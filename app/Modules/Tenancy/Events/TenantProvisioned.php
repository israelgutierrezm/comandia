<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dio de alta un negocio con su organización mínima.
 *
 * ## Por qué un evento y no una llamada directa
 *
 * "El tenant que no configura nada obtiene un restaurante funcional" (§1) implica que el alta tiene
 * que dejar sembrado lo mínimo de varios módulos: hoy las unidades de medida del catálogo, mañana
 * quizá un catálogo de motivos de merma o categorías sugeridas.
 *
 * `ProvisionTenant` vive en `Tenancy`, que es shared kernel, y el kernel **no puede depender de
 * ningún módulo de dominio** (§2, regla 1) — hay un candado estructural que lo verifica desde la
 * Fase 0. Llamar al sembrador de unidades desde aquí rompería la suite, y con razón: sería el kernel
 * conociendo al catálogo.
 *
 * Así que el kernel anuncia el hecho y cada módulo decide si le importa. El kernel no sabe quién
 * escucha, que es exactamente la regla 3 de §2.
 *
 * Se emite **dentro** de la transacción del alta y con el contexto de tenant ya abierto: sembrar las
 * unidades es parte del alta, y un negocio con propietario pero sin unidades no podría capturar ni un
 * artículo — sería un alta a medias que parece completa.
 */
final readonly class TenantProvisioned
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}
