<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes programados (Iteración 7, Tanda D3, D45).
 *
 * «Mándame este reporte del periodo cerrado, cada día/semana/mes, a estos correos.» El scheduler los ejecuta: genera el
 * export (reusa la Tanda B), lo envía por el correo del negocio (D1) y avisa al autor (D2).
 *
 * Corre con el alcance del AUTOR (su membresía), como el resto del motor. `last_run_on` evita que se envíe dos veces el
 * mismo día. Los destinatarios van normalizados en su tabla (sin JSON). El rango de fechas no se guarda: se deriva de la
 * frecuencia al correr (ayer / la semana pasada / el mes pasado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();

            $table->string('report_key', 80);
            $table->enum('format', ['pdf', 'xlsx', 'csv']);
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);

            // Agrupación opcional; los filtros de fecha los pone la frecuencia al correr.
            $table->string('group_by', 120)->nullable();

            $table->date('last_run_on')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'scheduled_reports_ulid_unique');
            $table->index(['tenant_id', 'membership_id'], 'scheduled_reports_owner_index');
            // «¿Cuáles toca correr hoy?»: por frecuencia y última corrida.
            $table->index(['frequency', 'last_run_on'], 'scheduled_reports_due_index');
        });

        Schema::create('scheduled_report_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('scheduled_report_id')->constrained('scheduled_reports')->cascadeOnDelete();
            $table->string('email', 191);

            $table->index(['tenant_id', 'scheduled_report_id'], 'scheduled_report_recipients_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report_recipients');
        Schema::dropIfExists('scheduled_reports');
    }
};
