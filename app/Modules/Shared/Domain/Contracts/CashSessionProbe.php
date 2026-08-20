<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * Preguntas sobre las sesiones de caja, para quien no es el punto de venta.
 *
 * ## Por qué `Finance` no puede consultarlas directamente
 *
 * Porque `Pos` ya depende de `Finance` desde el paso 6 —los métodos de pago, el diario— y consultar al revés cerraría un
 * ciclo entre el dinero y el punto de venta. Es exactamente el mismo problema que resolvió `LiveServiceProbe` en el paso
 * 13, y la misma salida: el contrato vive en el kernel, `Finance` depende de la interfaz y `Pos` la implementa.
 *
 * Es el **segundo** contrato de esta forma. Que aparezca dos veces en dos pasos consecutivos sugiere que no era un caso
 * raro sino la forma normal de que un módulo de dominio necesite algo que sólo sabe uno operativo.
 *
 * ## Por qué un gasto necesita saber esto
 *
 * Un gasto desde caja pertenece a un turno, y **el turno lo resuelve el servidor**. Aceptarlo del cliente dejaría que
 * alguien cargara un gasto al turno de otro: el arqueo del cajero de la mañana descuadrado por el de la tarde, sin nada
 * que lo explique.
 */
interface CashSessionProbe
{
    /**
     * El turno abierto de esta sucursal, o `null` si no hay ninguno.
     */
    public function openSessionIdForBranch(int $branchId): ?int;

    /**
     * El id interno de una sesión a partir de su ULID público.
     *
     * Existe para los filtros de las pantallas de finanzas —«qué salió de esta caja»—, que reciben el ULID porque los
     * ids internos no se exponen por la API.
     */
    public function sessionIdByUlid(string $ulid): ?int;
}
