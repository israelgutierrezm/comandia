<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Events\ArticleCostChanged;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registra una variación de costo y mantiene la proyección del costo vigente.
 *
 * Es el único camino por el que se escribe un costo. Que sea único es lo que permite afirmar las tres
 * condiciones que P4 puso como parte de la decisión: historial y proyección en la misma transacción,
 * comando de reconstrucción, y prueba de que no divergen.
 *
 * ## Captura por presentación: la forma en que un humano piensa el costo
 *
 * "Compré un costal de 25 kg en $600" es lo que dice el dueño; $24 por kilo es lo que el sistema
 * necesita. Pedirle que divida a mano es pedirle el cálculo donde se equivoca, y un costo unitario
 * mal capturado contamina el costeo de todo lo que use ese insumo (D22).
 */
final readonly class CaptureArticleCost
{
    /**
     * Captura directa: ya se conoce el costo por unidad base.
     *
     * @param  numeric-string  $unitCost
     */
    public function atUnitCost(
        Article $article,
        string $unitCost,
        CostOrigin $origin = CostOrigin::Manual,
        ?CarbonImmutable $effectiveAt = null,
        ?string $notes = null,
        ?int $actorMembershipId = null,
        ?string $idempotencyKey = null,
        ?int $sourceCostId = null,
    ): ArticleCost {
        try {
            return $this->write(
                $article, $unitCost, $origin, $effectiveAt, $notes,
                $actorMembershipId, $idempotencyKey, $sourceCostId,
            );
        } catch (QueryException $e) {
            // Violación del índice único de idempotencia: este costo ya se capturó. Es el caso NORMAL de un evento
            // re-despachado —una recepción cuyo oyente falló y se reintentó— y no un error, así que se devuelve el que
            // ya existe.
            //
            // **Esto SUSTITUYE una decisión de la Iteración 2** (D212), y no fue un descuido de entonces: su prueba
            // exigía a propósito que el segundo intento lanzara, con el argumento de que «el índice único lo hace
            // imposible aunque el código se equivoque». El argumento es bueno y la garantía sigue intacta — el índice no
            // se toca, y hay una prueba que lo comprueba desde un segundo camino.
            //
            // Lo que se corrige es el CONTRATO del servicio: una llave de idempotencia significa que aplicar dos veces
            // tenga el efecto de aplicar una, así que lanzar obliga a cada llamador a atrapar la excepción y a reconocer
            // códigos de MySQL para distinguir un reintento normal de un fallo real. Lo destapó el paso 9: el kardex lo
            // soportaba y el costo no, y dos mecanismos de idempotencia que se comportan distinto son una trampa para
            // quien escriba el tercero.
            //
            // Se traga SÓLO cuando el llamador puso llave: sin ella, una violación de unicidad sería cualquier otra
            // cosa y esconderla dejaría un fallo real sin diagnosticar. Es el mismo trato que en `RecordStockMovement`,
            // y ahora los dos mecanismos de idempotencia se comportan igual.
            if ($idempotencyKey !== null && $this->isDuplicateKey($e)) {
                return ArticleCost::query()->where('idempotency_key', $idempotencyKey)->sole();
            }

            throw $e;
        }
    }

    /**
     * @param  numeric-string  $unitCost
     */
    private function write(
        Article $article,
        string $unitCost,
        CostOrigin $origin,
        ?CarbonImmutable $effectiveAt,
        ?string $notes,
        ?int $actorMembershipId,
        ?string $idempotencyKey,
        ?int $sourceCostId,
    ): ArticleCost {
        return DB::transaction(function () use (
            $article, $unitCost, $origin, $effectiveAt, $notes, $actorMembershipId,
            $idempotencyKey, $sourceCostId,
        ): ArticleCost {
            $cost = ArticleCost::create([
                'article_id' => $article->id,
                'unit_cost' => Decimal::round($unitCost, 4),
                'origin' => $origin,
                'source_cost_id' => $sourceCostId,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'actor_membership_id' => $actorMembershipId,
                'effective_at' => $effectiveAt ?? CarbonImmutable::now(),
            ]);

            $becameCurrent = $this->refreshProjection($article, $cost);

            ArticleCostChanged::dispatch($cost, $becameCurrent);

            return $cost;
        });
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // 23000 es la clase SQLSTATE de violación de integridad; 1062 es el código de MySQL para duplicado.
        // Escrito igual que en `RecordStockMovement`: los dos mecanismos de idempotencia tienen que reconocer el
        // mismo caso con el mismo criterio, o uno de los dos tragará lo que el otro lanza.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), '1062');
    }

    /**
     * Captura por presentación de compra: se divide el total entre lo que rinde la presentación.
     *
     * @param  numeric-string  $totalCost  lo que se pagó por UNA presentación
     */
    public function fromPresentation(
        ArticlePurchasePresentation $presentation,
        string $totalCost,
        CostOrigin $origin = CostOrigin::Manual,
        ?CarbonImmutable $effectiveAt = null,
        ?string $notes = null,
        ?int $actorMembershipId = null,
        ?string $idempotencyKey = null,
    ): ArticleCost {
        $article = $presentation->article;

        // El divisor no puede ser cero: hay un CHECK en la tabla que lo garantiza, así que si
        // llegara un cero aquí sería un dato imposible y preferimos que reviente a que produzca un
        // costo infinito en silencio.
        // `Decimal::divide` redondea media-arriba con dígitos de guarda; `bcdiv` a secas truncaría, y
        // truncar sesga todos los costos hacia abajo.
        $unitCost = Decimal::divide($totalCost, $presentation->quantity_in_base_unit, 8);

        return $this->atUnitCost(
            article: $article,
            unitCost: $unitCost,
            origin: $origin,
            effectiveAt: $effectiveAt,
            notes: $notes ?? sprintf('%s a %s por presentación', $presentation->name, $totalCost),
            actorMembershipId: $actorMembershipId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Pone la proyección al día, **sólo si la fila nueva es de verdad la vigente**.
     *
     * Una captura retroactiva —la factura de la semana pasada que se registra hoy— NO debe pisar el
     * costo vigente. Sin esta comprobación, capturar un costo viejo dejaría al sistema costeando con
     * él, y el error sería invisible: la proyección tendría un valor perfectamente plausible.
     *
     * @return bool si la fila nueva quedó como la vigente
     */
    private function refreshProjection(Article $article, ArticleCost $cost): bool
    {
        $current = ArticleCost::currentFor($article->id);

        if ($current === null || $current->id !== $cost->id) {
            return false;
        }

        // `updateOrCreate` y no `create`: hay a lo más una fila por artículo, y el índice único lo
        // garantiza. `tenant_id` no se pasa: lo pone el trait BelongsToTenant, y pasarlo haría que
        // el guardián del modelo de dominio lo rechazara.
        ArticleCurrentCost::query()->updateOrCreate(
            ['article_id' => $article->id],
            [
                'unit_cost' => $cost->unit_cost,
                'effective_at' => $cost->effective_at,
                'source_cost_id' => $cost->id,
            ],
        );

        return true;
    }
}
