<?php

declare(strict_types=1);

use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Reporting\Infrastructure\Models\ReportExport;
use App\Modules\Reporting\Infrastructure\Models\ScheduledReport;
use App\Modules\Reporting\Jobs\RunScheduledReport;
use App\Modules\Reporting\Mail\ScheduledReportMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * REPORTES PROGRAMADOS (Iteración 7, Tanda D3, D45)
 *
 * Programar un reporte es personal (del autor) y exige el permiso del reporte concreto —recibirlo por correo no puede
 * saltarse el control de acceso—. Correrlo genera el export (reusa la Tanda B), lo envía por el correo del negocio (D1,
 * aquí sin SMTP configurado cae al mailer por omisión) y avisa al autor (D2). El comando del scheduler despacha los que
 * tocan hoy, sin contexto de tenant (es infraestructura).
 */
beforeEach(function () {
    Storage::fake('local');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(fn () => app(TenantContext::class)->forget());

it('programa un reporte con destinatarios y lo lista', function () {
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'daily',
            'recipients' => ['jefe@fonda.mx', 'conta@fonda.mx'],
        ])
        ->assertCreated()
        ->json('data');

    expect($data['recipients'])->toContain('jefe@fonda.mx')->toContain('conta@fonda.mx');
    expect($data['label'])->not->toBeEmpty();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/scheduled-reports')
        ->assertOk()
        ->assertJsonPath('data.0.report_key', 'sales.by_article')
        ->assertJsonCount(1, 'data');
});

it('correr ahora envía el reporte a cada destinatario, lo deja descargable y avisa al autor', function () {
    Mail::fake();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'daily',
            'recipients' => ['jefe@fonda.mx', 'conta@fonda.mx'],
        ])->assertCreated()->json('data.ulid');

    // La cola corre inline en pruebas: el job corre durante la petición.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/scheduled-reports/{$ulid}/run")
        ->assertStatus(202);

    // Un correo por destinatario.
    Mail::assertSent(ScheduledReportMail::class, 2);

    app(TenantContext::class)->set($this->tenant->id);

    // Quedó un export listo para descargar.
    expect(ReportExport::query()->where('report_key', 'sales.by_article')->where('status', 'ready')->exists())->toBeTrue();

    // Se avisó al autor.
    expect(Notification::query()->where('type', 'scheduled_report')->exists())->toBeTrue();

    // Y se marcó la última corrida.
    expect(ScheduledReport::query()->where('ulid', $ulid)->value('last_run_on'))->not->toBeNull();
});

it('rechaza una agrupación fuera de la whitelist del reporte', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'daily',
            'group_by' => 'inventado',
            'recipients' => ['jefe@fonda.mx'],
        ])
        ->assertStatus(422);
});

it('borra un programado propio', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'weekly',
            'recipients' => ['jefe@fonda.mx'],
        ])->assertCreated()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/scheduled-reports/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/scheduled-reports')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('otro negocio no puede correr ni borrar un programado ajeno', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'daily',
            'recipients' => ['jefe@fonda.mx'],
        ])->assertCreated()->json('data.ulid');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    // El ULID ajeno ni siquiera se resuelve (tenant scope): 404.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->postJson("/api/v1/scheduled-reports/{$ulid}/run")
        ->assertNotFound();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->deleteJson("/api/v1/scheduled-reports/{$ulid}")
        ->assertNotFound();
});

it('el comando del scheduler despacha los programados que tocan hoy', function () {
    Queue::fake();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/scheduled-reports', [
            'report_key' => 'sales.by_article',
            'format' => 'csv',
            'frequency' => 'daily',
            'recipients' => ['jefe@fonda.mx'],
        ])->assertCreated();

    // El scheduler corre SIN contexto de tenant (es de sistema): el comando recorre todos los negocios.
    app(TenantContext::class)->forget();

    $this->artisan('reports:run-scheduled')->assertSuccessful();

    Queue::assertPushed(RunScheduledReport::class, 1);
});
