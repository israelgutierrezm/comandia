<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Infrastructure\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Único por tenant, y con la colación de la base es además acento- e caso-insensible: un
            // nombre aleatorio evita que dos factories seguidas choquen.
            'name' => 'Etiqueta '.Str::random(8),
        ];
    }
}
