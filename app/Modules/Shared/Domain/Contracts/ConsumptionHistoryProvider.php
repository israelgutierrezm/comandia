<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Domain\Consumption\ConsumptionEntry;

/**
 * «¿Qué ha consumido este cliente?»
 *
 * ## Por qué es una sonda del kernel y no una consulta directa
 *
 * El expediente del cliente (§6.6) muestra sus consumos, pero los consumos son cuentas del POS: viven en `pos_accounts`,
 * que pertenece a `Pos`. Y `Pos` **ya depende de `Customers`** (asigna el cliente a la cuenta). Si `Customers` consultara
 * `Pos` para pintar el historial, cerraría un ciclo entre los dos módulos.
 *
 * La salida es la misma que con `LiveServiceProbe` (el salón le pregunta al POS si una mesa tiene servicio) y
 * `CashSessionProbe` (finanzas le pregunta al POS por el turno de caja): **la dependencia se invierte por el kernel**.
 * `Customers` consume este contrato; `Pos` lo implementa. Ninguno se nombra al otro para esto: los dos conocen el kernel,
 * que no conoce a nadie (D239, D266, D318).
 *
 * ## Degradación
 *
 * Si el binding no está —en una prueba que no levanta `Pos`, por ejemplo—, el default es un null-object que devuelve una
 * lista vacía: un expediente sin consumos, no un error. El historial es informativo; no debe poder tumbar la ficha.
 */
interface ConsumptionHistoryProvider
{
    /**
     * Los últimos consumos del cliente, del más reciente al más antiguo.
     *
     * Recibe el id interno del cliente —no su ULID— porque quien llama (`Customers`) ya resolvió el modelo y lo tiene en
     * la mano; el tenant lo impone el global scope de `pos_accounts`, no un parámetro. Devuelve primitivos: `Customers`
     * jamás toca un modelo de `Pos`.
     *
     * @return list<ConsumptionEntry>
     */
    public function forCustomer(int $customerId, int $limit): array;
}
