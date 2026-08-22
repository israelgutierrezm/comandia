<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Domain\Promotions\PromotionOutcome;

/**
 * «¿Qué promoción aplica a esta cuenta, a esta hora, en esta sucursal?»
 *
 * ## Por qué esto es una interfaz del kernel y no un evento
 *
 * Una promoción cambia lo que el cliente paga, y el total tiene que reflejarlo **en la misma petición** —al previsualizar
 * la cuenta y al cobrar—. Eso la vuelve una **pregunta**, no un anuncio de algo que ya pasó. Y una pregunta que cruza
 * módulos vive en el kernel como interfaz, con la dependencia invertida (D239, D266, D277): «un evento no sirve para
 * preguntar; la respuesta hace falta antes de escribir».
 *
 * `Promotions` implementa este contrato; el POS lo invoca. Ninguno se declara en el `depends_on` del otro: los dos
 * conocen el kernel, que no conoce a nadie. Es el mismo patrón que `LiveServiceProbe` y `CashSessionProbe`, con el POS
 * como **consumidor** esta vez y `Promotions` como **proveedor**.
 *
 * ## Primitivos de entrada y de salida
 *
 * Recibe `LineSnapshot[]` (primitivos) y devuelve `PromotionOutcome` (primitivos). El motor lee SÓLO su propio catálogo
 * más el snapshot que recibe; jamás toca `pos_order_items`. El POS pasa primitivos y recibe primitivos.
 *
 * ## Degradación
 *
 * El POS nunca se bloquea (§6). Si el binding no está —módulo ausente, o un fallo—, se resuelve a un null-object que
 * devuelve «ninguna promoción». A diferencia de `LiveServiceProbe`, que falla ruidoso a propósito, aquí el default
 * seguro es cobrar sin promoción antes que no poder cobrar.
 */
interface PromotionResolver
{
    /**
     * @param  string  $atIso  El instante de la venta en ISO-8601 (UTC).
     * @param  string  $branchTimezone  La zona IANA de la sucursal. Viaja como primitivo —no como un modelo `Branch`—
     *                                  para que `Promotions` no dependa de `Organization`: el motor evalúa la ventana
     *                                  horaria (§7) con lo que recibe, sin consultar otra tabla.
     * @param  list<\App\Modules\Shared\Domain\Promotions\LineSnapshot>  $lines
     */
    public function resolveForAccount(int $branchId, string $atIso, string $branchTimezone, array $lines): PromotionOutcome;
}
