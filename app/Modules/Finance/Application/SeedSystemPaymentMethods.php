<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentMethodKind;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;

/**
 * Siembra los cuatro métodos de pago del sistema.
 *
 * ## Por qué cuatro y no tres
 *
 * El diseño de la iteración proponía sembrar tres —efectivo, tarjeta, transferencia— y dejar el crédito del cliente
 * para cuando existiera el saldo. Se siembran los cuatro, **el crédito desactivado**: la naturaleza tiene que existir
 * desde el principio porque `pos_payments` la va a citar, y crearla más tarde obligaría a una migración de datos en un
 * negocio que ya estuviera operando. Desactivada no aparece en la caja, así que no promete algo que todavía no
 * funciona.
 *
 * ## Idempotente por código
 *
 * Se usa `firstOrCreate` sobre `(tenant, code)` porque esto corre en el alta de un negocio **y** en la sincronización
 * de un negocio que ya existía. Un segundo pase no puede duplicar métodos ni pisar la configuración que el negocio haya
 * hecho encima — el orden de los botones o el estado son suyos en cuanto los toca.
 */
final readonly class SeedSystemPaymentMethods
{
    /**
     * Los cuatro, con su comportamiento y su razón.
     *
     * Las banderas no son configuración por omisión: son la naturaleza del método. El efectivo entra al cajón y da
     * cambio; la tarjeta no hace ninguna de las dos cosas porque el dinero llega al banco días después; la
     * transferencia exige referencia porque sin el folio bancario no se puede conciliar; y el crédito no mueve caja
     * porque no ha entrado nada — es una promesa con nombre.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'CASH',
                'name' => 'Efectivo',
                'kind' => PaymentMethodKind::Cash,
                'affects_cash_drawer' => true,
                'requires_reference' => false,
                'allows_change' => true,
                'status' => 'active',
                'sort_order' => 10,
            ],
            [
                'code' => 'CARD',
                'name' => 'Tarjeta',
                'kind' => PaymentMethodKind::Card,
                'affects_cash_drawer' => false,
                'requires_reference' => false,
                'allows_change' => false,
                'status' => 'active',

                // Sin `requires_reference`: la autorización de la terminal bancaria es deseable y **no** siempre está
                // a mano en el momento del cobro. Exigirla haría que el cajero inventara un número para poder cerrar
                // la cuenta, que es peor que no tenerlo. El negocio puede exigirla si quiere.
                'sort_order' => 20,
            ],
            [
                'code' => 'TRANSFER',
                'name' => 'Transferencia',
                'kind' => PaymentMethodKind::Transfer,
                'affects_cash_drawer' => false,
                'requires_reference' => true,
                'allows_change' => false,
                'status' => 'active',
                'sort_order' => 30,
            ],
            [
                'code' => 'CUSTOMER_CREDIT',
                'name' => 'Crédito del cliente',
                'kind' => PaymentMethodKind::CustomerCredit,
                'affects_cash_drawer' => false,
                'requires_reference' => false,
                'allows_change' => false,

                // DESACTIVADO al nacer: el saldo del cliente llega en el paso 17. Un botón que cobra a crédito sin
                // saldo que cargar dejaría la cuenta pagada y la deuda en ninguna parte.
                'status' => 'inactive',
                'sort_order' => 40,
            ],
        ];
    }

    /**
     * @return int cuántos se crearon en esta pasada
     */
    public function seed(): int
    {
        $creados = 0;

        foreach (self::definitions() as $definicion) {
            $metodo = PaymentMethod::query()->where('code', $definicion['code'])->first();

            if ($metodo !== null) {
                continue;
            }

            // `is_system` se pone por el query builder DESPUÉS de crear, por lo mismo que el motivo de merma de sistema
            // en la Iteración 3: el invariante del modelo bloquea escribir `is_system` en un `update()`, y ponerlo en
            // `create()` exigiría abrirlo en `$fillable` — con lo que cualquier alta por API podría declararse del
            // sistema.
            $metodo = PaymentMethod::create($definicion);

            PaymentMethod::query()->whereKey($metodo->id)->toBase()->update(['is_system' => true]);

            $creados++;
        }

        return $creados;
    }
}
