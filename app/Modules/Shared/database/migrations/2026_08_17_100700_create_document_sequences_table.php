<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_sequences` — foliación sin huecos (ARQUITECTURA_MAESTRA §7, D73).
 *
 * Se construye en la Iteración 1 aunque su primer consumidor sea el POS en la
 * Iteración 4: es infraestructura del kernel y el mecanismo de concurrencia se
 * puede probar con transacciones simultáneas reales desde ya. Dejarla para después
 * significaría meter un lock delicado en la iteración más grande del proyecto y
 * bajo presión de entrega.
 *
 * CÓMO SE TOMA UN FOLIO: `SELECT ... FOR UPDATE` sobre la fila de la secuencia
 * DENTRO de la transacción del documento, incrementar, escribir el documento,
 * confirmar. El folio y el documento nacen o mueren juntos.
 *
 * COSTO ACEPTADO Y DECLARADO: esto SERIALIZA la creación de documentos por
 * (sucursal, tipo, serie). Es intencional —"sin huecos" es un requisito, no una
 * preferencia— y a 500–1,000 cuentas al día en el tenant más pesado
 * (ESPECIFICACIÓN_MAESTRA §2) el lock se sostiene sin problema.
 *
 * POR QUÉ NO `AUTO_INCREMENT`: un autoincremental es global a la tabla y deja
 * huecos en cuanto una transacción se revierte. La foliación necesita ser por
 * sucursal, por tipo y por serie, y sin huecos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // Catálogo cerrado en código. `ascii_bin` porque es un identificador.
            $table->string('document_type', 30)->charset('ascii')->collation('ascii_bin');

            // Serie configurable; por defecto el código de la sucursal.
            $table->string('series', 10)->charset('ascii')->collation('ascii_bin');

            $table->unsignedBigInteger('next_number')->default(1);

            $table->timestamps();

            // ES LA DEFINICIÓN MISMA DE LA SECUENCIA: exactamente la tupla de §7. Y es
            // el único índice, porque sólo se accede por esta llave —el `FOR UPDATE`
            // necesita encontrar la fila por índice único para bloquear una sola fila
            // y no un rango.
            $table->unique(
                ['tenant_id', 'branch_id', 'document_type', 'series'],
                'document_sequences_definition_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
