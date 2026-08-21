<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Application\Authorization\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canales privados
|--------------------------------------------------------------------------
|
| Aquí sólo se declara QUÉ canales existen y con qué permiso. La comprobación vive en `ChannelAccess`, y no por gusto
| de separar: una función declarada en este archivo se **redeclara** cada vez que la aplicación arranca —una vez por
| prueba— y la segunda aborta la suite completa con `Cannot redeclare`, sin ejecutar nada. Es el fallo que
| `TestHelperNamesAreUniqueTest` vigila desde la Iteración 3, y este archivo es otro sitio donde ocurre.
|
| El razonamiento de las tres comprobaciones —tenant, alcance de sucursal y permiso por rol activo— está en el
| encabezado de `ChannelAccess`. La corta: un canal se pide con el ULID que manda el CLIENTE, y el `tenant_id` no
| protege de una sucursal ajena del mismo negocio (D292).
|
| No hay canales de PRESENCIA: dirían además quién está mirando, y nadie lo ha pedido.
|
*/

/** El piso de una sucursal. Lo escucha todo el que atiende, y por eso lo que viaja por él no lleva dinero. */
Broadcast::channel(
    'tenant.{tenantUlid}.branch.{branchUlid}.floor',
    fn (User $user, string $tenantUlid, string $branchUlid): bool => app(ChannelAccess::class)
        ->branch($tenantUlid, $branchUlid, 'floor.layouts.view'),
);

/** Las comandas de un área. Permiso distinto porque el público es otro: quien prepara, no quien atiende. */
Broadcast::channel(
    'tenant.{tenantUlid}.branch.{branchUlid}.area.{areaUlid}',
    fn (User $user, string $tenantUlid, string $branchUlid, string $areaUlid): bool => app(ChannelAccess::class)
        ->area($tenantUlid, $branchUlid, $areaUlid, 'printing.jobs.view'),
);
