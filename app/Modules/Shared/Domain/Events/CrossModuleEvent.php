<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

/**
 * Marca un evento como **contrato entre módulos** (D231).
 *
 * ## Qué problema resuelve
 *
 * La regla 3 de §2 dice que los efectos cruzados viajan por eventos: «el POS emite `CuentaPagada`, y los listeners de
 * cada módulo reaccionan». La regla 2 dice que las dependencias nunca fluyen hacia un módulo operativo. Las dos juntas
 * sólo se sostienen si el **evento no pertenece a ninguno de los dos módulos**: si vive en el emisor, quien escucha
 * declara depender de él, y en la Iteración 3 eso ya obligó a romper un ciclo a mano (D209).
 *
 * La Iteración 4 multiplica el problema: el POS emite hacia inventarios, finanzas, impresión y salón a la vez. Si la
 * dirección se decide mal, el monolito modular deja de serlo justo en su punto más caliente.
 *
 * ## Las dos reglas que un evento de éstos cumple
 *
 * 1. **Vive en este espacio de nombres**, que es del shared kernel — y el kernel no depende de nadie, así que ningún
 *    módulo acaba declarando dependencia de otro por escucharlo.
 *
 * 2. **Lleva sólo datos primitivos**: ULIDs, cadenas, enteros, montos como cadena decimal, y DTOs inmutables hechos de
 *    lo mismo. **Nunca un modelo Eloquent.**
 *
 * La segunda regla importa más de lo que parece, y no por purismo arquitectónico: **estos eventos se serializan a la
 * cola**. Pasar un modelo a un job y recargarlo al otro lado es una fuente conocida de bugs —el modelo pudo cambiar
 * entre el despacho y el consumo, o dejar de existir— y hace que el oyente actúe sobre un estado que ya no es el que
 * disparó el evento. Con primitivos, el oyente recibe **lo que era verdad cuando ocurrió el hecho**, que es lo que un
 * evento de dominio significa.
 *
 * ## Y el evento tiene que ser AUTOCONTENIDO
 *
 * No basta con mandar el ULID del documento y esperar que el oyente lo lea: leerlo obligaría a consultar tablas de otro
 * módulo, que es la misma frontera por otra puerta. Un evento que cruza lleva **todo lo que sus oyentes necesitan**,
 * aunque eso lo haga más grande. Si un oyente necesita algo que el evento no trae, o el evento está incompleto o ese
 * oyente no debería estar escuchando.
 *
 * ## El tenant viaja en el evento
 *
 * Un oyente puede correr en una cola, sin sesión ni petición, donde no hay contexto de negocio que heredar. Así que
 * todo evento de éstos lleva su `tenantId` y el oyente abre el contexto con él — es lo que ya hacen los tres oyentes de
 * la recepción de compra, y aquí se vuelve parte del contrato en lugar de una costumbre.
 */
interface CrossModuleEvent
{
    /**
     * El negocio al que pertenece el hecho.
     *
     * Es la llave interna y no el ULID a propósito: no sale por la API —esto no es un recurso— y el contexto de
     * tenancy se abre con el entero. Exponerlo aquí no contradice §7: la regla es que las llaves internas no salgan
     * al cliente, y un evento de dominio no sale del servidor.
     */
    public function tenantId(): int;
}
