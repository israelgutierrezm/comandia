<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * EL LOCK DEL KARDEX, CON DOS CONEXIONES DE VERDAD
 *
 * ## Por qué esta prueba necesita una suite propia
 *
 * `balance_after` se congela en cada movimiento (P1), así que calcularlo exige leer el saldo, sumarle la
 * cantidad y escribir dos filas. **Entre leer y escribir, otro proceso puede hacer lo mismo**: los dos leerían
 * el mismo saldo de partida y escribirían el mismo `balance_after`. El kardex quedaría con dos filas afirmando
 * que el saldo es 30 cuando es 40, y la proyección con una de las dos, al azar.
 *
 * Y no es un caso de laboratorio: es exactamente lo que hace un punto de venta con dos cajas cobrando lo
 * mismo a la vez.
 *
 * El problema para probarlo es que `RefreshDatabase` envuelve cada prueba en una transacción, y una
 * transacción hace los datos **invisibles** para cualquier otra conexión. La herramienta que aísla las pruebas
 * es justo la que impide verificar un lock entre conexiones. De ahí esta suite, que hace COMMIT de verdad.
 *
 * ## La primera versión de estas pruebas era FALSA, y conviene que quede escrito
 *
 * Tomaban el lock **ellas mismas** desde la conexión A —`DB::table(...)->lockForUpdate()`— y después
 * comprobaban que la conexión B se quedaba esperando. Eso pasaba en verde con el `lockForUpdate` del servicio
 * **borrado**: lo único que probaban era que MySQL bloquea filas, que no es algo que este código pueda
 * equivocarse.
 *
 * Se descubrió al hacer lo de siempre: quitar el arreglo a propósito para ver si la prueba falla. No falló.
 *
 * La versión correcta se cuelga del evento `created` del movimiento, que Eloquent dispara **dentro** de la
 * transacción del servicio: en ese instante el lock del servicio está tomado, y desde la otra conexión se
 * puede comprobar. Es la única forma de observar un lock que dura milisegundos.
 */

/** La conexión secundaria: la misma base, otra sesión de MySQL. */
const OTRA_CONEXION = 'mysql_concurrency';

/**
 * ## Por qué esta suite NO corre en paralelo
 *
 * Abre una SEGUNDA conexión a mano para que dos transacciones pelen por la misma fila. Esa conexión apunta a la base
 * de la configuración, no a la que `--parallel` asigna a cada proceso, así que en paralelo pelearía contra los datos de
 * otro proceso y produciría deadlocks que no dicen nada sobre el código.
 *
 * El grupo `serial` la saca de la corrida paralela; el script `test` de `composer.json` la corre después, en serie.
 */
uses()->group('serial');

beforeEach(function () {
    // Se clona la configuración de la conexión por omisión. No es otra base: es otra SESIÓN, que es lo que
    // hace falta para que haya dos transacciones peleando.
    config(['database.connections.'.OTRA_CONEXION => config('database.connections.'.config('database.default'))]);
    DB::purge(OTRA_CONEXION);

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda Concurrente '.bin2hex(random_bytes(4)),
        ownerEmail: 'duena+'.bin2hex(random_bytes(4)).'@fonda.mx',
        ownerFirstName: 'Irene',
        ownerPaternalSurname: 'Bustos',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate concurrente',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
    ]);

    $this->queso = Article::create([
        'name' => 'Queso concurrente',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
    ]);

    $this->service = app(RecordStockMovement::class);
});

afterEach(function () {
    // Los listeners de modelo son estáticos: sin limpiarlos, el de una prueba corre en la siguiente.
    StockMovement::flushEventListeners();

    // Limpieza a mano, en orden inverso a las dependencias: sin `RefreshDatabase` nadie lo hace por nosotros,
    // y una prueba de concurrencia que deja basura contamina a la siguiente.
    $tenantId = $this->tenant->id ?? null;

    if ($tenantId !== null) {
        foreach (['article_stocks', 'stock_movements', 'articles', 'units', 'warehouses', 'branches',
            'audit_entries', 'tenant_status_transitions', 'tenant_memberships'] as $tabla) {
            DB::table($tabla)->where('tenant_id', $tenantId)->delete();
        }

        DB::table('roles')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    app(TenantContext::class)->forget();
    DB::purge(OTRA_CONEXION);
});

/**
 * Intenta bloquear una fila de saldo desde la OTRA conexión, con un segundo de paciencia.
 *
 * @return bool `true` si se quedó esperando y agotó el tiempo — o sea, si alguien más la tenía tomada
 */
function otraConexionSeQuedaEsperando(int $stockId): bool
{
    $otra = DB::connection(OTRA_CONEXION);
    $otra->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $otra->table('article_stocks')->where('id', $stockId)->lockForUpdate()->first();

        return false;
    } catch (QueryException $e) {
        // 1205: lock wait timeout exceeded. Es la señal de que la fila estaba tomada.
        return str_contains($e->getMessage(), '1205');
    }
}

