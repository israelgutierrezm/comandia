<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿Esta mesa tiene servicio en curso?»
 *
 * ## Por qué esto es una interfaz del kernel y no una consulta directa
 *
 * `Floor` necesita la respuesta para negarse a liberar a mano una mesa que tiene una cuenta abierta encima —si la
 * liberara, el siguiente cliente se sienta sobre una cuenta viva y el mesero cobra dos consumos como uno—. Pero la
 * respuesta la sabe `Pos`, y `Pos` ya depende de `Floor` desde el paso 7. Consultarla al revés cerraría un ciclo entre
 * el salón y el punto de venta.
 *
 * La salida es invertir la dependencia: el **contrato** vive en el kernel, `Floor` depende de la interfaz y `Pos` la
 * implementa. Ninguno de los dos módulos conoce al otro; los dos conocen el kernel, que no conoce a nadie (§2, regla 1).
 *
 * Es el mismo razonamiento de D231 con los eventos y de `RequiresAuthorizationException` con los errores: **lo que cruza
 * una frontera vive donde no depende de nadie**. Cambia sólo la forma — un evento anuncia algo que ya pasó, y esto es
 * una pregunta que hay que contestar antes de decidir.
 *
 * ## Y por qué NO se resuelve con un evento
 *
 * Un evento no sirve para preguntar. Quien libera la mesa necesita la respuesta **antes** de escribir, en la misma
 * petición y con la certeza de que es la de ahora. Ése es justo el criterio que D239 dejó fijado: el evento es para el
 * efecto que puede llegar tarde; esto no puede.
 */
interface LiveServiceProbe
{
    /**
     * ¿Hay alguna cuenta viva —abierta, con cuenta solicitada o cerrada sin pagar— en esta mesa?
     *
     * Una cuenta **pagada** o **cancelada** no cuenta: la mesa ya no le debe nada a nadie.
     */
    public function tableHasLiveService(int $tableId): bool;

    /**
     * El ULID de la cuenta viva de esta mesa, si la hay.
     *
     * Lo pide el **evento** de cambio de estado, que viaja al piso en vivo: sin él, la pantalla que recibe «mesa
     * ocupada» sabría que hay gente pero no a dónde llevar a quien toque la mesa, y tendría que volver a pedir el piso
     * entero para averiguarlo — justo lo que el evento existe para evitar.
     *
     * Devuelve el ULID y no el modelo, por la misma razón que los eventos llevan primitivos (D231): `Floor` no debe
     * poder tocar una cuenta, sólo nombrarla.
     */
    public function openAccountUlidForTable(int $tableId): ?string;
}
