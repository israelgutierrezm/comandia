<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Route;

/**
 * BUSCAR CON ACENTOS NO REVIENTA NINGÚN LISTADO
 *
 * ## El defecto que este candado cierra
 *
 * Buscar «azúcar» en el catálogo devolvía **500**. MySQL 8 se niega a comparar una columna `ascii_bin`
 * con un parámetro que no es ASCII —error 3988, «Conversion from collation utf8mb4_0900_ai_ci into
 * ascii_bin impossible for parameter»— y los códigos son `ascii_bin` a propósito (D58), para que `Kg` y
 * `kg` sean valores distintos.
 *
 * O sea: en un SaaS mexicano, buscar «azúcar», «jalapeño», «piña» o «puré» en cualquier listado que
 * incluyera una columna de código era un error del servidor. Con 544 pruebas en verde.
 *
 * Ninguna lo vio porque **todas buscaban palabras sin acentos**. Es el punto ciego más incómodo de una
 * suite: no falta una prueba de una función, falta un dato en las que ya existen. Lo encontró el
 * navegador, escribiendo lo que un usuario escribe.
 *
 * ## Por qué el candado barre TODAS las rutas y no sólo la de artículos
 *
 * Porque el defecto no era del catálogo: era de `ListQuery`, que lo usan todos los módulos. Corregirlo y
 * probar sólo artículos dejaría el mismo agujero abierto para las nueve iteraciones que faltan, y cada
 * módulo nuevo llegaría con su propia versión del 500. El barrido cubre los listados que existan hoy y
 * los que se agreguen después, sin que nadie tenga que acordarse de venir a añadirlos aquí.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda de los Acentos',
        ownerEmail: 'dueno@fonda.mx',
        ownerFirstName: 'Rocío',
        ownerPaternalSurname: 'Ibáñez',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * Los listados de colección de `/api/v1`: `GET` sin parámetros en la URL.
 *
 * Se excluyen las rutas con `{parámetro}` porque necesitarían un recurso real de cada tipo para poder
 * llamarlas, y lo que se está probando —la traducción del término de búsqueda a SQL— es común a todas:
 * vive en `ListQuery`, no en el controlador.
 *
 * @return list<string>
 */
function collectionRoutes(): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), strict: true) || str_contains($route->uri(), '{')) {
            continue;
        }

        $uris[$route->uri()] = true;
    }

    return array_keys($uris);
}

it('ningún listado revienta al buscar un término con acentos', function () {
    $rutas = collectionRoutes();

    expect($rutas)->not->toBeEmpty('No se encontró ningún listado: el candado no está mirando nada.');

    // Acentos, eñe y diéresis: los cuatro caracteres que un menú mexicano usa a diario y que ninguna
    // columna ASCII puede contener.
    $termino = 'azúcar piña jalapeño pingüino';

    $reventadas = [];

    foreach ($rutas as $uri) {
        $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->getJson('/'.$uri.'?search='.urlencode($termino));

        // 200 es lo esperado; 422 también vale —el endpoint no declara `search` en su whitelist y lo
        // rechaza, que es el comportamiento correcto de §8—. Lo que no vale es un error del servidor.
        if ($respuesta->status() >= 500) {
            $reventadas[] = sprintf('%s → %d', $uri, $respuesta->status());
        }
    }

    expect($reventadas)->toBe([], sprintf(
        "Estos listados devuelven un error del servidor al buscar con acentos:\n  - %s\n\n".
        'Casi siempre es una columna `ascii_bin` comparada con un parámetro que no es ASCII (MySQL 3988). '.
        '`ListQuery` descarta esas columnas cuando el término lleva acentos; si aparece aquí, la búsqueda '.
        'se está construyendo por fuera de `ListQuery`.',
        implode("\n  - ", $reventadas),
    ));
});

it('descartar columnas ASCII no pierde resultados que sí existen', function () {
    // La otra mitad del arreglo: no basta con no reventar, hay que seguir encontrando. Y la búsqueda
    // sigue siendo insensible a acentos y a mayúsculas por la colación de la base (D58), así que
    // «CREMA» y «acida» encuentran «Crema ácida».
    app(TenantContext::class)->set($this->tenant->id);

    $unidad = Unit::query()->where('code', 'ml')->firstOrFail();

    Article::create([
        'name' => 'Crema ácida',
        'base_unit_id' => $unidad->id,
        'is_supply' => true,
        'code' => 'CRM-01',
    ]);

    app(TenantContext::class)->forget();

    foreach (['ácida', 'acida', 'CREMA', 'Ácida'] as $termino) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->getJson('/api/v1/articles?search='.urlencode($termino))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Crema ácida');
    }

    // Y un término con acentos que no existe devuelve la lista VACÍA, no la lista completa: descartar
    // las columnas ASCII no puede convertirse en «no filtrar nada».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?search='.urlencode('mazapán'))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // El código sigue buscándose, que es para lo que la columna ASCII está en la lista.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?search=CRM-01')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Crema ácida');
});
