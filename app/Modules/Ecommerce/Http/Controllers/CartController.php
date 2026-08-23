<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\Cart;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use App\Modules\Ecommerce\Http\Requests\AddCartItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El carrito de la tienda pública (Iteración 8, Tanda B), sin autenticación. Vive en la sesión del cliente. El slug
 * resuelve el negocio; el servicio valida stock y pertenencia a la tienda. Termina en «listo para pagar» (el checkout es
 * la Tanda C).
 */
final class CartController
{
    use ResolvesPublicStore;

    public function __construct(private readonly Cart $cart) {}

    public function index(string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        return new JsonResponse(['data' => $this->cart->contents($store)]);
    }

    public function store(AddCartItemRequest $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $this->cart->add(
            $store,
            (string) $request->string('branch_ulid'),
            (string) $request->string('article_ulid'),
            (int) $request->integer('quantity'),
        );

        return new JsonResponse(['data' => $this->cart->contents($store)], 201);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $validated = $request->validate([
            'article_ulid' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->cart->setQuantity($validated['article_ulid'], (int) $validated['quantity']);

        return new JsonResponse(['data' => $this->cart->contents($store)]);
    }

    public function destroy(string $slug, string $article): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $this->cart->remove($article);

        return new JsonResponse(['data' => $this->cart->contents($store)]);
    }
}
