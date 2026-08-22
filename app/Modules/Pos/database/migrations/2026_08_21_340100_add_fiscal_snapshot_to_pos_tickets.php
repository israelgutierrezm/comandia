<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El snapshot fiscal congelado en el ticket facturable, al cobrar (Iteración 6, D317, ADR-005).
 *
 * ## Por qué congelar, y por qué en el ticket
 *
 * El ticket final ya es el «folio facturable» (ADR-005). Para que el cliente pueda pedir su factura después hace falta
 * saber CON QUÉ datos: si la cuenta tenía cliente y se eligió uno de sus perfiles fiscales, se congela aquí —RFC, razón
 * social, régimen, uso, CP—. Congelado, como todo en el POS (D233): si el cliente corrige su RFC mañana, la factura de
 * hoy sigue diciendo lo que se capturó al cobrar.
 *
 * Todo nullable: la mayoría de los tickets son «público en general» —sin cliente, sin factura—, que es el caso normal.
 *
 * Es sólo captura, no timbrado: el CFDI real llega como evolución (ADR-005). Aquí queda el dato listo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->char('fiscal_rfc', 13)->charset('ascii')->collation('ascii_bin')->nullable()->after('folio');
            $table->string('fiscal_business_name', 200)->nullable()->after('fiscal_rfc');
            $table->char('fiscal_postal_code', 5)->charset('ascii')->collation('ascii_bin')->nullable()->after('fiscal_business_name');
            $table->char('fiscal_tax_regime_code', 4)->charset('ascii')->collation('ascii_bin')->nullable()->after('fiscal_postal_code');
            $table->char('fiscal_cfdi_use_code', 5)->charset('ascii')->collation('ascii_bin')->nullable()->after('fiscal_tax_regime_code');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'fiscal_rfc', 'fiscal_business_name', 'fiscal_postal_code',
                'fiscal_tax_regime_code', 'fiscal_cfdi_use_code',
            ]);
        });
    }
};
