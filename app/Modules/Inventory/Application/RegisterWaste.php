<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Exceptions\WasteRequiresAuthorizationException;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registra una merma: salida tipificada con motivo, umbral y autorización (D27, §6.2).
 *
 * ## Las cuatro reglas de §6.2, y por qué cada una está donde está
 *
 * 1. **Catálogo de motivos por tenant.** El motivo es obligatorio y tiene que estar activo. Una merma sin motivo
 *    es una salida que nadie puede explicar, y el reporte que D27 hace posible se quedaría sin agrupador.
 * 2. **Permiso específico** — `inventory.waste.create`, que la ruta exige.
 * 3. **Umbral de monto con autorización superior.** Aquí, porque el monto no se conoce hasta valuar la merma: se
 *    necesita el costo vigente y la cantidad, y eso lo sabe el servicio y no la ruta.
 * 4. **Evidencia fotográfica opcional** — diferida con su razón (P5): no hay almacenamiento de archivos. La
 *    política `requires_evidence` del motivo sí existe y viaja en la respuesta para que la UI advierta.
 *
 * ## El umbral se evalúa sobre el VALOR, no sobre la cantidad
 *
 * Cien gramos de azafrán y cien kilos de sal no son la misma pérdida. El umbral está en pesos porque lo que el
 * negocio quiere controlar es cuánto dinero se va, y por eso hace falta valuar antes de decidir si se autoriza.
 *
 * Consecuencia que hay que decir: **un artículo sin costo capturado no puede cruzar el umbral**, porque su merma
 * no vale nada calculable. Se registra sin autorización. Es la alternativa correcta a inventarle un costo: un cero
 * diría que la mercancía es gratis, y bloquear la merma por falta de costo dejaría al almacén sin poder operar por
 * un dato que le falta a otro módulo.
 *
 * ## Quien registra no puede autorizarse
 *
 * `inventory.waste.authorize_above_threshold` NO lo tiene el almacenista (D161): si quien registra pudiera
 * autorizar, el umbral no defendería nada. Y la autorización pasa por PIN (ADR-008) con la **unión de roles** del
 * autorizador, no por su rol activo — porque el autorizador no está operando el sistema en ese momento: está
 * poniendo su PIN en la terminal de otra persona.
 */
