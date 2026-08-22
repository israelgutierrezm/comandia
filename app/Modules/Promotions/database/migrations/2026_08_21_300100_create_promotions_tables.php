<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las DEFINICIONES de promoción (§6.3, D50).
 *
 * ## Catálogo acotado, no motor libre
 *
 * Cuatro tipos en la Especificación; tres en esta iteración (los cupones son de e-commerce, D314). El `type` es un enum
 * cerrado precisamente porque D50 lo pide: no se inventan reglas, se elige de un catálogo. Un tipo nuevo es una decisión
 * de producto y una migración, no un campo libre.
 *
 * ## Los campos de valor son columnas nullable por tipo, NO un JSON
 *
 * SIN JSON en datos de dominio (ADR / CLAUDE.md). Cada tipo usa las columnas que le tocan y deja el resto en null; los
 * CHECK garantizan que el rango sea válido cuando el valor existe, y el Form Request garantiza que el tipo traiga las
 * columnas que necesita. Repartir el rango entre BD (rango) y Request (completitud por tipo) es deliberado: la BD no
 * puede expresar «si type=percentage entonces percent_value NOT NULL» de forma portable, pero sí «percent_value, si
 * existe, está entre 0 y 100».
 *
 * ## La ventana horaria se evalúa en la zona de la SUCURSAL
 *
 * `starts_on`/`ends_on` acotan las fechas; `daily_start`/`daily_end` la franja del día (happy hour); `weekday_mask` los
 * días de la semana. Todo se compara en la hora **local de la sucursal** (§7), con el mismo `branch.timezone` que la
 * Iteración 5 por fin consumió: «los jueves de 6 a 8» no significa nada en UTC.
 *
 * `weekday_mask` es un entero de banderas (bit 0 = domingo … bit 6 = sábado, siguiendo `date('w')`), 127 = todos los
 * días. Es un escalar, no JSON; el resolver comprueba `mask & (1 << w)`.
 *
 * ## `version` para concurrencia, no para historia
 *
 * Las definiciones se editan en sitio (D315): el registro por venta congela su resultado, así que cambiar la definición
 * hoy no altera lo que descontó ayer, y no hace falta historizarla como los precios. `version` es el bloqueo optimista
 * del guardado, igual que en `floor_plans`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name', 120);
            $table->enum('type', ['percentage', 'amount', 'nxm', 'special_price']);

            // Valor por tipo. Nullable porque cada tipo usa el suyo (ver docblock).
            $table->decimal('percent_value', 5, 2)->nullable();   // percentage
            $table->decimal('amount_value', 12, 2)->nullable();   // amount (descuento) y special_price (precio final)
            $table->unsignedSmallInteger('buy_quantity')->nullable(); // nxm: compra N
            $table->unsignedSmallInteger('pay_quantity')->nullable(); // nxm: paga M

            // Vigencia. Todo nullable = «sin límite por ese lado».
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->time('daily_start')->nullable();
            $table->time('daily_end')->nullable();
            $table->unsignedSmallInteger('weekday_mask')->default(127);

            // Alcance de sucursal: si `all_branches`, aplica en todas; si no, en las de `promotion_branches`. Explícito
            // y no «cero filas = todas» para que crear una promoción y olvidar las sucursales no la aplique en todas por
            // descuido.
            $table->boolean('all_branches')->default(true);

            // Desempate de «mejor gana»: a igual descuento, mayor prioridad. El resolver desempata luego por ulid.
            $table->unsignedSmallInteger('priority')->default(0);

            // Acumulable con otras promociones sólo si la config del tenant lo permite (D315, D20).
            $table->boolean('is_stackable')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            // Bloqueo optimista del guardado, no historia (D315).
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by_membership_id')->constrained('tenant_memberships')->restrictOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'promotions_ulid_unique');
            $table->unique(['tenant_id', 'name'], 'promotions_tenant_name_unique');

            // El resolver trae las ACTIVAS de un tenant y filtra vigencia/ventana en PHP: un tenant tiene un puñado de
            // promociones, así que acotar por (tenant, status) basta y las columnas de fecha no necesitan índice.
            $table->index(['tenant_id', 'status'], 'promotions_tenant_status_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `promotions`
            ADD CONSTRAINT `chk_promotions_percent` CHECK (`percent_value` IS NULL OR (`percent_value` > 0 AND `percent_value` <= 100)),
            ADD CONSTRAINT `chk_promotions_amount` CHECK (`amount_value` IS NULL OR `amount_value` > 0),
            ADD CONSTRAINT `chk_promotions_nxm` CHECK (
                (`buy_quantity` IS NULL AND `pay_quantity` IS NULL)
                OR (`buy_quantity` > `pay_quantity` AND `pay_quantity` >= 1)
            ),
            ADD CONSTRAINT `chk_promotions_weekday_mask` CHECK (`weekday_mask` >= 1 AND `weekday_mask` <= 127)
        SQL);

        // A qué aplica: artículos o categorías. Exactamente uno por fila (CHECK). Una promoción con N objetivos tiene N
        // filas — SIN JSON.
        Schema::create('promotion_targets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();

            $table->foreignId('article_id')->nullable()->constrained('articles')->restrictOnDelete();
            $table->foreignId('article_category_id')->nullable()->constrained('article_categories')->restrictOnDelete();

            $table->timestamps();

            // Sin duplicar el mismo objetivo en la misma promoción.
            $table->unique(['promotion_id', 'article_id'], 'promotion_targets_article_unique');
            $table->unique(['promotion_id', 'article_category_id'], 'promotion_targets_category_unique');

            // El aislamiento de tenant exige el índice que empieza por tenant_id (ADR-002).
            $table->index(['tenant_id', 'promotion_id'], 'promotion_targets_tenant_promotion_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `promotion_targets`
            ADD CONSTRAINT `chk_promotion_targets_exactly_one` CHECK (
                (`article_id` IS NOT NULL AND `article_category_id` IS NULL)
                OR (`article_id` IS NULL AND `article_category_id` IS NOT NULL)
            )
        SQL);

        // Dónde aplica, cuando no es `all_branches`.
        Schema::create('promotion_branches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['promotion_id', 'branch_id'], 'promotion_branches_unique');
            $table->index(['tenant_id', 'branch_id'], 'promotion_branches_tenant_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_branches');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
