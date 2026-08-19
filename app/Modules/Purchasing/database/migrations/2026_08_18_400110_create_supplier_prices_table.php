<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `supplier_prices` — el historial de lo que cada proveedor cobra (D26).
 *
 * ## Es un HISTORIAL, no un precio vigente, y ésa es toda la tabla
 *
 * §6.2 lo pide «para comparación y detección de subidas», y con una sola fila por (proveedor, artículo) esas dos cosas
 * son imposibles: comparar exige varios proveedores y detectar una subida exige **dos observaciones del mismo**. Un
 * `UPDATE` sobre el precio anterior borraría precisamente el dato que contesta la pregunta.
 *
 * Por eso es **inmutable** (§7), como el historial de costos. Se corrige agregando una observación nueva, no editando
 * la vieja: si el precio se capturó mal, lo cierto es que hubo un error de captura en esa fecha, y borrarlo hace que el
 * historial mienta sobre lo que se sabía entonces.
 *
 * ## Se alimenta de las recepciones, no de un catálogo que nadie mantiene
 *
 * Cada recepción confirmada deja una observación con `source = 'receipt'` (paso 9). La captura manual y la cotización
 * existen porque un precio que te dan por teléfono es información útil antes de comprar, pero el grueso del historial
 * llega solo. Un catálogo de precios que hay que mantener a mano es un catálogo que a los tres meses miente.
 *
 * `source = 'receipt'` **no es alcanzable todavía** en el paso 8: llega con las recepciones. Se declara ya, como el
 * `CostOrigin::Purchase` de la Iteración 2, porque el enum describe el dominio y no el estado de la implementación.
 *
 * ## `currency` sin conversión, y eso limita la comparación a propósito
 *
 * En México se cotiza en dólares lo importado, así que registrar un precio en USD como si fuera en pesos sería una
 * mentira en el dato. La columna existe para que el dato sea cierto.
 *
 * Lo que **no** hay es conversión: no hay tipo de cambio en el sistema, y no se va a inventar uno. La consecuencia se
 * asume y se hace visible — la comparación entre proveedores agrupa **por moneda**, así que dos precios en monedas
 * distintas no se comparan en lugar de compararse mal. Un número plausible y falso es peor que la ausencia de número
 * (es el mismo argumento del costeo cuando falta un costo).
 *
 * ## `presentation_id` nulable
 *
 * El precio puede ser por presentación de compra —«la caja de 12 kg a 480»— o por unidad base. Se guarda como se
 * observó, porque así viene la factura, y la comparación entre proveedores exige normalizar: por eso `unit_price` es
 * **siempre por unidad base** y la presentación se conserva sólo para poder explicar de dónde salió el número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_prices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT las dos: es evidencia histórica. Un proveedor con historial no se borra —se da de baja— y un
            // artículo tampoco. Si se borraran, el historial de precios se quedaría sin poder decir de quién ni de qué.
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // La presentación de compra en la que se observó el precio. `nullOnDelete` y no RESTRICT: es contexto
            // explicativo, no la identidad de la observación — el `unit_price` ya está normalizado a unidad base, así
            // que perder la presentación no invalida el dato.
            $table->foreignId('presentation_id')
                ->nullable()
                ->constrained('article_purchase_presentations')
                ->nullOnDelete();

            // SIEMPRE por unidad base, para que dos proveedores se puedan comparar. Cuatro decimales como el resto de
            // los costos: un gramo de azafrán no cabe en dos.
            $table->decimal('unit_price', 12, 4);

            // Lo que se capturó tal cual, para poder explicar el número normalizado. `null` cuando el precio se dio
            // directamente por unidad base.
            $table->decimal('observed_quantity', 12, 4)->nullable();
            $table->decimal('observed_price', 12, 4)->nullable();

            $table->char('currency', 3)->charset('ascii')->collation('ascii_bin')->default('MXN');

            // FECHA y no timestamp: una cotización es de un día, no de un instante, y guardar la hora invitaría a
            // ordenar por ella como si significara algo.
            $table->date('observed_at');

            $table->enum('source', ['receipt', 'quote', 'manual']);

            // La recepción que originó la observación, cuando vino de una. Nulable porque las cotizaciones y las
            // capturas manuales no tienen documento.
            $table->foreignId('purchase_receipt_id')->nullable();

            // Quién la capturó. `null` cuando la escribió el sistema al confirmar una recepción — no se inventa un
            // actor, igual que en el kardex.
            $table->foreignId('registered_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->string('notes', 300)->nullable();

            $table->timestamps();

            // LA consulta de la tabla: «cómo ha evolucionado el precio de este artículo», que es la comparación entre
            // proveedores y la detección de subidas a la vez. Empieza por `tenant_id` como manda ADR-002.
            $table->index(['tenant_id', 'article_id', 'observed_at'], 'supplier_prices_tenant_article_index');

            // «Qué me cobra este proveedor», que es la otra mitad: la ficha del proveedor y la negociación.
            $table->index(
                ['tenant_id', 'supplier_id', 'article_id', 'observed_at'],
                'supplier_prices_tenant_supplier_article_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_prices');
    }
};
