<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * El corte de caja: cuánto debería haber, por método.
 *
 * ## NO es una tabla. Es una consulta (§6.5, ADR-004)
 *
 * El corte se **calcula** del diario y nunca se almacena como verdad paralela. Un total guardado se desvía —una reversa
 * posterior, un gasto capturado tarde— y entonces hay dos cifras que dicen ser el corte de la misma noche, sin forma de
 * saber cuál es la buena. Reconstruyéndolo siempre, la respuesta es la de ahora.
 *
 * ## El efectivo esperado es una SUMA, no la fórmula de §6.5
 *
 * El diseño lo escribía así:
 *
 *     esperado(efectivo) = fondo + Σ pagos − Σ cambios − Σ retiros − Σ gastos de caja
 *                          − Σ liquidaciones de propina + Σ abonos de crédito
 *
 * Y esa fórmula ya no hace falta enumerarla. Cada asiento lleva `affects_cash_drawer` **copiado** al escribirse y el
 * signo impuesto por su tipo (D253), así que el efectivo esperado es literalmente:
 *
 *     SELECT SUM(amount) WHERE pos_session_id = X AND affects_cash_drawer = 1
 *
 * Las dos formas dan lo mismo, y ésta tiene una propiedad que la otra no: **un tipo nuevo de movimiento entra solo**. La
 * fórmula enumerada habría que editarla cada vez —y el día que alguien olvidara sumar los abonos de crédito, el arqueo
 * daría de más sin que nada fallara—. Es exactamente lo que §1.5 del diseño advertía al decir que sin gastos, propinas y
 * abonos el «esperado» sería sistemáticamente falso.
 *
 * Es también donde se paga que `affects_cash_drawer` se copie en lugar de leerse del método: si mañana alguien marca que
 * las tarjetas mueven el cajón, los cortes de ayer no cambian.
 *
 * ## Y el esperado de los DEMÁS métodos son sus pagos
 *
 * Una tarjeta no mueve el cajón, así que su «esperado» no sale de la suma de arriba. Sale de lo que se cobró con ella,
 * que es lo que la terminal bancaria tiene que decir al cortar.
 */
final readonly class CalculateSessionCut
{
    /**
     * Lo que debería haber en el cajón.
     *
     * @return numeric-string
     */
    public function expectedCash(int $sessionId): string
    {
        return Decimal::round((string) (FinancialMovement::query()
            ->where('pos_session_id', $sessionId)
            ->where('affects_cash_drawer', true)
            ->sum('amount') ?? 0), 2);
    }

    /**
     * Lo esperado de cada método que se usó en el turno.
     *
     * @return array<int, numeric-string> id del método => esperado
     */
    public function expectedByMethod(int $sessionId): array
    {
        $esperado = [];

        // El EFECTIVO se calcula por la vía del cajón y no sumando sus pagos, y la diferencia importa: el cajón arranca
        // con el fondo de apertura y se lleva los cambios, los retiros y los gastos. Sumar sólo los pagos en efectivo
        // daría una cifra que nadie va a contar.
        $efectivo = PaymentMethod::query()->where('code', 'CASH')->first();

        if ($efectivo !== null) {
            $esperado[(int) $efectivo->id] = $this->expectedCash($sessionId);
        }

        // Los demás: lo que se cobró con cada uno. Se incluye el cambio por si algún método llegara a darlo —hoy sólo el
        // efectivo— para que la cifra siga siendo «lo que entró por esta vía».
        $porMetodo = FinancialMovement::query()
            ->where('pos_session_id', $sessionId)
            ->whereIn('type', [FinancialMovementType::Payment->value, FinancialMovementType::Change->value])
            ->whereNotNull('payment_method_id')
            ->selectRaw('payment_method_id, SUM(amount) as total')
            ->groupBy('payment_method_id')
            ->pluck('total', 'payment_method_id');

        foreach ($porMetodo as $methodId => $total) {
            if ($efectivo !== null && (int) $methodId === (int) $efectivo->id) {
                continue;
            }

            $esperado[(int) $methodId] = Decimal::round((string) $total, 2);
        }

        return $esperado;
    }
}
