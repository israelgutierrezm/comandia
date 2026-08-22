<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Domain\Sat\SatCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Los catálogos del SAT que la pantalla de perfiles fiscales necesita para sus desplegables (régimen, uso CFDI).
 *
 * Es dato de referencia nacional, no de un negocio; se sirve del catálogo cerrado en código. Se protege con el permiso
 * de administrar perfiles fiscales: quien no los edita no necesita el catálogo.
 */
final class SatCatalogController
{
    public function __invoke(): JsonResponse
    {
        $regimes = [];

        foreach (SatCatalog::taxRegimes() as $code => $regime) {
            $regimes[] = [
                // A cadena: PHP convierte una clave de array numérica ('626') a entero, y un código del SAT es una
                // cadena —el cliente lo manda como cadena y así se guarda—.
                'code' => (string) $code,
                'description' => $regime['description'],
                'fisica' => $regime['fisica'],
                'moral' => $regime['moral'],
            ];
        }

        $uses = [];

        foreach (SatCatalog::cfdiUses() as $code => $description) {
            $uses[] = ['code' => $code, 'description' => $description];
        }

        return new JsonResponse(['data' => ['tax_regimes' => $regimes, 'cfdi_uses' => $uses]]);
    }
}