final class RegisterWaste
{
    public function __construct(
        private readonly IssueStock $issues,
        private readonly ResolveArticleCost $costs,
        private readonly PinAuthorizationService $pin,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  numeric-string  $quantity
     * @param  string|null  $authorizationToken  concesión de PIN, obligatoria si la merma pasa el umbral
     * @return list<StockMovement>
     *
     * @throws WasteRequiresAuthorizationException
     */
    public function register(
        Warehouse $warehouse,
        Article $article,
        WasteReason $reason,
        string $quantity,
        ?ArticleLot $lot = null,
        ?string $authorizationToken = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $notes = null,
    ): array {
        $estimatedValue = $this->estimateValue($article, $quantity);
        $threshold = $this->threshold($warehouse);

        $authorizer = $this->resolveAuthorizer($estimatedValue, $threshold, $authorizationToken);

        // La transacción cubre el kardex Y el motivo. Tienen que ser atómicos: una merma registrada sin motivo es
        // precisamente lo que §6.2 prohíbe, y si `stampReason()` quedara fuera, cualquier fallo entre las dos
        // escrituras dejaría en el kardex —que es inmutable— una salida que nadie puede explicar ni corregir.
        $movements = DB::transaction(function () use ($warehouse, $article, $quantity, $lot, $occurredAt, $notes, $reason): array {
            $movements = $this->issues->issue(
                warehouse: $warehouse,
                article: $article,
                kind: StockMovementKind::Waste,
                quantity: $quantity,
                lot: $lot,

                // El motivo NO viaja por `IssueStock`: ése no sabe de mermas. Se escribe enseguida, sobre los
                // movimientos que produjo — ver `stampReason()`.
                occurredAt: $occurredAt,
                notes: $notes,
            );

            $this->stampReason($movements, $reason);

            return $movements;
        });

        // Bitácora TÉCNICA además del kardex, y es el único movimiento de inventario que la lleva: una merma es
        // una pérdida con actor, la zona de robo hormiga que §9 pide poder investigar (§6.7).
        //
        // `authorizedBy` es la columna que existe justo para esto: distingue «lo hizo el gerente» de «el gerente
        // autorizó que lo hiciera otra persona».
        $this->audit->log(
            action: AuditAction::WASTE_REGISTERED,
            auditable: $movements[0] ?? null,
            after: [
                'article' => $article->name,
                'warehouse' => $warehouse->code,
                'reason' => $reason->name,
                'quantity' => $quantity,
                'estimated_value' => $estimatedValue,
                'threshold' => $threshold,
                'movements' => count($movements),
            ],
            authorizedBy: $authorizer,
        );

        return $movements;
    }

    /**
     * ¿Hace falta autorización, y quién la dio?
     *
     * Devuelve `null` cuando la merma no pasa el umbral: no se pide autorización que no hace falta, y aceptar una
     * concesión de PIN igualmente la consumiría —son de un solo uso— desperdiciando la que el usuario acababa de
     * pedir para otra cosa.
     *
     * @param  numeric-string|null  $estimatedValue
     * @param  numeric-string  $threshold
     *
     * @throws WasteRequiresAuthorizationException
     */
    private function resolveAuthorizer(
        ?string $estimatedValue,
        string $threshold,
        ?string $authorizationToken,
    ): ?TenantMembership {
        // Sin valor calculable no hay nada que comparar: un artículo sin costo capturado no puede cruzar un
        // umbral en pesos.
        if ($estimatedValue === null || bccomp($estimatedValue, $threshold, 2) !== 1) {
            return null;
        }

        if ($authorizationToken === null) {
            throw WasteRequiresAuthorizationException::forValue($estimatedValue, $threshold);
        }

        // `consume` revalida el permiso y el estado de la membresía, y la concesión queda gastada: una
        // autorización sirve para UNA operación. Si el token no vale, lanza `PinAuthorizationFailed`, que el
        // proveedor del módulo de identidad ya traduce a HTTP.
        return $this->pin->consume($authorizationToken, 'inventory.waste.authorize_above_threshold');
    }

    /**
     * El valor estimado de la merma: cantidad × costo vigente. `null` si el artículo no tiene costo.
     *
     * Se calcula ANTES de mover existencia, a propósito. El costo se congela en cada movimiento —eso lo hace
     * `RecordStockMovement`— pero la decisión de pedir autorización tiene que tomarse antes de tocar el kardex:
     * una merma que se registra y después se rechaza por falta de autorización dejaría existencia descontada sin
     * quien responda por ella.
     *
     * @param  numeric-string  $quantity
     * @return numeric-string|null
     */
    private function estimateValue(Article $article, string $quantity): ?string
    {
        $unitCost = $this->costs->current($article);

        return $unitCost === null ? null : Decimal::round(bcmul($quantity, $unitCost, 6), 2);
    }

    /**
     * El umbral de la sucursal del almacén, o el del negocio.
     *
     * Un almacén central no pertenece a ninguna sucursal (D11), así que resuelve el valor del tenant — que es lo
     * correcto: una merma en el almacén central no es de nadie en particular.
     *
     * @return numeric-string
     */
    private function threshold(Warehouse $warehouse): string
    {
        $value = $warehouse->branch_id === null
            ? $this->settings->get('inventory.waste_authorization_threshold')
            : $this->settings->forBranch('inventory.waste_authorization_threshold', $warehouse->branch_id);

        // El valor llega como float del catálogo de configuración; se normaliza a cadena con dos decimales para
        // compararlo con `bccomp`. Comparar un monto con `>` sobre floats es exactamente el error que §7 prohíbe.
        return Decimal::round((string) $value, 2);
    }

    /**
     * Escribe el motivo en los movimientos recién creados.
     *
     * `stock_movements` es INMUTABLE y el trait bloquea `update()`, así que esto va por el query builder — el
     * mismo camino que usa la reconstrucción de proyecciones.
     *
     * Y conviene decir por qué es aceptable: el motivo se está escribiendo **dentro de la misma transacción** que
     * creó las filas, antes de que nadie las haya podido leer. No es una corrección de evidencia registrada: es
     * la escritura inicial, partida en dos porque `IssueStock` no sabe de mermas y no debe saberlo.
     *
     * La alternativa era pasarle el motivo a `IssueStock` y de ahí a `RecordStockMovement`, ensuciando la firma de
     * los dos con un concepto que sólo le importa a uno de sus llamadores. La tercera —que el servicio de mermas
     * escribiera el kardex por su cuenta— rompería la regla de que hay una sola puerta de entrada.
     *
     * @param  list<StockMovement>  $movements
     */
    private function stampReason(array $movements, WasteReason $reason): void
    {
        if ($movements === []) {
            return;
        }

        StockMovement::query()
            ->whereIn('id', array_map(fn (StockMovement $m): int => $m->id, $movements))
            ->toBase()
            ->update(['waste_reason_id' => $reason->id]);

        foreach ($movements as $movement) {
            $movement->setAttribute('waste_reason_id', $reason->id);
            $movement->setRelation('wasteReason', $reason);
        }
    }
}
