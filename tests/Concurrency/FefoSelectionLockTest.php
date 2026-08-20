<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\IssueStock;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * LA SELECCIÓN FEFO TAMBIÉN NECESITA LOCK, Y NO ES EL MISMO
 *
 * El lock de {@see RecordStockMovement} protege **la aritmética de una fila**: que dos movimientos del mismo
 * `(almacén, artículo, lote)` no calculen el mismo `balance_after`. Eso no alcanza para FEFO.
 *
 * FEFO tiene un paso más: **decide de qué lote sacar**. Lee qué hay disponible y después escribe. Entre las dos
 * cosas, otro proceso puede agotar el mismo lote — y los dos habrían elegido el de marzo creyendo que alcanzaba.
 * El resultado no sería una aritmética mal hecha: sería un lote en negativo, que ordena primero en FEFO y
 * absorbería todas las salidas siguientes. El error se volvería permanente.
 *
 * Por eso {@see IssueStock} abre su propia transacción y bloquea **todas** las filas de saldo del artículo en el
 * almacén antes de decidir. Esta prueba comprueba que ese lock existe y que su alcance es el correcto.
 *
 * Vive en la suite `Concurrency` por lo mismo que la del kardex: `RefreshDatabase` envuelve cada prueba en una
 * transacción, y una transacción hace los datos invisibles para cualquier otra conexión.
 */

/** La conexión secundaria: la misma base, otra sesión de MySQL. */
const OTRA_CONEXION_FEFO = 'mysql_fefo';

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
    config([
        'database.connections.'.OTRA_CONEXION_FEFO => config('database.connections.'.config('database.default')),
    ]);
    DB::purge(OTRA_CONEXION_FEFO);

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda FEFO '.bin2hex(random_bytes(4)),
        ownerEmail: 'duena+'.bin2hex(random_bytes(4)).'@fonda.mx',
        ownerFirstName: 'Alma',
        ownerPaternalSurname: 'Robles',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $ml = Unit::query()->where('code', 'ml')->firstOrFail();

    $this->leche = Article::create([
        'name' => 'Leche concurrente',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    $this->otro = Article::create([
        'name' => 'Crema concurrente',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    $this->issues = app(IssueStock::class);
    $this->records = app(RecordStockMovement::class);
});

afterEach(function () {
    StockMovement::flushEventListeners();

    $tenantId = $this->tenant->id ?? null;

    if ($tenantId !== null) {
        // El orden importa: la proyección apunta al kardex, y el kardex a los lotes.
        DB::table('article_stocks')->where('tenant_id', $tenantId)->update(['last_movement_id' => null]);

        foreach (['article_stocks', 'stock_movements', 'article_lots', 'articles', 'units', 'warehouses',
            'branches', 'audit_entries', 'tenant_status_transitions', 'tenant_memberships'] as $tabla) {
            DB::table($tabla)->where('tenant_id', $tenantId)->delete();
        }

        DB::table('roles')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    app(TenantContext::class)->forget();
    DB::purge(OTRA_CONEXION_FEFO);
});

/** Crea un lote con existencia. */
function loteConcurrente(Article $article, string $code, string $expiresAt, string $quantity): ArticleLot
{
    $lot = ArticleLot::create([
        'article_id' => $article->id,
        'code' => $code,
        'expires_at' => $expiresAt,
        'received_at' => now()->subDay()->toDateString(),
    ]);

    test()->records->record(
        warehouse: test()->warehouse,
        article: $article,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: $quantity,
        lot: $lot,
    );

    return $lot;
}

/**
 * ¿Se queda esperando la otra conexión al intentar bloquear las filas de saldo de este artículo?
 */
function otraConexionEsperaElArticulo(int $warehouseId, int $articleId): bool
{
    return conOtraConexion(fn ($otra) => $otra->table('article_stocks')
        ->where('warehouse_id', $warehouseId)
        ->where('article_id', $articleId)
        ->lockForUpdate()
        ->get());
}

/**
 * ¿Y al intentar bloquear el saldo de UN lote concreto?
 *
 * Es la versión que discrimina: si el lote no participó en la salida, `RecordStockMovement` nunca tocó su fila.
 */
function otraConexionEsperaElLote(int $lotId): bool
{
    return conOtraConexion(fn ($otra) => $otra->table('article_stocks')
        ->where('lot_id', $lotId)
        ->lockForUpdate()
        ->get());
}

/**
 * Corre la consulta en la otra conexión con un segundo de paciencia.
 *
 * @return bool `true` si agotó el tiempo esperando un lock
 */
function conOtraConexion(Closure $query): bool
{
    $otra = DB::connection(OTRA_CONEXION_FEFO);
    $otra->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $query($otra);

        return false;
    } catch (QueryException $e) {
        // 1205: lock wait timeout. Alguien más tiene la fila tomada.
        return str_contains($e->getMessage(), '1205');
    }
}

it('la salida por FEFO bloquea TAMBIÉN los lotes que no usó', function () {
    // ESTA es la prueba discriminante, y la primera versión no lo era.
    //
    // Observaba las filas del artículo completo mientras se registraba un movimiento — pero en ese instante
    // `RecordStockMovement` ya tiene tomada la fila del lote que está escribiendo, así que la otra conexión se
    // bloqueaba **por el lock del registro** y no por el de FEFO. Pasaba en verde con el lock de `IssueStock`
    // borrado: no distinguía los dos locks, que es justo lo que tenía que distinguir.
    //
    // Se descubrió como siempre: quitando el arreglo para ver si la prueba falla. No falló.
    //
    // La versión correcta observa un lote que FEFO **decidió no usar**. `RecordStockMovement` nunca lo toca, así
    // que si su fila está bloqueada sólo puede ser por el lock previo de `IssueStock` — el que protege la
    // DECISIÓN de qué lote sacar.
    loteConcurrente($this->leche, 'L-MAR', now()->addMonth()->toDateString(), '300.0000');
    $sinUsar = loteConcurrente($this->leche, 'L-DIC', now()->addMonths(9)->toDateString(), '300.0000');

    $bloqueadoElNoUsado = null;

    StockMovement::created(function () use (&$bloqueadoElNoUsado, $sinUsar): void {
        $bloqueadoElNoUsado ??= otraConexionEsperaElLote($sinUsar->id);
    });

    // Sólo 100: cabe entero en el lote de marzo, así que el de diciembre no se toca.
    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '100.0000',
    );

    expect($movimientos)->toHaveCount(1, 'La salida debía caber en un solo lote para que el otro quede intacto.')
        ->and($movimientos[0]->lot_id)->not->toBe($sinUsar->id);

    expect($bloqueadoElNoUsado)->not->toBeNull('El observador no corrió: no se registró ningún movimiento.');

    expect($bloqueadoElNoUsado)->toBeTrue(
        'El saldo de un lote que FEFO no usó quedó libre mientras la salida ocurría. Eso significa que la '
        .'DECISIÓN de qué lote sacar no está protegida: dos salidas simultáneas leerían la misma '
        .'disponibilidad, elegirían el mismo lote y la segunda dejaría ese lote en negativo — ordenando '
        .'primero en FEFO y absorbiendo todas las salidas siguientes.'
    );
});

it('el lock de FEFO no alcanza a OTRO artículo', function () {
    // Serializar el almacén entero sería contención real: en hora pico salen decenas de insumos distintos.
    loteConcurrente($this->leche, 'L-MAR', now()->addMonth()->toDateString(), '500.0000');
    loteConcurrente($this->otro, 'C-MAR', now()->addMonth()->toDateString(), '500.0000');

    $otroBloqueado = null;

    StockMovement::created(function () use (&$otroBloqueado): void {
        $otroBloqueado ??= otraConexionEsperaElArticulo($this->warehouse->id, $this->otro->id);
    });

    $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '100.0000',
    );

    expect($otroBloqueado)->toBeFalse(
        'Sacar leche dejó bloqueados los saldos de la crema: el lock es demasiado ancho y serializaría el '
        .'almacén completo.'
    );
});