it('el SERVICIO bloquea la fila del saldo mientras registra', function () {
    // Un primer movimiento para que la fila de saldo exista.
    $this->service->record(
        warehouse: $this->warehouse,
        article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: '1000.0000',
    );

    $stockId = ArticleStock::query()->where('article_id', $this->jitomate->id)->value('id');

    // El observador se cuelga del evento `created`, que Eloquent dispara DENTRO de la transacción del
    // servicio y después de que éste tomó el lock. Es el único instante en el que se puede mirar.
    $bloqueada = null;

    StockMovement::created(function () use ($stockId, &$bloqueada): void {
        $bloqueada = otraConexionSeQuedaEsperando($stockId);
    });

    // Segundo movimiento: es el que se observa.
    $this->service->record(
        warehouse: $this->warehouse,
        article: $this->jitomate,
        kind: StockMovementKind::Waste,
        quantity: '100.0000',
    );

    expect($bloqueada)->not->toBeNull('El observador no corrió: el evento `created` no se disparó.');

    expect($bloqueada)->toBeTrue(
        'La otra conexión pudo bloquear la fila del saldo MIENTRAS el servicio registraba. O sea que el '
        .'servicio no la tiene tomada, y dos movimientos simultáneos del mismo artículo pueden leer el mismo '
        .'saldo de partida y congelar el mismo `balance_after`.'
    );

    // Y el resultado quedó bien, que es lo que el lock protege.
    expect(ArticleStock::query()->where('article_id', $this->jitomate->id)->value('quantity'))
        ->toBe('900.0000');
});

it('el lock del servicio NO alcanza a otro artículo del mismo almacén', function () {
    // La otra mitad de la decisión: serializar el almacén entero sí sería contención real. El lock es de la
    // fila `(tenant, almacén, artículo, lote)`.
    foreach ([$this->jitomate, $this->queso] as $articulo) {
        $this->service->record(
            warehouse: $this->warehouse, article: $articulo,
            kind: StockMovementKind::PurchaseReceipt, quantity: '500.0000',
        );
    }

    $idQueso = ArticleStock::query()->where('article_id', $this->queso->id)->value('id');

    $quesoBloqueado = null;

    // Mientras el servicio registra un movimiento de JITOMATE, se intenta bloquear la fila del QUESO.
    StockMovement::created(function () use ($idQueso, &$quesoBloqueado): void {
        $quesoBloqueado = otraConexionSeQuedaEsperando($idQueso);
    });

    $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::Waste, quantity: '10.0000',
    );

    expect($quesoBloqueado)->toBeFalse(
        'Registrar un movimiento de jitomate dejó bloqueada la fila del queso: el lock es demasiado ancho y '
        .'serializaría el almacén entero.'
    );
});

it('la cadena de saldos queda consistente tras muchos movimientos', function () {
    // Sin concurrencia, y es la comprobación que detecta un error de cálculo acumulado: cada `balance_after`
    // tiene que ser el anterior más el movimiento. Un error de un decimal se ve aquí y no en un movimiento
    // suelto.
    $esperado = '0.0000';

    foreach (range(1, 25) as $i) {
        $entrada = $i % 3 !== 0;

        $this->service->record(
            warehouse: $this->warehouse,
            article: $this->jitomate,
            kind: $entrada ? StockMovementKind::PurchaseReceipt : StockMovementKind::Waste,
            quantity: '33.3333',
        );

        $esperado = bcadd($esperado, $entrada ? '33.3333' : '-33.3333', 4);
    }

    $movimientos = StockMovement::query()
        ->where('article_id', $this->jitomate->id)
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(25);

    $acumulado = '0.0000';

    foreach ($movimientos as $movimiento) {
        $acumulado = bcadd($acumulado, $movimiento->signedQuantity(), 4);

        expect($movimiento->balance_after)->toBe(
            $acumulado,
            "El movimiento #{$movimiento->id} congeló un saldo que no es el acumulado hasta él."
        );
    }

    expect($acumulado)->toBe($esperado)
        ->and(ArticleStock::query()->where('article_id', $this->jitomate->id)->value('quantity'))
        ->toBe($esperado);
});
