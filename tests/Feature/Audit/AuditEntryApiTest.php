<?php

declare(strict_types=1);

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * Consulta de la bitácora técnica (§6.7, D47).
 *
 * Verifica lo que hace útil a la bitácora: que se pueda investigar. Los tres casos de uso que §9
 * necesita para detectar robo hormiga son filtrar por acción, por persona y por rango de fechas.
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
    $this->ownerMembership = $alta['membership'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('lista la bitácora, lo más reciente primero', function () {
    // En una investigación se empieza por lo último que pasó.
    //
    // Se viaja en el tiempo en lugar de asignar `created_at`: la bitácora no lo admite en
    // asignación masiva —y hace bien, porque poder fijar la fecha de un registro de auditoría
    // desde una petición sería poder falsificar cuándo ocurrió algo—.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->travelTo(now()->subDays(2));
        AuditEntry::create(['action' => AuditAction::LOGIN]);

        $this->travelBack();
        AuditEntry::create(['action' => AuditAction::LOGOUT]);
    });

    $acciones = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertOk()
        ->json('data.*.action');

    expect($acciones[0])->toBe(AuditAction::LOGOUT);
});

it('cada acción viaja con su identificador Y su texto en español', function () {
    // La pantalla mostraba `organization.branch_created` en crudo. El identificador tiene que
    // seguir viajando porque es el valor por el que filtran los reportes: se agrega la etiqueta,
    // no se sustituye.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create(['action' => AuditAction::BRANCH_CREATED]),
    );

    $fila = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertOk()
        ->json('data.0');

    expect($fila['action'])->toBe('organization.branch_created');
    expect($fila['action_label'])->toBe('Creó una sucursal');
});

it('toda acción declarada del kernel tiene etiqueta', function () {
    // El candado. Una acción sin etiqueta se pinta con su identificador en inglés, contra la regla
    // de idioma de CLAUDE.md, y sólo se descubre abriendo la pantalla.
    $reflexion = new ReflectionClass(AuditAction::class);
    $constantes = $reflexion->getConstants();

    expect($constantes)->not->toBeEmpty();

    $etiquetas = AuditAction::labels();

    foreach ($constantes as $nombre => $valor) {
        expect(array_key_exists($valor, $etiquetas))
            ->toBeTrue("La acción {$nombre} («{$valor}») no tiene etiqueta en español.");
    }

    // Y a la inversa: una etiqueta huérfana señala una constante renombrada sin actualizar el mapa.
    foreach (array_keys($etiquetas) as $valor) {
        expect(in_array($valor, $constantes, strict: true))
            ->toBeTrue("La etiqueta «{$valor}» no corresponde a ninguna acción declarada.");
    }
});

it('pagina por CURSOR y no por página', function () {
    // §8 reserva el cursor para los listados de alto volumen. Con OFFSET, la página 500 cuesta
    // 500 veces la primera, y el COUNT(*) de "de 340,000" recorre la tabla en cada petición.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        for ($i = 0; $i < 5; $i++) {
            AuditEntry::create(['action' => AuditAction::LOGIN]);
        }
    });

    $meta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?per_page=2')
        ->assertOk()
        ->json('meta');

    expect($meta)->toHaveKey('next_cursor');
    // Sin `total` ni `last_page`: es lo que evita el COUNT(*) sobre millones de filas.
    expect($meta)->not->toHaveKey('total');
});

it('filtra por acción: es el reporte de robo hormiga de §9', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        AuditEntry::create(['action' => AuditAction::LOGIN]);
        AuditEntry::create(['action' => 'pos.discount_applied']);
        AuditEntry::create(['action' => 'pos.discount_applied']);
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?action=pos.discount_applied')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filtra por persona usando su identificador público', function () {
    // La API no expone llaves primarias (§7), así que el filtro llega como ULID y se traduce.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        AuditEntry::create([
            'action' => AuditAction::LOGIN,
            'actor_membership_id' => $this->ownerMembership->id,
        ]);
        AuditEntry::create(['action' => AuditAction::LOGIN]);
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/audit-entries?actor={$this->ownerMembership->ulid}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actor.employee_code', 'P001');
});

it('un actor desconocido NO devuelve la bitácora completa', function () {
    // Ignorar el filtro mostraría todo a quien cree estar viendo las acciones de una sola persona.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create(['action' => AuditAction::LOGIN])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?actor=01ZZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filtra por rango de fechas incluyendo el día completo', function () {
    // Quien filtra "hasta el día X" espera incluir ese día entero, no cortar a su medianoche.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->travelTo(now()->subDays(10));
        AuditEntry::create(['action' => AuditAction::LOGIN]);

        // A las 23:30 del día de hoy: si el filtro cortara a la medianoche del inicio del día,
        // esta entrada quedaría fuera y el usuario creería que no pasó nada.
        $this->travelBack();
        $this->travelTo(now()->setTime(23, 30));
        AuditEntry::create(['action' => AuditAction::LOGOUT]);
    });

    $hoy = now()->toDateString();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/audit-entries?occurred_from={$hoy}&occurred_to={$hoy}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', AuditAction::LOGOUT);
});

it('rechaza un rango invertido en lugar de devolver vacío', function () {
    // Un rango invertido casi siempre es un error del cliente, y una lista vacía se interpreta
    // como "no hay datos".
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?occurred_from=2026-03-10&occurred_to=2026-03-01')
        ->assertStatus(422)
        ->assertJsonPath('errors.occurred_from.0', 'La fecha inicial no puede ser posterior a la final.');
});

