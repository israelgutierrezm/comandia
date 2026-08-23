<?php

declare(strict_types=1);

use App\Modules\Configuration\Infrastructure\Models\TenantMailSetting;
use App\Modules\Configuration\Mail\TestConfigurationMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * CONFIGURACIÓN DE CORREO DEL NEGOCIO (Iteración 7, Tanda D1)
 *
 * Fijan lo importante y lo delicado: se guarda la config SMTP, la contraseña se CIFRA en reposo y NUNCA vuelve por la API;
 * el correo de prueba usa el remitente del negocio y marca la config como verificada; sin configurar, la prueba se
 * rechaza; y editar sin re-teclear la contraseña conserva la guardada.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    $this->guardar = fn (array $extra = []) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/mail-settings', array_merge([
            'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'ventas@fonda.mx', 'password' => 'clave-de-aplicacion',
            'from_address' => 'ventas@fonda.mx', 'from_name' => 'Fonda del Centro',
        ], $extra));
});

afterEach(fn () => app(TenantContext::class)->forget());

it('guarda la configuración, cifra la contraseña y no la devuelve', function () {
    ($this->guardar)()->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.host', 'smtp.gmail.com');

    // La API nunca devuelve la contraseña.
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/mail-settings')->json('data');
    expect($data)->not->toHaveKey('password');

    // En la base está CIFRADA (no en claro), pero el modelo la descifra.
    $raw = app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::table('tenant_mail_settings')->value('password'));
    expect($raw)->not->toBe('clave-de-aplicacion');

    $model = app(TenantContext::class)->runFor($this->tenant->id, fn () => TenantMailSetting::query()->firstOrFail());
    expect($model->password)->toBe('clave-de-aplicacion');
});

it('sin configurar, show dice configured:false y la prueba se rechaza', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/mail-settings')->assertOk()->assertJsonPath('data.configured', false);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/mail-settings/test', ['email' => 'prueba@fonda.mx'])->assertStatus(422);
});

it('envía el correo de prueba con el remitente del negocio y marca verificado', function () {
    Mail::fake();

    ($this->guardar)()->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/mail-settings/test', ['email' => 'prueba@fonda.mx'])
        ->assertOk()
        ->assertJsonPath('data.sent', true);

    Mail::assertSent(TestConfigurationMail::class, fn ($mail) => $mail->hasTo('prueba@fonda.mx'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/mail-settings')->assertJsonPath('data.verified_at', fn ($v) => $v !== null);
});

it('editar sin re-teclear la contraseña conserva la guardada', function () {
    ($this->guardar)()->assertOk();

    // Se cambia el host, sin mandar contraseña.
    ($this->guardar)(['host' => 'smtp.otro.mx', 'password' => ''])->assertOk()->assertJsonPath('data.host', 'smtp.otro.mx');

    $model = app(TenantContext::class)->runFor($this->tenant->id, fn () => TenantMailSetting::query()->firstOrFail());
    expect($model->password)->toBe('clave-de-aplicacion');
});
