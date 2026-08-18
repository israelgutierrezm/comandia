<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\ModifierGroupResource;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Asignación de grupos de modificadores a un artículo (D7).
 *
 * `PUT` sincroniza la lista completa, por lo mismo que la receta se guarda entera: el orden en que se presentan
 * los grupos forma parte de la asignación, y con operaciones de "agregar" y "quitar" el orden habría que
 * recalcularlo en cada llamada — o dejarlo inconsistente entre ellas.
 */
final class ArticleModifierGroupController
{
    /**
     * @return AnonymousResourceCollection<Collection<int, ModifierGroup>>
     */
    public function index(Article $article): AnonymousResourceCollection
    {
        $groups = $article->modifierGroups()
            ->with(['modifiers' => fn ($q) => $q->orderBy('sort_order')])
            ->get();

        return ModifierGroupResource::collection($groups);
    }

    /**
     * Sincroniza los grupos del artículo, en el orden recibido.
     *
     * El orden del arreglo ES el orden de presentación: pedirlo aparte invitaría a que llegara desordenado o
     * incompleto, y el cliente ya envía la lista en el orden en que la pintó.
     */
    public function sync(Request $request, Article $article): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'modifier_group_ulids' => ['present', 'array', 'max:30'],
            'modifier_group_ulids.*' => ['string', 'size:26'],
        ], [
            'modifier_group_ulids.present' => 'Envía la lista de grupos, aunque esté vacía.',
        ]);

        /** @var list<string> $ulids */
        $ulids = $validated['modifier_group_ulids'];

        // Se resuelven con el scope de tenant aplicado, así que un ULID de otro negocio simplemente no
        // aparece: el aislamiento no depende de que el cliente mande identificadores válidos.
        $groups = ModifierGroup::query()->whereIn('ulid', $ulids)->get()->keyBy('ulid');

        $desconocidos = array_values(array_diff($ulids, $groups->keys()->all()));

        if ($desconocidos !== []) {
            // Ignorarlos en silencio dejaría al cliente creyendo que asignó un grupo que no se asignó — el
            // peor resultado, porque la respuesta parecería correcta.
            throw ValidationException::withMessages([
                'modifier_group_ulids' => ['Alguno de los grupos indicados no existe.'],
            ]);
        }

        $pivot = [];

        foreach ($ulids as $index => $ulid) {
            $pivot[$groups[$ulid]->id] = ['sort_order' => $index];
        }

        $article->modifierGroups()->sync($pivot);

        return $this->index($article);
    }
}
