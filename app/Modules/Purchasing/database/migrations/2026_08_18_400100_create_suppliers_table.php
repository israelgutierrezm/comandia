<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `suppliers` — a quién le compra el negocio (D26).
 *
 * ## Sin cuentas por pagar en v1
 *
 * §6.2 dice «compras v1 mínimas», así que no hay saldos, ni vencimientos, ni antigüedad de deuda.
 * `payment_terms_days` se guarda igual, y no es una promesa a medias: es un **dato del proveedor** que se pierde para
 * siempre si no se captura al darlo de alta —«éste me da treinta días»— y que sirve para decidir a quién comprarle sin
 * que exista ningún módulo de pagos. Cuando lleguen las cuentas por pagar, el dato ya estará.
 *
 * ## `legal_name` y `trade_name` por separado
 *
 * La razón social va en la factura y el nombre comercial es como lo llama la cocina. «Distribuidora de Alimentos del
 * Bajío S.A. de C.V.» y «Don Beto» son el mismo proveedor, y colapsarlos obligaría a elegir entre una lista ilegible y
 * una factura mal emitida.
 *
 * ## El RFC deduplica cuando existe, y aquí NO hace falta el truco de D93
 *
 * Dos proveedores con el mismo RFC son la misma persona moral capturada dos veces, y el síntoma es que las compras se
 * reparten entre dos fichas y «¿cuánto le compro a éste?» da la mitad. Así que el RFC es único por negocio.
 *
 * Y es único **con un índice normal**, aunque la columna sea nulable. Conviene decir por qué, porque es justo lo
 * contrario de D93: allá el problema era que MySQL **no** deduplica `NULL` y hacía falta una columna generada para
 * forzarlo; aquí ese comportamiento es exactamente el que se quiere — muchos proveedores sin RFC (el puesto del
 * mercado no tiene) y ninguno repetido entre los que sí lo tienen.
 *
 * Empecé escribiendo la columna generada por inercia del patrón. Sobraba: el mismo patrón resuelve dos problemas
 * opuestos y hay que mirar cuál de los dos se tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // `ascii_bin` como los demás códigos del sistema (D58): `DON-BETO` y `don-beto` son códigos distintos, y
            // que la base los distinga evita que dos personas crean haber capturado el mismo proveedor.
            $table->char('code', 20)->charset('ascii')->collation('ascii_bin');

            $table->string('legal_name', 200);
            $table->string('trade_name', 120)->nullable();

            // 13 caracteres: persona moral 12, persona física 13. `ascii_bin` porque un RFC es siempre mayúsculas y
            // dígitos, y compararlo sin distinguir caso permitiría capturar el mismo dos veces.
            $table->char('rfc', 13)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 160)->nullable();

            // Días de crédito. `unsignedSmallInteger` llega a 65 535, de sobra para cualquier plazo real; `null` es
            // «no se sabe» y cero es «de contado», que son cosas distintas.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            $table->string('notes', 500)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'suppliers_tenant_code_unique');

            // Nulable y único a la vez: MySQL admite tantos `NULL` como haga falta en un índice único, y aquí eso es
            // la característica y no el problema. La cadena vacía se normaliza a `null` en el Form Request, para que
            // dos proveedores «sin RFC» no choquen por haber mandado `""`.
            $table->unique(['tenant_id', 'rfc'], 'suppliers_tenant_rfc_unique');

            // «Los proveedores activos, por nombre»: es el selector de toda pantalla de compra y el listado de la
            // sección. El nombre comercial no entra en el índice porque es nulable y la ordenación es por razón social.
            $table->index(['tenant_id', 'status', 'legal_name'], 'suppliers_tenant_status_name_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
