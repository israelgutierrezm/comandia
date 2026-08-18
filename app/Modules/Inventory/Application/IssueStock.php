<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Da SALIDA a existencia, eligiendo lotes por FEFO cuando el artículo los lleva (D23).
 *
 * FEFO = *First Expired, First Out*: primero lo que caduca. §6.2 lo pide **automático y sin selección manual
 * obligatoria**, y esa palabra —automático— es la que da forma a este servicio: quien registra una salida dice
 * cuánto sale, no de dónde.
 *
 * ## Una salida puede ser VARIOS movimientos
 *
 * Si el lote más próximo a caducar no alcanza, la salida se parte: 300 ml del lote que vence en marzo y 200 del
 * de abril son **dos** renglones del kardex, no uno. Es lo correcto y no un detalle: cada renglón dice de qué
 * partida física salió, que es exactamente lo que se necesita cuando hay que rastrear un lote defectuoso.
 *
 * Por eso el servicio devuelve una lista y no un movimiento.
 *
 * ## Los que NO caducan salen AL FINAL
 *
 * Es la parte que no se adivina: en MySQL los `NULL` ordenan **primero**, así que un ordenamiento ingenuo por
 * `expires_at` sacaría antes la sal —que no caduca— que la leche que vence el jueves. Justo lo contrario de lo
 * que FEFO quiere.
 *
 * ## Lo que sobra cuando no hay lotes suficientes va SIN LOTE
 *
 * Las existencias negativas están permitidas (§6.2), así que una salida mayor que lo disponible tiene que
 * proceder. La pregunta es a qué lote se le carga el faltante, y la respuesta es: a ninguno.
 *
 * Atribuirlo al último lote usado dejaría **ese** lote en negativo, y un lote negativo ordena primero en FEFO —
 * absorbería todas las salidas siguientes y el error se volvería permanente. Cargarlo a una fila «sin lote»
 * concentra el descuadre en un sitio visible, deja los saldos por lote diciendo la verdad —nunca bajan de
 * cero— y es justo lo que el próximo conteo tiene que revisar.
 *
 * ## Por qué el lock abarca TODOS los lotes del artículo
 *
 * La selección lee qué hay disponible y después escribe. Entre las dos cosas, otro proceso puede agotar el
 * mismo lote: los dos habrían elegido el lote de marzo creyendo que alcanzaba. El lock de
 * {@see RecordStockMovement} protege la aritmética de **una** fila, no la decisión de cuál usar.
 *
 * Así que aquí se bloquean por adelantado todas las filas de saldo del `(almacén, artículo)`, y la transacción
 * externa las mantiene tomadas mientras se registran los movimientos. Serializa las salidas de ese artículo en
 * ese almacén, que es exactamente el alcance que hace falta: otro artículo no espera.
 */
final class IssueStock
{
    public function __construct(private readonly RecordStockMovement $movements) {}

    /**
     * @param  numeric-string  $quantity  total a sacar, en la unidad base del artículo
     * @param  ArticleLot|null  $lot  lote explícito: si viene, NO se aplica FEFO
     * @return list<StockMovement>
     */
    public function issue(
        Warehouse $warehouse,
        Article $article,
        StockMovementKind $kind,
        string $quantity,
        ?ArticleLot $lot = null,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $notes = null,
        ?int $actorMembershipId = null,
    ): array {
        // Un lote explícito gana a FEFO: quien lo indica está mirando la caja física, y el sistema no tiene
        // mejor información que eso. Y un artículo sin lotes no tiene nada que elegir.
        if ($lot !== null || ! $article->tracksLots()) {
            return [$this->movements->record(
                warehouse: $warehouse,
                article: $article,
                kind: $kind,
                quantity: $quantity,
                lot: $lot,
                source: $source,
                idempotencyKey: $idempotencyKey,
                occurredAt: $occurredAt,
                notes: $notes,
                actorMembershipId: $actorMembershipId,
            )];
        }

        return DB::transaction(function () use (
            $warehouse, $article, $kind, $quantity, $source, $idempotencyKey, $occurredAt, $notes,
            $actorMembershipId,
        ): array {
            // Se bloquean TODAS las filas de saldo del artículo en el almacén antes de decidir. Sin esto, dos
            // salidas simultáneas eligen el mismo lote creyendo que alcanzaba.
            $this->lockStockRows($warehouse, $article);

            $plan = $this->planFefo($warehouse, $article, $quantity);

            $movements = [];

            foreach ($plan as $index => [$planLot, $planQuantity]) {
                $movements[] = $this->movements->record(
                    warehouse: $warehouse,
                    article: $article,
                    kind: $kind,
                    quantity: $planQuantity,
                    lot: $planLot,
                    source: $source,

                    // La llave de idempotencia se sufija con el índice: la salida puede ser N movimientos, y
                    // sin sufijo el segundo choparía con el primero y se descartaría en silencio — dejando la
                    // salida a medias con el saldo mal.
                    idempotencyKey: $idempotencyKey === null ? null : $idempotencyKey.':'.$index,

                    occurredAt: $occurredAt,
                    notes: $notes,
                    actorMembershipId: $actorMembershipId,
                );
            }

            return $movements;
        });
    }

