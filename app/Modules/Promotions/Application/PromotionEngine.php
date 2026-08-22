<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Shared\Domain\Contracts\PromotionResolver;
use App\Modules\Shared\Domain\Promotions\AppliedPromotion;
use App\Modules\Shared\Domain\Promotions\LineSnapshot;
use App\Modules\Shared\Domain\Promotions\PromotionOutcome;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * El motor que DECIDE qué promoción aplica (§6.3, D50, D310, D315).
 *
 * Implementa el contrato del kernel `PromotionResolver`. Lee SÓLO su propio catálogo y el snapshot de líneas que recibe;
 * nunca toca `pos_order_items`. El POS lo invoca; este módulo no conoce al POS.
 *
 * ## La semántica de cada tipo, fijada aquí porque la Especificación da el principio, no el algoritmo
 *
 * - **Porcentaje:** X % del `lineTotal` de cada línea objetivo (la base viva).
 * - **Monto:** $X de descuento en cada línea objetivo, topado al `lineTotal` de esa línea. Es por LÍNEA, no una vez por
 *   categoría: aplicar «$20 en Bebidas» una sola vez exigiría elegir a qué bebida, y elegir por el motor sería una
 *   regla que nadie pidió. Documentado como la lectura de v1.
 * - **NxM:** sobre la CANTIDAD de la línea. `buy=2, pay=1` (2x1) con cantidad 5 regala 2 unidades (dos grupos completos
 *   de 2, el sobrante de 1 no alcanza). Descuento = unidades regaladas × precio unitario. Se calcula sobre la línea
 *   —la captura junta «2 cervezas» en una línea de cantidad 2—, no cruzando líneas del mismo artículo; esa agregación
 *   es una evolución, no v1.
 * - **Precio especial:** el precio unitario objetivo baja a `amount_value`; descuento = (precio − especial) × cantidad,
 *   por línea. Si el «especial» es mayor que el precio real, no hay descuento (no se encarece nada).
 *
 * ## «Mejor gana, excepción configurable» (§6.3, D315)
 *
 * Por línea, entre las promociones que le aplican, gana la que MÁS descuenta al cliente; empata mayor `priority`,
 * desempata menor `ulid` (determinista). Si el negocio enciende `promotions.allow_stacking` (D20), las promociones
 * marcadas `is_stackable` se acumulan —cada una sobre la base que va quedando— y compiten como bloque contra la mejor no
 * acumulable. Apagado (el default), gana una sola.
 *
 * ## La ventana se evalúa en la hora de la SUCURSAL
 *
 * `atIso` llega en UTC y se convierte a `branchTimezone` antes de comparar fecha, franja horaria y día de la semana
 * (§7): «los jueves de 6 a 8» no significa nada en UTC.
 */
