<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Exceptions\StockMovementInvariantException;
use App\Modules\Inventory\Events\StockMovementRecorded;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registra un movimiento de inventario y actualiza el saldo. **La única puerta de entrada al kardex.**
 *
 * Todo lo que mueva existencia —una recepción, una merma, un ajuste, el descuento por venta— pasa por aquí.
 * No es una preferencia de estilo: es lo que garantiza que `balance_after` sea correcto y que la proyección no
 * se desvíe. Un segundo camino que escribiera `stock_movements` directamente rompería las dos cosas sin fallar.
 *
 * ## Por qué hay un lock pesimista
 *
 * `balance_after` se congela en el movimiento (P1), así que calcularlo exige leer el saldo actual, sumarle la
 * cantidad y escribir las dos filas. Entre leer y escribir, **otro proceso puede hacer lo mismo**: dos
 * movimientos concurrentes del mismo artículo en el mismo almacén leerían el mismo saldo de partida y
 * escribirían el mismo `balance_after`. El kardex quedaría con dos filas que dicen que el saldo es 30 cuando
 * en realidad es 40, y la proyección con una de las dos — al azar.
 *
 * Y no es un caso raro: es exactamente lo que hace un POS con dos cajas cobrando lo mismo a la vez.
 *
 * El lock es `SELECT ... FOR UPDATE` sobre la fila de `article_stocks`, que existe por el índice único de
 * `(tenant, almacén, artículo, lot_key)`. Serializa **sólo** los movimientos de esa combinación: dos
 * artículos distintos no se esperan.
 *
 * ## Por qué el saldo se lee de la PROYECCIÓN y no del último movimiento
 *
 * Porque la fila de la proyección es la que se puede bloquear. `stock_movements` es append-only: no hay una
 * fila estable sobre la que tomar el lock, y bloquear «el último movimiento» es una carrera en sí misma —
 * entre leer cuál es el último y bloquearlo, otro pudo insertar uno.
 *
 * La proyección se puede reconstruir del kardex, así que usarla como punto de sincronización no la convierte
 * en la verdad: sigue siendo derivada.
 */
final class RecordStockMovement
{
    public function __construct(private readonly ContextHolder $context) {}

    /**
     * @param  numeric-string  $quantity  SIEMPRE positiva; la dirección la decide el tipo
     * @param  numeric-string|null  $unitCost  costo con el que se mueve; `null` si no se conoce
     * @param  Model|null  $source  el documento que causó el movimiento
     * @param  StockMovementDirection|null  $direction  sólo para los tipos que admiten las dos
     *
     * @throws StockMovementInvariantException
     */
    public function record(
        Warehouse $warehouse,
        Article $article,
        StockMovementKind $kind,
        string $quantity,
        ?StockMovementDirection $direction = null,
        ?ArticleLot $lot = null,
        ?string $unitCost = null,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $notes = null,
        ?int $actorMembershipId = null,
    ): StockMovement {
        $direction = $this->resolveDirection($kind, $direction);

        $this->assertQuantityIsPositive($quantity);
        $this->assertLotBelongsToArticle($lot, $article);

        // El actor sale del contexto salvo que se pase explícito. Un job no tiene contexto de persona y
        // registra `null`, que es correcto: no se inventa un actor.
        $actorMembershipId ??= $this->context->getOrNull()?->membership?->id;

        try {
            $movement = DB::transaction(fn (): StockMovement => $this->write(
                warehouse: $warehouse,
                article: $article,
                kind: $kind,
                direction: $direction,
                quantity: $quantity,
                lot: $lot,
                unitCost: $unitCost,
                source: $source,
                idempotencyKey: $idempotencyKey,
                occurredAt: $occurredAt ?? CarbonImmutable::now(),
                notes: $notes,
                actorMembershipId: $actorMembershipId,
            ));
        } catch (QueryException $e) {
            // Violación del índice único de idempotencia: el movimiento ya se registró. Es el caso NORMAL de
            // un job re-despachado, no un error, y se devuelve el que ya existe.
            //
            // Sólo se traga cuando el llamador PUSO una llave: sin ella, una violación de unicidad sería
            // cualquier otra cosa y esconderla dejaría un fallo real sin diagnosticar.
            if ($idempotencyKey !== null && $this->isDuplicateKey($e)) {
                return StockMovement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->sole();
            }

            throw $e;
        }

        // Fuera de la transacción: quien escuche no debe poder abortar la escritura del kardex, y un listener
        // lento no tiene por qué mantener abierto el lock del saldo.
        StockMovementRecorded::dispatch($movement);

        return $movement;
    }

