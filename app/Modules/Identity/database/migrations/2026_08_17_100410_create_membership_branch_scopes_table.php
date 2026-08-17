<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `membership_branch_scopes` — en qué sucursales puede operar una persona (D12).
 *
 * Resolución del alcance: si `tenant_memberships.has_all_branches` es 1, todas las
 * sucursales activas del tenant; si no, exactamente las filas de esta tabla.
 *
 * Sin ULID: es una tabla de relación y no se expone como recurso propio de la API.
 *
 * El alcance por ALMACÉN queda diferido a la Iteración 3 (D74). Hasta entonces el
 * alcance efectivo sobre almacenes es "los almacenes de mis sucursales", y el
 * almacén central —que no pertenece a ninguna sucursal— se protege con permiso en
 * lugar de con alcance. Deuda declarada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_branch_scopes', function (Blueprint $table): void {
            $table->id();

            // Regla A: redundante con la FK de la membresía y deliberado. Permite
            // acotar por tenant sin un join y que el índice empiece por `tenant_id`.
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('membership_id')
                ->constrained('tenant_memberships')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['membership_id', 'branch_id'], 'membership_branch_scopes_membership_branch_unique');

            // "Quién puede operar en esta sucursal": la consulta del alta de turno y de
            // los reportes por sucursal. La FK de `branch_id` sola no basta porque no
            // empieza por `tenant_id` como manda ADR-002.
            $table->index(['tenant_id', 'branch_id'], 'membership_branch_scopes_tenant_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_branch_scopes');
    }
};