final readonly class PromotionEngine implements PromotionResolver
{
    public function __construct(private Settings $settings) {}

    public function resolveForAccount(int $branchId, string $atIso, string $branchTimezone, array $lines): PromotionOutcome
    {
        if ($lines === []) {
            return new PromotionOutcome();
        }

        $localMoment = CarbonImmutable::parse($atIso)->setTimezone($branchTimezone);

        $promotions = $this->activePromotionsFor($branchId, $localMoment);

        if ($promotions->isEmpty()) {
            return new PromotionOutcome();
        }

        $stackable = (bool) $this->settings->forBranch('promotions.allow_stacking', $branchId);

        $applied = [];

        foreach ($lines as $line) {
            foreach ($this->resolveLine($line, $promotions, $stackable) as $entry) {
                $applied[] = $entry;
            }
        }

        return new PromotionOutcome($applied);
    }

    /**
     * Las promociones vigentes AHORA en esta sucursal, con sus objetivos ya cargados.
     *
     * @return \Illuminate\Support\Collection<int, Promotion>
     */
    private function activePromotionsFor(int $branchId, CarbonImmutable $localMoment): \Illuminate\Support\Collection
    {
        $localDate = $localMoment->toDateString();

        return Promotion::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $localDate))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $localDate))
            ->where(fn ($q) => $q->where('all_branches', true)
                ->orWhereHas('branches', fn ($b) => $b->where('branch_id', $branchId)))
            ->with('targets')
            ->orderByDesc('priority')
            ->orderBy('ulid')
            ->get()
            ->filter(fn (Promotion $p): bool => $this->withinDailyWindow($p, $localMoment))
            ->values();
    }

    /**
     * ¿Estamos dentro de la franja horaria y el día de la semana de la promoción?
     */
    private function withinDailyWindow(Promotion $promotion, CarbonImmutable $localMoment): bool
    {
        // Día de la semana: bit 0 = domingo … 6 = sábado, igual que date('w').
        $weekdayBit = 1 << (int) $localMoment->format('w');

        if (($promotion->weekday_mask & $weekdayBit) === 0) {
            return false;
        }

        // Sin franja definida = todo el día.
        if ($promotion->daily_start === null || $promotion->daily_end === null) {
            return true;
        }

        $now = $localMoment->format('H:i:s');
        $start = $this->timeString($promotion->daily_start);
        $end = $this->timeString($promotion->daily_end);

        // Franja normal (18:00–20:00) y franja que cruza medianoche (22:00–02:00): las dos se soportan.
        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }

    private function timeString(mixed $value): string
    {
        // La columna TIME vuelve como cadena; se normaliza a H:i:s para comparar.
        return CarbonImmutable::parse((string) $value)->format('H:i:s');
    }

    /**
     * Las promociones que ganan en una línea.
     *
     * @param  \Illuminate\Support\Collection<int, Promotion>  $promotions
     * @return list<AppliedPromotion>
     */
    private function resolveLine(LineSnapshot $line, \Illuminate\Support\Collection $promotions, bool $stackable): array
    {
        $matching = $promotions
            ->filter(fn (Promotion $p): bool => $this->targets($p, $line))
            ->all();

        if ($matching === []) {
            return [];
        }

        // El monto que cada promoción descontaría sobre la base viva.
        $candidates = [];

        foreach ($matching as $promotion) {
            $amount = $this->amountFor($promotion, $line);

            if (bccomp($amount, '0', 2) > 0) {
                $candidates[] = ['promotion' => $promotion, 'amount' => $amount];
            }
        }

        if ($candidates === []) {
            return [];
        }

        if (! $stackable) {
            return [$this->best($candidates, $line)];
        }

        return $this->stack($candidates, $line);
    }

    /**
     * ¿La promoción apunta a esta línea, por artículo o por categoría?
     */
    private function targets(Promotion $promotion, LineSnapshot $line): bool
    {
        foreach ($promotion->targets as $target) {
            if ($target->article_id !== null && (int) $target->article_id === $line->articleId) {
                return true;
            }

            if ($target->article_category_id !== null
                && $line->categoryId !== null
                && (int) $target->article_category_id === $line->categoryId) {
                return true;
            }
        }

        return false;
    }

    /**
     * El monto que una promoción descuenta en una línea, calculado en el SERVIDOR. Nunca más que la base.
     *
     * @return numeric-string
     */
    private function amountFor(Promotion $promotion, LineSnapshot $line): string
    {
        $base = $line->lineTotal;

        $amount = match ($promotion->type) {
            // A seis decimales y se redondea al final, como toda reducción de escala del proyecto (D237).
            PromotionType::Percentage => Decimal::round(
                bcdiv(bcmul($base, (string) $promotion->percent_value, 6), '100', 6),
                2,
            ),

            PromotionType::Amount => Decimal::round((string) $promotion->amount_value, 2),

            PromotionType::Nxm => $this->nxmAmount($promotion, $line),

            PromotionType::SpecialPrice => $this->specialPriceAmount($promotion, $line),
        };

        // Nunca más que la base: un descuento mayor que la línea la dejaría negativa (el negocio pagándole al cliente).
        return bccomp($amount, $base, 2) > 0 ? $base : $amount;
    }

    /**
     * @return numeric-string
     */
    private function nxmAmount(Promotion $promotion, LineSnapshot $line): string
    {
        $quantity = (int) $line->quantity;
        $buy = (int) $promotion->buy_quantity;
        $pay = (int) $promotion->pay_quantity;

        if ($buy <= 0 || $quantity < $buy) {
            return '0.00';
        }

        // Grupos completos de `buy`; cada grupo regala `buy - pay` unidades.
        $freeUnits = intdiv($quantity, $buy) * ($buy - $pay);

        return Decimal::round(bcmul((string) $freeUnits, $line->unitPrice, 4), 2);
    }

    /**
     * @return numeric-string
     */
    private function specialPriceAmount(Promotion $promotion, LineSnapshot $line): string
    {
        $special = Decimal::round((string) $promotion->amount_value, 2);

        // Un «especial» más caro que el precio normal no encarece: no hay descuento.
        if (bccomp($special, $line->unitPrice, 2) >= 0) {
            return '0.00';
        }

        $perUnit = bcsub($line->unitPrice, $special, 2);

        return Decimal::round(bcmul($perUnit, $line->quantity, 4), 2);
    }

    /**
     * La mejor: mayor descuento; empata la primera, que ya viene ordenada por priority desc y ulid asc.
     *
     * @param  list<array{promotion: Promotion, amount: numeric-string}>  $candidates
     */
    private function best(array $candidates, LineSnapshot $line): AppliedPromotion
    {
        $winner = $candidates[0];

        foreach ($candidates as $candidate) {
            if (bccomp($candidate['amount'], $winner['amount'], 2) > 0) {
                $winner = $candidate;
            }
        }

        return $this->applied($winner['promotion'], $line, $winner['amount']);
    }

    /**
     * Todas las acumulables, cada una sobre la base que va quedando, topado a la línea.
     *
     * @param  list<array{promotion: Promotion, amount: numeric-string}>  $candidates
     * @return list<AppliedPromotion>
     */
    private function stack(array $candidates, LineSnapshot $line): array
    {
        $stackables = array_values(array_filter($candidates, fn ($c): bool => $c['promotion']->is_stackable));

        // Si ninguna es acumulable, o sólo una, es el caso «mejor gana» de siempre.
        if (count($stackables) <= 1) {
            return [$this->best($candidates, $line)];
        }

        $result = [];
        $remaining = $line->lineTotal;

        foreach ($stackables as $candidate) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $amount = bccomp($candidate['amount'], $remaining, 2) > 0 ? $remaining : $candidate['amount'];
            $remaining = bcsub($remaining, $amount, 2);

            $result[] = $this->applied($candidate['promotion'], $line, $amount);
        }

        return $result;
    }

    private function applied(Promotion $promotion, LineSnapshot $line, string $amount): AppliedPromotion
    {
        return new AppliedPromotion(
            promotionUlid: (string) $promotion->ulid,
            name: (string) $promotion->name,
            itemUlid: $line->itemUlid,
            resultingAmount: $amount,
        );
    }
}
