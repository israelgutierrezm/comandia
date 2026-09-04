<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Finance\Application\CalculateSessionCut;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * El corte de una caja: esperado contra declarado, método por método.
 *
 * ## Vive en `Pos` y pide el esperado a `Finance`
 *
 * Las dos mitades están en módulos distintos: lo **esperado** sale del diario y lo **declarado** son las declaraciones
 * del cajero, que son de aquí. Alguien tiene que juntarlas.
 *
 * Lo hace `Pos` porque `Pos → Finance` ya está declarado desde el paso 6, así que juntar aquí no cuesta ninguna flecha
 * nueva. Al revés habría hecho falta un contrato del kernel para que `Finance` leyera las declaraciones — el tercero de
 * la iteración— por un reporte que se mira una vez al día.
 *
 * ## Sirve a dos pantallas que NO son iguales
 *
 * El **corte** lo mira quien supervisa y muestra todo. El **precorte** es ciego: quien cuenta no ve el esperado. La
 * diferencia no está en este servicio sino en quién puede llamarlo — el permiso `finance.cuts.view`— y es deliberado:
 * un servicio que devolviera «a veces con esperado y a veces sin él» acabaría filtrándolo por un descuido de la
 * pantalla.
 */
final readonly class BuildSessionCutReport
{
    public function __construct(private CalculateSessionCut $cut) {}

    /**
     * @return array{
     *     expected_cash: numeric-string,
     *     total_declared: numeric-string,
     *     total_difference: numeric-string,
     *     by_method: list<array{method: string, method_ulid: string, expected: numeric-string, declared: numeric-string|null, difference: numeric-string|null}>,
     *     sales_total: numeric-string,
     *     payments_by_method: list<array{method: string, method_ulid: string, amount: numeric-string}>,
     *     expenses_total: numeric-string,
     *     withdrawals_total: numeric-string
     * }
     */
    public function forSession(PosSession $session): array
    {
        $esperado = $this->cut->expectedByMethod((int) $session->id);
        $pagos = $this->cut->paymentsByMethod((int) $session->id);

        $declarado = $session->declarations()
            ->where('moment', 'close')
            ->get()
            ->keyBy('payment_method_id');

        // Los métodos que aparecen en cualquiera de los dos lados. Uno declarado sin esperado también cuenta: si el
        // cajero declara doscientos pesos de un método que no se usó, eso es exactamente lo que hay que ver.
        $ids = collect(array_keys($esperado))->merge($declarado->keys())->merge(array_keys($pagos))->unique();

        $metodos = PaymentMethod::query()->whereIn('id', $ids)->get()->keyBy('id');

        $filas = [];
        $totalDeclarado = '0.00';
        $totalDiferencia = '0.00';

        foreach ($ids as $id) {
            $metodo = $metodos->get($id);

            if ($metodo === null) {
                continue;
            }

            $esperadoDelMetodo = Decimal::round($esperado[$id] ?? '0', 2);

            // `null` y no cero cuando no se declaró: son dos cosas distintas —«declaró que no había nada» y «no lo
            // contó»— y pintarlas igual haría que un método olvidado se viera como un faltante del total.
            $declaradoDelMetodo = $declarado->has($id)
                ? Decimal::round((string) $declarado[$id]->declared_amount, 2)
                : null;

            $diferencia = $declaradoDelMetodo === null
                ? null
                : bcsub($declaradoDelMetodo, $esperadoDelMetodo, 2);

            if ($declaradoDelMetodo !== null) {
                $totalDeclarado = bcadd($totalDeclarado, $declaradoDelMetodo, 2);
                $totalDiferencia = bcadd($totalDiferencia, (string) $diferencia, 2);
            }

            $filas[] = [
                'method' => (string) $metodo->name,
                'method_ulid' => (string) $metodo->ulid,
                'expected' => $esperadoDelMetodo,
                'declared' => $declaradoDelMetodo,
                'difference' => $diferencia,
            ];
        }

        // Cómo se pagó lo vendido, con el nombre del método para la pantalla. Sólo los métodos con los que de verdad se
        // cobró: uno sin pagos no aporta un renglón de cero al resumen.
        $pagosPorMetodo = [];

        foreach ($pagos as $methodId => $monto) {
            $metodo = $metodos->get($methodId);

            if ($metodo === null) {
                continue;
            }

            $pagosPorMetodo[] = [
                'method' => (string) $metodo->name,
                'method_ulid' => (string) $metodo->ulid,
                'amount' => $monto,
            ];
        }

        return [
            'expected_cash' => $this->cut->expectedCash((int) $session->id),
            'total_declared' => $totalDeclarado,
            'total_difference' => $totalDiferencia,
            'by_method' => $filas,

            // El resumen del turno: lo vendido, cómo se pagó y lo que salió. Del diario, como todo lo demás — nunca
            // sumado en el navegador (§6.9).
            'sales_total' => $this->cut->salesTotal((int) $session->id),
            'payments_by_method' => $pagosPorMetodo,
            'expenses_total' => $this->cut->expensesTotal((int) $session->id),
            'withdrawals_total' => $this->cut->withdrawalsTotal((int) $session->id),
        ];
    }
}