    /**
     * Bloquea las filas de saldo del artículo en el almacén, incluida la de «sin lote».
     *
     * `lockForUpdate` sobre filas que quizá no existan no bloquea nada —no hay fila que tomar— y eso es
     * aceptable: el faltante se carga a la fila «sin lote», que {@see RecordStockMovement} crea con su propio
     * lock si hace falta.
     */
    private function lockStockRows(Warehouse $warehouse, Article $article): void
    {
        ArticleStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('article_id', $article->id)
            ->lockForUpdate()
            ->get();
    }

    /**
     * El plan de salida: qué lote y cuánto de cada uno.
     *
     * @param  numeric-string  $quantity
     * @return list<array{0: ArticleLot|null, 1: numeric-string}>
     */
    private function planFefo(Warehouse $warehouse, Article $article, string $quantity): array
    {
        $pending = $quantity;
        $plan = [];

        foreach ($this->availableLots($warehouse, $article) as [$lot, $available]) {
            if (bccomp($pending, '0', 4) !== 1) {
                break;
            }

            // De este lote sale lo que quede pendiente, o todo lo que tenga si no alcanza.
            $take = bccomp($available, $pending, 4) === -1 ? $available : $pending;

            $plan[] = [$lot, $take];
            $pending = bcsub($pending, $take, 4);
        }

        // Lo que sobró no tiene lote al que cargarse. Va a la fila «sin lote», que deja el descuadre en un
        // sitio visible en lugar de meter un lote en negativo.
        if (bccomp($pending, '0', 4) === 1) {
            $plan[] = [null, $pending];
        }

        return $plan;
    }

    /**
     * Los lotes con saldo positivo, en orden FEFO, con cuánto tiene cada uno.
     *
     * Se unen `article_lots` y `article_stocks` porque hacen falta los dos: el orden lo da la caducidad del
     * lote y la disponibilidad la da el saldo en ESTE almacén. Un lote puede existir con saldo cero aquí y
     * saldo en otra sucursal.
     *
     * @return list<array{0: ArticleLot, 1: numeric-string}>
     */
    private function availableLots(Warehouse $warehouse, Article $article): array
    {
        $stocks = ArticleStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('article_id', $article->id)
            ->whereNotNull('lot_id')
            ->where('quantity', '>', 0)
            ->with('lot')
            ->get()
            ->filter(fn (ArticleStock $stock): bool => $stock->lot?->status === LotStatus::Active);

        // El orden FEFO se aplica en PHP y no en SQL a propósito: la consulta ya trajo las pocas filas de saldo
        // de este artículo en este almacén —son tantas como lotes con existencia, o sea unas cuantas— y
        // ordenar aquí evita un `join` con `ORDER BY` sobre dos tablas grandes.
        //
        // Los que no caducan van AL FINAL. En MySQL los `NULL` ordenarían primero, y en PHP `null` compararía
        // como menor: en los dos casos la sal saldría antes que la leche del jueves.
        $sorted = $stocks->sortBy([
            fn (ArticleStock $a, ArticleStock $b): int => ($a->lot?->expires_at === null ? 1 : 0)
                <=> ($b->lot?->expires_at === null ? 1 : 0),
            fn (ArticleStock $a, ArticleStock $b): int => ($a->lot?->expires_at?->timestamp ?? 0)
                <=> ($b->lot?->expires_at?->timestamp ?? 0),
            // Desempate estable por si dos lotes caducan el mismo día: sin él, el orden dependería de cómo
            // MySQL devolvió las filas y dos salidas iguales podrían partirse distinto.
            fn (ArticleStock $a, ArticleStock $b): int => $a->lot_id <=> $b->lot_id,
        ]);

        return $sorted
            ->map(fn (ArticleStock $stock): array => [$stock->lot, $stock->quantity])
            ->values()
            ->all();
    }

    /**
     * La dirección que este servicio impone. Sólo da SALIDA: para entradas no hay nada que elegir — el lote
     * llega con la mercancía.
     */
    public function direction(): StockMovementDirection
    {
        return StockMovementDirection::Out;
    }
}
