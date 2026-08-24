<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de aceptación (Iteración 8, Tanda D parte 1, D51).
 *
 * El pedido gana los estados operativos que faltaban —`accepted`, `ready`, `completed`, `rejected`— sobre los que ya
 * tenía, y los sellos de tiempo de cada hito más quién lo aceptó (auditable). Cada línea congela **su área de preparación**
 * al hacer el pedido (como el POS congela el área al capturar, D240), para que al aceptar se parta en comandas por área sin
 * volver a resolver el ruteo. Y la tienda gana el interruptor de **aceptación automática** (D51).
 */
return new class extends Migration
{
    public function up(): void
    {
        // La lista cerrada de estados crece con los operativos. Se ordenan por el ciclo de vida para que se lean en orden.
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','accepted','ready','completed','failed','rejected','cancelled'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);

        Schema::table('orders', function (Blueprint $table): void {
            // Los sellos de cada hito: nullables porque un pedido sólo los va ganando al avanzar.
            $table->timestamp('accepted_at')->nullable()->after('placed_at');
            $table->timestamp('ready_at')->nullable()->after('accepted_at');
            $table->timestamp('completed_at')->nullable()->after('ready_at');
            $table->timestamp('rejected_at')->nullable()->after('completed_at');
            $table->string('rejection_reason', 300)->nullable()->after('rejected_at');

            // Quién aceptó (auditable). Nullable: la aceptación automática (D51) no tiene actor de personal. RESTRICT como
            // todo actor del sistema —una membresía que aceptó pedidos no se borra, se da de baja por estado—.
            $table->foreignId('accepted_by_membership_id')->nullable()->after('rejection_reason')
                ->constrained('tenant_memberships')->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // El área que preparará la línea, congelada al hacer el pedido vía la sonda `AreaRouter` (Pos resuelve el
            // ruteo). Nullable: un artículo sin regla de ruteo no se comanda, y es legítimo. `nullOnDelete`: si el área se
            // borra, la línea pierde su ruteo sin arrastrar el pedido.
            $table->foreignId('preparation_area_id')->nullable()->after('article_id')
                ->constrained('preparation_areas')->nullOnDelete();
        });

        Schema::table('stores', function (Blueprint $table): void {
            // Aceptación automática (D51): si está activa, un pedido pagado se acepta solo (sin esperar en la bandeja).
            $table->boolean('auto_accept_orders')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('auto_accept_orders'));

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preparation_area_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by_membership_id');
            $table->dropColumn(['accepted_at', 'ready_at', 'completed_at', 'rejected_at', 'rejection_reason']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','failed','cancelled'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);
    }
};