    /**
     * La escritura, ya dentro de la transacción.
     */
    private function write(
        Warehouse $warehouse,
        Article $article,
        StockMovementKind $kind,
        StockMovementDirection $direction,
        string $quantity,
        ?ArticleLot $lot,
        ?string $unitCost,
        ?Model $source,
        ?string $idempotencyKey,
        CarbonImmutable $occurredAt,
        ?string $notes,
        ?int $actorMembershipId,
    ): StockMovement {
        $stock = $this->lockedStock($warehouse, $article, $lot);

        // El saldo nuevo: `bcmath` y nunca punto flotante. Cuatro decimales, la escala de la columna.
        $balanceAfter = Decimal::round(
            bcadd($stock->quantity, bcmul($quantity, (string) $direction->sign(), 4), 4),
            4,
        );

        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'article_id' => $article->id,
            'lot_id' => $lot?->id,
            'kind' => $kind,
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,

            // El importe congelado. Se guarda en lugar de calcularse al leer porque es lo que suman los
            // reportes de valor de merma y de compras, y dos redondeos distintos del mismo número es de donde
            // salen los descuadres de un peso.
            'total_cost' => $unitCost === null ? null : Decimal::round(bcmul($quantity, $unitCost, 6), 2),

            'balance_after' => $balanceAfter,

            'source_type' => $source === null ? null : $source::class,
            'source_id' => $source?->getKey(),

            // El identificador PÚBLICO del documento, congelado (D151). La llave interna deja de significar
            // algo si el documento desaparece, y no se puede exponer por la API.
            'source_ulid' => is_string($ulid = $source?->getAttribute('ulid')) ? $ulid : null,

            'idempotency_key' => $idempotencyKey,
            'actor_membership_id' => $actorMembershipId,
            'notes' => $notes,
            'occurred_at' => $occurredAt,
        ]);

        $stock->update([
            'quantity' => $balanceAfter,
            'last_movement_id' => $movement->id,
        ]);

        return $movement;
    }

    /**
     * La fila de saldo, BLOQUEADA para el resto de la transacción.
     *
     * Si no existe se crea y **se vuelve a leer con lock**, en lugar de usar la recién creada. La diferencia
     * importa: `firstOrCreate` no bloquea nada, así que dos procesos pueden crearla a la vez —uno gana, el
     * otro recibe la violación de unicidad— y el que gana seguiría con una fila sin lock. Releer con
     * `lockForUpdate` es lo que garantiza que los dos acaben serializados.
     */
    private function lockedStock(Warehouse $warehouse, Article $article, ?ArticleLot $lot): ArticleStock
    {
        $criteria = [
            'warehouse_id' => $warehouse->id,
            'article_id' => $article->id,
            'lot_id' => $lot?->id,
        ];

        $stock = ArticleStock::query()->where($criteria)->lockForUpdate()->first();

        if ($stock !== null) {
            return $stock;
        }

        try {
            ArticleStock::create([...$criteria, 'quantity' => '0.0000']);
        } catch (QueryException $e) {
            // Otro proceso la creó primero. No es un error: se sigue adelante y se lee la suya.
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        return ArticleStock::query()->where($criteria)->lockForUpdate()->sole();
    }

    /**
     * La dirección del movimiento: la del tipo, o la que pase el llamador si el tipo admite las dos.
     *
     * @throws StockMovementInvariantException
     */
    private function resolveDirection(
        StockMovementKind $kind,
        ?StockMovementDirection $direction,
    ): StockMovementDirection {
        $fixed = $kind->fixedDirection();

        if ($fixed !== null) {
            // Se pasó una dirección que contradice al tipo: es una merma que suma, o una recepción que
            // resta. Se RECHAZA en lugar de ignorarse, porque quien la pasó cree que va a ocurrir.
            if ($direction !== null && $direction !== $fixed) {
                throw StockMovementInvariantException::directionContradictsKind($kind, $direction);
            }

            return $fixed;
        }

        // Los dos ajustes exigen dirección explícita: ahí el signo ES la información y no hay valor por
        // omisión razonable. Elegir uno haría que un ajuste sin dirección restara —o sumara— en silencio.
        return $direction ?? throw StockMovementInvariantException::directionRequired($kind);
    }

    /**
     * @throws StockMovementInvariantException
     */
    private function assertQuantityIsPositive(string $quantity): void
    {
        if (bccomp($quantity, '0', 4) !== 1) {
            throw StockMovementInvariantException::quantityMustBePositive($quantity);
        }
    }

    /**
     * @throws StockMovementInvariantException
     */
    private function assertLotBelongsToArticle(?ArticleLot $lot, Article $article): void
    {
        if ($lot !== null && $lot->article_id !== $article->id) {
            // Un lote de otro artículo mezclaría dos existencias distintas bajo el mismo saldo, y el
            // movimiento no fallaría en ningún sitio: la FK está satisfecha porque el lote existe.
            throw StockMovementInvariantException::lotBelongsToAnotherArticle($lot, $article);
        }
    }

    /** ¿Esta excepción de base de datos es una violación de índice único? */
    private function isDuplicateKey(QueryException $e): bool
    {
        // 23000 es la clase SQLSTATE de violación de integridad; 1062 es el código de MySQL para duplicado.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), '1062');
    }
}
