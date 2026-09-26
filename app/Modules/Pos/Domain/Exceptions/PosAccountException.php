<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use DomainException;

/**
 * Se intentó algo que una cuenta no admite (§6.3).
 */
final class PosAccountException extends DomainException
{
    public static function accountDoesNotAcceptItems(string $account, string $estado): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y no admite más items. Una cuenta cobrada o cancelada no se puede seguir llenando: su '
            .'total ya se fijó.',
            $account,
            mb_strtolower($estado),
        ));
    }

    public static function itemsLockedAfterBillRequest(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya pidió la cuenta y este negocio bloquea la captura a partir de ahí. Si hace falta agregar '
            .'algo, vuelve a abrirla.',
            $account,
        ));
    }

    public static function articleNotSellable(string $article): self
    {
        return new self(sprintf(
            '«%s» no es un artículo vendible: no tiene precio de venta y no se puede cobrar.',
            $article,
        ));
    }

    public static function articleWithoutPrice(string $article): self
    {
        return new self(sprintf(
            '«%s» no tiene precio en esta sucursal. Captúralo antes de venderlo — cobrarlo en cero haría que el cliente '
            .'pagara de menos y nadie se enterara hasta el corte.',
            $article,
        ));
    }

    public static function membershipRequired(): self
    {
        return new self('No hay una persona en contexto a la que atribuir esta captura.');
    }

    public static function fiscalProfileWithoutCustomer(): self
    {
        return new self('Para facturar hay que asociar un cliente a la cuenta antes de cobrar.');
    }

    public static function fiscalProfileNotFound(): self
    {
        return new self('El perfil fiscal indicado no existe o no es de este cliente.');
    }

    public static function tableNotAvailable(string $table): self
    {
        return new self(sprintf(
            'La mesa %s no está disponible: tiene servicio en curso o está unida a otra.',
            $table,
        ));
    }

    public static function itemsNotInAccount(string $account): self
    {
        return new self(sprintf(
            'Alguno de los items que pediste cancelar no está en la cuenta %s. Vuelve a cargarla: pudo moverse a otra '
            .'cuenta o cancelarse ya.',
            $account,
        ));
    }

    /** Un modificador agotado (86'ing del servicio) no se puede capturar, aunque siga en la carta. */
    public static function modifierSoldOut(string $modifier): self
    {
        return new self(sprintf('«%s» está agotado ahora mismo. Quítalo o elige otra opción.', $modifier));
    }

    /** Un modificador que no pertenece a ningún grupo del artículo: no se puede inyectar por fuera de la carta. */
    public static function modifierNotForArticle(string $modifier, string $article): self
    {
        return new self(sprintf('«%s» no es una opción de «%s».', $modifier, $article));
    }

    /** Un grupo obligatorio (o con mínimo) sin las opciones suficientes. */
    public static function modifierGroupRequiresMore(string $group, int $min): self
    {
        return new self(sprintf(
            'Elige %s %d %s en «%s».',
            $min === 1 ? 'al menos' : 'al menos',
            $min,
            $min === 1 ? 'opción' : 'opciones',
            $group,
        ));
    }

    /** Un grupo con más opciones de las que su máximo permite. */
    public static function modifierGroupTooMany(string $group, int $max): self
    {
        return new self(sprintf(
            'En «%s» puedes elegir a lo más %d %s.',
            $group,
            $max,
            $max === 1 ? 'opción' : 'opciones',
        ));
    }

    /** Un grupo que no admite cantidades, con una opción pedida más de una vez. */
    public static function modifierGroupNoQuantity(string $group): self
    {
        return new self(sprintf('Las opciones de «%s» no llevan cantidad; se eligen una vez.', $group));
    }

    public static function itemAlreadyCancelled(string $article): self
    {
        return new self(sprintf(
            '«%s» ya está cancelado. Cancelarlo otra vez emitiría una segunda comanda de cancelación al área, y la '
            .'cocina no sabría si son dos platos o el mismo dos veces.',
            $article,
        ));
    }

    /**
     * El bump del tablero de cocina sólo avanza; no retrocede (D350).
     *
     * Marcar «preparando» un plato ya servido, o cualquier salto hacia atrás, se rechaza: el avance va
     * comandado → preparando → listo, y una pantalla que intenta lo contrario está mirando un estado viejo.
     */
    public static function kitchenTransitionNotAllowed(string $article, string $from, string $to): self
    {
        return new self(sprintf(
            '«%s» no puede pasar de «%s» a «%s» en el tablero de cocina: el avance sólo va hacia adelante. Recarga el '
            .'tablero si otra pantalla ya lo movió.',
            $article,
            $from,
            $to,
        ));
    }

    /**
     * Cancelar algo ya comandado exige motivo.
     *
     * El mismo argumento que en las mermas (D27) y los retiros: sin motivo, una venta que desaparece es una venta que
     * nadie puede explicar. Y aquí importa más, porque hay comida hecha de por medio.
     */
    public static function cancellationReasonRequired(): self
    {
        return new self(
            'Cancelar un item ya comandado exige un motivo de al menos 3 caracteres: alguien preparó eso y el corte '
            .'tiene que poder explicar por qué no se cobró.',
        );
    }

    /**
     * Y exige decir qué se hizo con la comida.
     *
     * Podría inferirse del estado —«si está servido, es merma»— y sería adivinar: un plato marcado servido puede no
     * haberse tocado, y uno en «preparando» puede llevar media hora en la plancha. Quien está ahí lo sabe y el sistema
     * no. Del destino depende que el inventario registre una merma o devuelva el producto, así que adivinarlo movería
     * existencias a ciegas.
     */
    public static function cancellationDestinationRequired(): self
    {
        return new self(
            'Di qué se hizo con el producto: «waste» si ya estaba preparado y se tira, «restock» si no se tocó y vuelve '
            .'al inventario. De eso depende que se registre una merma o no.',
        );
    }

    public static function orderNotInAccount(string $account): self
    {
        return new self(sprintf(
            'Esa orden no pertenece a la cuenta %s.',
            $account,
        ));
    }

    public static function noOpenSession(): self
    {
        return new self(
            'No hay una caja abierta en esta sucursal. Sin sesión de caja no se puede cobrar: un pago que no pertenece a '
            .'ningún turno es dinero que ningún arqueo puede explicar.',
        );
    }

    public static function accountNotChargeable(string $account, string $estado): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y no admite pagos. Cobrar de más se corrige con una reversa, no aplicando otro pago '
            .'encima.',
            $account,
            mb_strtolower($estado),
        ));
    }

    public static function paymentMethodInactive(string $method): self
    {
        return new self(sprintf(
            'El método de pago «%s» está inactivo y no se puede usar para cobrar.',
            $method,
        ));
    }

    public static function paymentReferenceRequired(string $method): self
    {
        return new self(sprintf(
            '«%s» exige una referencia. Sin ella el pago no se concilia con el estado de cuenta del banco y el dinero '
            .'queda sin comprobar.',
            $method,
        ));
    }

    /**
     * El cliente entregó menos de lo que hay que cubrir.
     *
     * La propina cuenta para esto: si deja mil por una cuenta de 850 con 50 de propina, hay que cubrir 900. Aceptar
     * menos daría un cambio negativo, que el CHECK de la base rechaza — y con razón.
     */
    public static function tenderedBelowAmount(string $entregado, string $aCubrir): self
    {
        return new self(sprintf(
            'Se entregaron %s y hay que cubrir %s (el monto más la propina). Revisa la cifra.',
            $entregado,
            $aCubrir,
        ));
    }

    public static function courtesyRequiresItem(): self
    {
        return new self(
            'Una cortesía es de un plato, no de la cuenta entera: «esto va por la casa» señala algo concreto. Regalar la '
            .'mesa completa es un descuento del 100 %, que sí existe y deja rastro como tal.',
        );
    }

    public static function accountNotDiscountable(string $account, string $estado): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y ya no admite descuentos. Corregir de más se hace con una reversa del pago, no '
            .'descontando después — que es justo la maniobra que la auditoría del punto de venta existe para impedir.',
            $account,
            mb_strtolower($estado),
        ));
    }

    public static function discountNotPositive(): self
    {
        return new self('Un descuento de cero no descuenta nada, y uno negativo sería un cargo encubierto.');
    }

    public static function discountAboveBase(string $monto, string $base): self
    {
        return new self(sprintf(
            'El descuento de %s es mayor que los %s sobre los que se aplica. Un total negativo sería el negocio '
            .'pagándole al cliente.',
            $monto,
            $base,
        ));
    }

    public static function cannotSplitSubaccount(): self
    {
        return new self(
            'Una parte de una cuenta dividida no se vuelve a dividir. Dividir reparte el importe entre personas, y '
            .'dividir una parte otra vez daría un árbol que nadie sabría cobrar.',
        );
    }

    public static function alreadySplit(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya está dividida. Para repartirla de otra forma, cancela primero todas sus partes.',
            $account,
        ));
    }

    /**
     * La madre de una división no admite nada que mueva su importe ni su mercancía (D262).
     *
     * Su total ya se repartió en partes FIJAS y la madre queda pagada cuando todas lo están. Cobrarla directo cobraría
     * dos veces lo mismo; capturar, quitar o descontar cambiaría su total sin que las partes se enteraran, y lo nuevo no
     * lo pagaría nadie —o lo pagaría de más el cliente—.
     */
    public static function accountIsSplit(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s está dividida: su importe ya se repartió en partes y se cobra por ellas. Mientras esté '
            .'dividida no admite captura, descuentos, cobro directo, cancelación ni otras operaciones de cuenta. Para '
            .'cambiarla, cancela primero todas sus partes.',
            $account,
        ));
    }

    /**
     * Una parte de una división no lleva artículos propios ni se recalcula (D262): su importe se fijó al dividir.
     */
    public static function splitPartNotOperable(string $account): self
    {
        return new self(sprintf(
            '%s es una parte de una cuenta dividida: su importe se fijó al dividir y no lleva artículos propios. No '
            .'admite captura, descuentos, mesa ni otras operaciones de cuenta; eso se hace en la cuenta original, antes '
            .'de dividirla.',
            $account,
        ));
    }

    /**
     * Se quiso cobrar una parte de una división que ya no suma el total.
     *
     * Con una parte cancelada, las que quedan cubren menos que la cuenta: cobrarlas dejaría el resto sin cobrar y la
     * madre no quedaría saldada nunca. «Dividir en cuatro, cancelar una, cobrar tres» es el hueco del bar con otro
     * disfraz.
     */
    public static function splitIncomplete(string $account): self
    {
        return new self(sprintf(
            'La división a la que pertenece %s ya no suma el total: se canceló alguna de sus partes, y cobrar las que '
            .'quedan dejaría parte de la cuenta sin cobrar. Cancela también las demás partes para deshacer la división; '
            .'después vuelve a dividir o cobra la cuenta completa.',
            $account,
        ));
    }

    /**
     * Se quiso cancelar una parte de una división en la que ya entró dinero.
     *
     * Su importe se quedaría sin cobrar y la madre no se saldaría nunca: la mesa quedaría ocupada para siempre. Es D263
     * aplicado a la división como un todo — ninguna operación toca lo que ya tiene pagos.
     */
    public static function splitHasPayments(string $account): self
    {
        return new self(sprintf(
            '%s es una parte de una cuenta dividida que ya tiene pagos: cancelarla dejaría su importe sin cobrar y la '
            .'cuenta original no quedaría saldada nunca. Cobra las partes que faltan; corregir un cobro se hace con una '
            .'reversa del pago.',
            $account,
        ));
    }

    public static function cannotSplitEmpty(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s no tiene nada que repartir.',
            $account,
        ));
    }

    public static function accountsFromDifferentBranches(): self
    {
        return new self(
            'Las dos cuentas tienen que ser de la misma sucursal: mover mercancía entre locales no es una operación de '
            .'caja, y el corte de cada sucursal dejaría de cuadrar.',
        );
    }

    public static function cannotMergeIntoItself(): self
    {
        return new self('Una cuenta no se junta consigo misma.');
    }

    public static function accountNotOperable(string $account, string $estado): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y no admite esta operación.',
            $account,
            mb_strtolower($estado),
        ));
    }

    /**
     * No se mueven items de una cuenta que ya tiene pagos.
     *
     * Es la regla que protege las propinas y el ticket: mover mercancía dejaría el dinero donde estaba y el papel ya
     * impreso diría una cosa mientras la cuenta dice otra. Corregir un cobro es una reversa, no una mudanza.
     */
    public static function accountHasPayments(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya tiene pagos aplicados y no se puede dividir, juntar ni mover. Corregir un cobro se hace '
            .'con una reversa del pago.',
            $account,
        ));
    }

    /**
     * Quitar artículos de una cuenta que ya recibió dinero bajaría su total por debajo de lo cobrado, sin reversa del
     * pago: el diario diría que se cobró de más sin que nadie lo decidiera (§6.3).
     */
    public static function itemsCannotLeaveChargedAccount(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya tiene pagos aplicados: quitarle artículos la dejaría cobrada de más. Corrige primero el '
            .'cobro con una reversa del pago.',
            $account,
        ));
    }

    public static function alreadyAtTable(string $table): self
    {
        return new self(sprintf('La cuenta ya está en la mesa %s.', $table));
    }

    /**
     * A un pedido para llevar no se le asigna mesa.
     *
     * La base lo impide con un CHECK (una cuenta con mesa Y número de mostrador no se puede ni pintar), y llegar hasta
     * ahí era un 500 con la mesa ya ocupada dentro de la transacción. Se rechaza antes de tocar el salón.
     */
    public static function takeoutHasNoTable(string $account): self
    {
        return new self(sprintf(
            '%s es un pedido para llevar: no ocupa mesa. Si el cliente se queda a comer, abre una cuenta en la mesa.',
            $account,
        ));
    }

    /**
     * Un pedido cancelado no tiene entrega que avanzar: no hay bolsa que salga por el mostrador.
     */
    public static function cancelledOrderHasNoDelivery(string $account): self
    {
        return new self(sprintf(
            'El pedido %s está cancelado: no hay nada que entregar.',
            $account,
        ));
    }

    /**
     * Una transición de estado que la cuenta no admite desde donde está.
     *
     * Antes toda transición rechazada respondía con el mensaje de la captura («no admite más items»), que al pedir la
     * cuenta, cerrarla o reabrirla mandaba a buscar el problema en el sitio equivocado.
     */
    public static function transitionNotAllowed(string $account, string $estado, string $destino): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y no puede pasar a «%s».',
            $account,
            mb_strtolower($estado),
            mb_strtolower($destino),
        ));
    }

    /** Cancelar una cuenta que ya está pagada: se borraría una venta cobrada. */
    public static function cannotCancelPaidAccount(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya está pagada y no se puede cancelar: cancelarla borraría una venta cobrada. Si hubo un error '
            .'en el cobro, se corrige con una reversa del pago.',
            $account,
        ));
    }

    /** Cancelar una cuenta con un pago parcial: el dinero ya entró a la caja. */
    public static function cannotCancelWithPayments(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya tiene pagos aplicados y no se puede cancelar: el dinero ya entró a la caja y la venta dejaría '
            .'de explicarlo. Si hubo un error en el cobro, se corrige con una reversa del pago.',
            $account,
        ));
    }

    public static function notATakeoutOrder(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s no es un pedido para llevar: no hay nada que entregar. En una mesa se sirve y ya.',
            $account,
        ));
    }

    public static function deliveryTransitionNotAllowed(string $desde, string $hacia): self
    {
        return new self(sprintf(
            'Un pedido «%s» no puede pasar a «%s». Entregar es un hecho físico: la bolsa ya salió por el mostrador, y '
            .'deshacerlo en el sistema no la trae de vuelta.',
            $desde,
            $hacia,
        ));
    }

    /**
     * Se quiso mandar a cocina un pedido para llevar que se cobra al ordenar, sin haberlo pagado.
     *
     * La sucursal configuró `pos.takeout_payment_timing = on_order`: primero se cobra y luego se prepara. No bloquea la
     * entrega —eso nunca depende del pago (D269)—, sólo el momento de comandar.
     */
    public static function takeoutMustBePaidBeforeCommanding(string $account): self
    {
        return new self(sprintf(
            'El pedido %s se cobra al ordenar: hay que registrar el pago antes de mandarlo a cocina.',
            $account,
        ));
    }

    /**
     * Se quiso fiar una cuenta sin cliente.
     *
     * Es la diferencia entre fiar y regalar: un consumo a crédito sin nombre es dinero que nadie va a cobrar. La cuenta
     * se identifica con el cliente ANTES de cobrarla a crédito.
     */
    public static function creditNeedsCustomer(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s no tiene cliente. Para cobrarla a crédito hay que decir a quién se le fía: sin nombre, un '
            .'consumo fiado es dinero que nadie va a cobrar.',
            $account,
        ));
    }

    public static function versionMismatch(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s cambió mientras la tenías en pantalla. Vuelve a cargarla antes de continuar: alguien más pudo '
            .'agregar items o cobrarla.',
            $account,
        ));
    }
}
