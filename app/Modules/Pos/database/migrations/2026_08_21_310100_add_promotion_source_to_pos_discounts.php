<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde viene un descuento: la mano de un humano, o una promoción automática (Iteración 6).
 *
 * ## Por qué el origen importa, y no es sólo etiqueta
 *
 * `pos_discounts` ya guardaba el efecto monetario de los descuentos MANUALES. Una promoción automática produce el mismo
 * efecto —dinero retirado de la venta— y reusa esta tabla a propósito: la aritmética del total (IVA incluido) vive en
 * un solo sitio, `CaptureOrderItems::recalculate()`, y las promociones no la reinventan. El gancho ya estaba puesto
 * desde la Iteración 4: `authorized_by_membership_id` se dejó nullable con el comentario «el día que exista un descuento
 * automático —una promoción— no habrá humano autorizando».
 *
 * Pero manual y promoción NO son lo mismo para el reporte antifraude de §9: un descuento manual es sospechoso —alguien
 * lo autorizó con su PIN—, una promoción es una regla del negocio. `source` los separa sin adivinar, y `promotion_ulid`
 * enlaza la fila con la definición que la produjo, que es lo que el registro por venta y el reporte por promoción
 * necesitan.
 *
 * ## La tabla es inmutable; agregar columnas no lo contradice
 *
 * La inmutabilidad de `pos_discounts` es sobre las FILAS (no se reescriben), no sobre el esquema. Un `ALTER` que añade
 * columnas es un cambio de estructura, no de historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_discounts', function (Blueprint $table): void {
            // 'manual' por omisión: todas las filas existentes son descuentos hechos a mano, que es lo único que había.
            $table->enum('source', ['manual', 'promotion'])->default('manual')->after('kind');

            // La definición que produjo el descuento, sólo para las de origen promoción. Por ULID y sin FK: `Pos` no
            // depende de `Promotions` (la flecha va al revés, por el probe del kernel).
            $table->char('promotion_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('pos_discounts', function (Blueprint $table): void {
            $table->dropColumn(['source', 'promotion_ulid']);
        });
    }
};