it('el reparto entre lotes queda consistente tras muchas salidas', function () {
    // Sin concurrencia, y detecta el error acumulado: la suma de lo que salió de cada lote tiene que ser
    // exactamente lo que se pidió, sin que ningún lote quede en negativo.
    loteConcurrente($this->leche, 'L-1', now()->addMonth()->toDateString(), '100.0000');
    loteConcurrente($this->leche, 'L-2', now()->addMonths(2)->toDateString(), '100.0000');
    loteConcurrente($this->leche, 'L-3', now()->addMonths(3)->toDateString(), '100.0000');

    // Doce salidas de 25 = 300, que es exactamente lo que hay.
    foreach (range(1, 12) as $i) {
        $this->issues->issue(
            warehouse: $this->warehouse,
            article: $this->leche,
            kind: StockMovementKind::ManualExit,
            quantity: '25.0000',
        );
    }

    $saldos = ArticleStock::query()
        ->where('article_id', $this->leche->id)
        ->get();

    // Los tres lotes en cero, y NINGUNA fila «sin lote»: nunca hizo falta cargar un faltante.
    foreach ($saldos as $saldo) {
        expect($saldo->quantity)->toBe(
            '0.0000',
            'Un lote quedó con saldo distinto de cero después de sacar exactamente todo lo que había.'
        );
    }

    expect($saldos)->toHaveCount(3, 'Apareció una fila de saldo que no corresponde a los tres lotes.');
});