it('rechaza una fecha con formato inválido', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?occurred_from=el-martes-pasado')
        ->assertStatus(422);
});

it('rechaza filtros no declarados y ordenar por columnas no declaradas', function () {
    // Un `order by` sobre una tabla de este volumen y una columna sin índice sería un filesort
    // sobre millones de filas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?ip_address=10.0.0.1')
        ->assertStatus(422);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries?sort=action')
        ->assertStatus(422);
});

it('DISTINGUE al ejecutor del autorizador', function () {
    // Es la razón de ser de las dos columnas: sin ellas, "el gerente aplicó el descuento" y "el
    // gerente autorizó que el mesero lo aplicara" serían el mismo registro.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        AuditEntry::create([
            'action' => 'pos.discount_applied',
            'actor_membership_id' => $this->ownerMembership->id,
            'authorized_by_membership_id' => $this->ownerMembership->id,
        ]);
    });

    $entrada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->json('data.0');

    expect($entrada['actor']['name'])->toBe('Ana Gómez');
    expect($entrada['authorized_by']['name'])->toBe('Ana Gómez');
    // Mismo actor y autorizador: no fue autorizado por otra persona.
    expect($entrada['was_authorized_by_another'])->toBeFalse();
});

it('una acción del sistema no inventa un actor', function () {
    // Devolver "Sistema" como nombre sería indistinguible de un actor real llamado así.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create(['action' => AuditAction::LOGIN])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertJsonPath('data.0.actor', null);
});

it('no expone el namespace interno de la entidad auditada', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create([
            'action' => AuditAction::BRANCH_UPDATED,
            'auditable_type' => $this->branch::class,
            'auditable_id' => $this->branch->id,
        ])
    );

    $auditable = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->json('data.0.auditable');

    expect($auditable['type'])->toBe('Branch');
    expect(json_encode($auditable))->not->toContain('App\\Modules');

    // Tampoco la PK interna: "nunca exponer IDs secuenciales" es regla de datos no negociable, y se
    // estaba filtrando aquí — la pantalla mostraba «Branch #2».
    expect($auditable)->not->toHaveKey('id');
    expect(json_encode($auditable))->not->toContain((string) $this->branch->id);
});

it('NO existe endpoint para escribir ni borrar la bitácora', function () {
    // Ausencia deliberada: un endpoint de escritura permitiría fabricar evidencia, y uno de
    // borrado permitiría que el tenant destruyera aquello con lo que se le investiga.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create(['action' => AuditAction::LOGIN])
    );

    $ulid = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): string => AuditEntry::query()->firstOrFail()->ulid
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/audit-entries', ['action' => 'inventada'])
        ->assertStatus(405);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/audit-entries/{$ulid}")
        ->assertStatus(405);
});

it('la bitácora de otro negocio es invisible', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    $ajena = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn (): AuditEntry => AuditEntry::create(['action' => AuditAction::LOGIN])
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/audit-entries/{$ajena->ulid}")
        ->assertNotFound();
});

it('sólo quien tiene permiso de auditoría consulta la bitácora', function () {
    $cajero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::CASHIER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $cajero->ulid)
        ->getJson('/api/v1/audit-entries')
        ->assertForbidden();
});

it('el asiento identifica a la entidad por su ULID público, no por la llave interna', function () {
    // La columna se aprobó al cerrar la Iteración 2. La bitácora es evidencia y tiene que poder leerse
    // sola: la llave interna sólo significa algo mientras la fila exista, y además no se puede exponer
    // (D91). El ULID congelado en el asiento lo explica aunque la entidad desaparezca.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(AuditLogger::class)->log(
            action: AuditAction::BRANCH_UPDATED,
            auditable: $this->branch,
        )
    );

    $auditable = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertOk()
        ->json('data.0.auditable');

    expect($auditable['ulid'])->toBe($this->branch->ulid)
        ->and($auditable['type'])->toBe('Branch');

    // Y sigue sin filtrar la llave interna.
    expect($auditable)->not->toHaveKey('id');
});

it('lo que no tiene ULID público se registra sin inventarle uno', function () {
    // No todo lo auditable tiene identificador público —una tabla pivote no tiene ninguno—, y un ULID
    // fabricado en un registro de evidencia sería indistinguible de uno real.
    $entrada = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): AuditEntry => app(AuditLogger::class)->log(
            action: AuditAction::SETTING_UPDATED,
            auditable: null,
        )
    );

    expect($entrada->auditable_ulid)->toBeNull();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertOk()
        ->assertJsonPath('data.0.auditable', null);
});

it('los asientos anteriores a la columna se leen sin ULID y sin reventar', function () {
    // Las filas previas NO se rellenaron a propósito: `audit_entries` es append-only por §7, y derivar el
    // ULID de cada una habría sido un UPDATE masivo sobre la tabla de evidencia del sistema. Que el valor
    // sea derivado no cambia la naturaleza de la operación.
    //
    // Así que el caso «asiento sin ULID pero con entidad» existe y tiene que leerse bien.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::create([
            'action' => AuditAction::BRANCH_UPDATED,
            'auditable_type' => $this->branch::class,
            'auditable_id' => $this->branch->id,
            'auditable_ulid' => null,
        ])
    );

    $auditable = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/audit-entries')
        ->assertOk()
        ->json('data.0.auditable');

    expect($auditable['type'])->toBe('Branch')
        ->and($auditable['ulid'])->toBeNull();
});
