<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Pos\Infrastructure\Models\PosOrder;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItemModifier;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Capturar items en una cuenta, CONGELANDO lo que se cobra.
 *
 * ## La decisión que define este servicio
 *
 * El precio, el nombre del artículo, la tasa de IVA y los modificadores con su precio se copian a la línea **en el
 * momento de capturarla** y no se releen nunca. Si alguien sube el precio del café a media tarde, las cuentas abiertas
 * siguen cobrando el precio con el que se pidió — que es lo que el cliente aceptó.
 *
 * El nombre también, y eso sorprende hasta que se piensa en un ticket reimpreso un mes después: tiene que decir lo que
 * decía el original, aunque el artículo se haya renombrado o dado de baja.
 *
 * ## El precio sale de la SUCURSAL de la cuenta
 *
 * §6.1 permite override de precio por sucursal, así que el mismo café cuesta distinto en Roma que en Polanco. Se resuelve
 * con la cascada del catálogo y se congela el resultado. Leer el precio maestro sería cobrar mal en la mitad de las
 * sucursales.
 *
 * ## Y una orden nueva por captura, no una línea más en la anterior
 *
 * Cada vez que alguien manda a preparar, es una **orden**. Tres rondas de bebidas son tres órdenes en la misma cuenta,
 * y eso es lo que permite que la cocina reciba tres comandas en lugar de una que crece. Reusar la orden anterior haría
 * imposible saber qué se pidió junto.
 */
final readonly class CaptureOrderItems
{
    public function __construct(
        private ContextHolder $context,
        private Settings $settings,
        private ResolveAreaRoute $routes,
    ) {}

    /**
     * Añade una orden con sus líneas a una cuenta abierta.
     *
     * @param  list<array{article_ulid: string, quantity: numeric-string, modifier_ulids?: list<string>, modifier_quantities?: array<string, int>}>  $lines
     */
    public function capture(PosAccount $account, array $lines): PosOrder
    {
        $this->assertAcceptsItems($account);

        $membershipId = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        return DB::transaction(function () use ($account, $lines, $membershipId): PosOrder {
            // El bloqueo de la cuenta antes de leer su secuencia: dos meseros capturando en la misma cuenta a la vez
            // tomarían el mismo número de orden, y el único de (cuenta, secuencia) rechazaría al segundo con un error de
            // MySQL en lugar de darle el siguiente número.
            $locked = PosAccount::query()->whereKey($account->id)->lockForUpdate()->sole();

            $order = PosOrder::create([
                'pos_account_id' => $locked->id,
                'sequence' => (int) PosOrder::query()->where('pos_account_id', $locked->id)->max('sequence') + 1,
                'created_by_membership_id' => $membershipId,
            ]);

            foreach ($lines as $linea) {
                $this->captureLine($locked, $order, $linea, $membershipId);
            }

            $this->recalculate($locked);

            return $order->refresh();
        });
    }

    /**
     * Una línea, con todo congelado.
     *
     * @param  array{article_ulid: string, quantity: numeric-string, modifier_ulids?: list<string>, modifier_quantities?: array<string, int>}  $linea
     */
    private function captureLine(PosAccount $account, PosOrder $order, array $linea, int $membershipId): void
    {
        $article = Article::query()
            ->where('ulid', $linea['article_ulid'])
            ->with([
                'branchOverrides' => fn ($q) => $q->where('branch_id', $account->branch_id),

                // La categoría se carga porque el ruteo asciende un nivel cuando la categoría del artículo no tiene
                // regla propia (D240).
                'category',
            ])
            ->sole();

        if (! $article->is_sellable) {
            throw PosAccountException::articleNotSellable((string) $article->name);
        }

        // El precio de la SUCURSAL de la cuenta, con la cascada de §6.1 resuelta.
        $pricing = $article->effectivePricingFor((int) $account->branch_id);

        if ($pricing->price === null) {
            // Un artículo vendible sin precio no se puede cobrar, y capturarlo en cero sería peor: el cliente pagaría de
            // menos y nadie se enteraría hasta el corte.
            throw PosAccountException::articleWithoutPrice((string) $article->name);
        }

        // La tasa de IVA vigente, congelada en la línea. Es el paso 2 de D150: hoy sale de la configuración del negocio
        // con override por sucursal, y el día que exista `articles.vat_rate` saldrá de ahí sin tocar los documentos ya
        // emitidos.
        $vatRate = (string) $this->settings->forBranch('tax.vat_rate', (int) $account->branch_id);

        $modificadores = $this->resolveModifiers($linea);

        $item = PosOrderItem::create([
            'pos_order_id' => $order->id,
            'pos_account_id' => $account->id,
            'article_id' => $article->id,
            'quantity' => Decimal::round($linea['quantity'], 4),

            // ---- LOS CONGELADOS ----
            'article_name' => $article->name,
            'unit_price' => Decimal::round($pricing->price, 2),
            'vat_rate' => Decimal::round($vatRate, 2),
            'modifiers_total' => $this->modifiersTotal($modificadores),

            // El área se resuelve AQUÍ y se guarda, no al comandar (D240). Si se resolviera al comandar, cambiar una
            // regla de ruteo a media tarde partiría una cuenta abierta entre dos áreas: el mismo plato iría a la cocina
            // si se capturó antes y a la parrilla si se capturó después. Es la misma razón por la que el precio se
            // congela.
            //
            // `null` es legítimo: un item sin área no se comanda. Una cerveza que el mesero saca de la nevera no
            // necesita que nadie prepare nada.
            'preparation_area_id' => $this->routes->forArticle($article, (int) $account->branch_id),

            'captured_by_membership_id' => $membershipId,
        ]);

        foreach ($modificadores as ['modifier' => $modifier, 'quantity' => $cantidad]) {
            PosOrderItemModifier::create([
                'pos_order_item_id' => $item->id,
                'modifier_id' => $modifier->id,
                'modifier_name' => $modifier->name,
                'extra_price' => Decimal::round((string) $modifier->extra_price, 2),
                'quantity' => $cantidad,
            ]);
        }
    }

    /**
     * Los modificadores pedidos, con su cantidad.
     *
     * @param  array{modifier_ulids?: list<string>, modifier_quantities?: array<string, int>}  $linea
     * @return list<array{modifier: Modifier, quantity: int}>
     */
    private function resolveModifiers(array $linea): array
    {
        $ulids = $linea['modifier_ulids'] ?? [];

        if ($ulids === []) {
            return [];
        }

        $cantidades = $linea['modifier_quantities'] ?? [];
        $resueltos = [];

        foreach (Modifier::query()->whereIn('ulid', $ulids)->get() as $modifier) {
            $resueltos[] = [
                'modifier' => $modifier,

                // Uno por omisión. La cantidad son los 3 shots de D7, y sólo tiene sentido si el grupo del modificador
                // la permite — eso lo valida el Form Request, que es donde vive el contrato de entrada.
                'quantity' => max(1, (int) ($cantidades[$modifier->ulid] ?? 1)),
            ];
        }

        return $resueltos;
    }

    /**
     * Lo que los modificadores suman a la línea.
     *
     * @param  list<array{modifier: Modifier, quantity: int}>  $modificadores
     */
    private function modifiersTotal(array $modificadores): string
    {
        $total = '0.00';

        foreach ($modificadores as ['modifier' => $modifier, 'quantity' => $cantidad]) {
            // `bcmul` y no `*`: son los 3 shots por su precio, y multiplicar dinero con floats es lo que §7 prohíbe.
            $total = bcadd($total, bcmul((string) $modifier->extra_price, (string) $cantidad, 2), 2);
        }

        return $total;
    }

    /**
     * Recalcula las proyecciones de la cuenta desde sus líneas.
     *
     * ## Por qué se recalcula todo en lugar de sumar el delta
     *
     * Sumar lo nuevo al total anterior parece más eficiente y es más frágil: cualquier camino que modifique una línea
     * —un descuento, una cancelación, mover un item a otra cuenta— tendría que acordarse de ajustar el total. Recalcular
     * desde las líneas hace que el total sea siempre una consecuencia y no un acumulado que se puede desviar.
     *
     * Es la misma decisión que `article_stocks` en la Iteración 3: la proyección se reconstruye del hecho.
     */
    public function recalculate(PosAccount $account): PosAccount
    {
        // Una SUBCUENTA de una división no se recalcula.
        //
        // No tiene items propios —dividir reparte el importe y deja la mercancía en la madre (§6.3)— así que recalcular
        // la dejaría en cero y el cliente de la parte 2 de 4 no pagaría nada. Su importe se escribe una vez, al dividir,
        // y es fijo por definición.
        if ($account->isSplitPart()) {
            return $account;
        }

        $items = PosOrderItem::query()
            ->where('pos_account_id', $account->id)
            ->billable()
            ->get();

        $subtotal = '0.00';
        $descuentos = '0.00';
        $iva = '0.00';

        foreach ($items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $descuentos = bcadd($descuentos, (string) $item->discount_amount, 2);
            $iva = bcadd($iva, $item->vatAmount(), 2);
        }

        // Los descuentos de CUENTA se restan aquí; los de ITEM no.
        //
        // Es la distinción que hay que tener clara o el total sale mal en las dos direcciones. Un descuento de item ya
        // está dentro de `line_total` —columna generada que resta `discount_amount`— así que sumarlo otra vez lo
        // descontaría dos veces. Uno de cuenta, en cambio, no tiene línea donde vivir: si no se restara aquí, no se
        // restaría en ningún sitio y el cliente pagaría el total sin descuento.
        $deCuenta = '0.00';

        foreach (PosDiscount::query()->where('pos_account_id', $account->id)->accountWide()->get() as $descuento) {
            $deCuenta = bcadd($deCuenta, (string) $descuento->resulting_amount, 2);
        }

        $total = bcsub($subtotal, $deCuenta, 2);

        $account->update([
            'subtotal' => $subtotal,

            // Lo que el ticket muestra como «descuentos» es la suma de los dos alcances: al cliente le da igual dónde
            // vivan.
            'discount_total' => bcadd($descuentos, $deCuenta, 2),

            'vat_total' => $iva,

            // El total ES el subtotal menos los descuentos de cuenta: los precios son IVA incluido (D30), así que
            // sumarle el IVA lo cobraría dos veces — el error que este comentario existe para evitar.
            'total' => $total,
        ]);

        // El candado optimista se incrementa EN LA BASE y no asignando el atributo, por dos razones.
        //
        // La primera es de diseño: `version` no está en `$fillable` a propósito, porque es un mecanismo de concurrencia
        // y no un campo del negocio — si fuera asignable en masa, un `update($request->all())` dejaría que el cliente
        // fijara la versión que quisiera y el candado no protegería nada. Lo descubrí con un `MassAssignmentException`,
        // y la excepción tenía razón.
        //
        // La segunda es que `increment()` suma en SQL, así que dos escrituras concurrentes de la misma cuenta no pierden
        // un incremento — leer, sumar uno y guardar sí lo perdería, y sería irónico en el mecanismo que existe justo
        // para detectar escrituras concurrentes.
        $this->touchVersion($account);

        return $account->refresh();
    }

    /**
     * Mueve el candado optimista de la cuenta sin recalcular importes.
     *
     * Comandar no cambia el total —los items ya estaban contados— pero **sí** cambia lo que la cuenta contiene, y quien
     * la tenía en pantalla desde antes ya no está mirando lo mismo. Sin este empujón, ese cliente podría cobrar creyendo
     * que nada se comandó, y el candado no lo detendría porque la versión seguiría coincidiendo.
     *
     * Es público porque lo llama `CommandOrder`. Recalcular ahí sería la alternativa y sería peor: haría una consulta y
     * cuatro sumas para escribir los mismos importes.
     */
    public function touchVersion(PosAccount $account): void
    {
        PosAccount::query()->whereKey($account->id)->increment('version');
    }

    /**
     * ¿La cuenta admite más items?
     *
     * Dos razones para negarse, y son distintas: la cuenta ya no está viva, o el negocio configuró que pedir la cuenta
     * bloquea la captura (`pos.lock_items_on_bill_request`). Lo segundo es configurable porque los dos comportamientos
     * son legítimos: en un restaurante, pedir la cuenta significa «ya terminamos»; en un bar, alguien la pide y a los
     * cinco minutos pide otra cerveza.
     */
    private function assertAcceptsItems(PosAccount $account): void
    {
        if (! $account->status->acceptsItems()) {
            throw PosAccountException::accountDoesNotAcceptItems(
                $account->displayName(),
                $account->status->label(),
            );
        }

        if ($account->status === PosAccountStatus::BillRequested
            && $this->settings->forBranch('pos.lock_items_on_bill_request', (int) $account->branch_id)) {
            throw PosAccountException::itemsLockedAfterBillRequest($account->displayName());
        }
    }
}
