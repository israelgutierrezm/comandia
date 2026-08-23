<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Contracts\StockAvailabilityProbe;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * El carrito de la tienda (Iteración 8, Tanda B), guardado en la SESIÓN (el grupo `public` la trae). Sin tabla en v1: al
 * hacer checkout (Tanda C) se materializa en un pedido.
 *
 * El carrito está atado a una sucursal (el cliente elige entre las que la tienda atiende): el precio y el stock se
 * resuelven ahí. Cambiar de sucursal reinicia el carrito, porque precio y existencia pueden diferir. Valida stock al
 * agregar según la política del artículo (ADR-007); los importes son DECIMAL en cadena, aritmética exacta.
 */
final class Cart
{
    private const KEY = 'store_cart';

    public function __construct(private readonly StockAvailabilityProbe $stock) {}

    public function add(Store $store, string $branchUlid, string $articleUlid, int $quantity): void
    {
        $branchId = $this->requireServedBranch($store, $branchUlid);
        [$article, $setting] = $this->requireStoreArticle($articleUlid);

        $this->assertCanSell($article, $setting, $branchId);

        $cart = $this->raw();

        // Cambiar de sucursal reinicia el carrito: los precios y el stock son de la sucursal.
        if (($cart['branch_ulid'] ?? null) !== $branchUlid) {
            $cart = ['branch_ulid' => $branchUlid, 'items' => []];
        }

        $current = (int) ($cart['items'][$articleUlid] ?? 0);
        $cart['items'][$articleUlid] = max(1, $current + $quantity);

        session()->put(self::KEY, $cart);
    }

    public function setQuantity(string $articleUlid, int $quantity): void
    {
        $cart = $this->raw();

        if (! isset($cart['items'][$articleUlid])) {
            return;
        }

        if ($quantity <= 0) {
            unset($cart['items'][$articleUlid]);
        } else {
            $cart['items'][$articleUlid] = $quantity;
        }

        session()->put(self::KEY, $cart);
    }

    public function remove(string $articleUlid): void
    {
        $cart = $this->raw();
        unset($cart['items'][$articleUlid]);
        session()->put(self::KEY, $cart);
    }

    /**
     * El carrito resuelto para pintar: líneas con precio y subtotal, más el total. Re-resuelve precio y stock en vivo (el
     * carrito guarda sólo artículo y cantidad).
     *
     * @return array{branch_ulid: string|null, items: list<array{article_ulid: string, name: string, unit_price: string, quantity: int, line_total: string, out_of_stock: bool}>, total: string, count: int}
     */
    public function contents(Store $store): array
    {
        $cart = $this->raw();
        $branchUlid = $cart['branch_ulid'] ?? null;
        $items = $cart['items'] ?? [];

        if ($branchUlid === null || $items === []) {
            return ['branch_ulid' => $branchUlid, 'items' => [], 'total' => '0.00', 'count' => 0];
        }

        $branch = Branch::query()->where('ulid', $branchUlid)->first();

        if ($branch === null) {
            return ['branch_ulid' => null, 'items' => [], 'total' => '0.00', 'count' => 0];
        }

        $lines = [];
        $total = '0.00';
        $count = 0;

        foreach ($items as $articleUlid => $quantity) {
            $article = Article::query()->where('ulid', $articleUlid)->first();
            $setting = $article === null ? null : ArticleStoreSetting::query()->where('article_id', $article->id)->first();

            if ($article === null || $setting === null || ! $setting->is_in_store) {
                continue; // un artículo que salió de la tienda desaparece del carrito
            }

            $price = $setting->channel_price ?? $article->effectivePricingFor((int) $branch->id)->price ?? '0.00';
            $lineTotal = bcmul($price, (string) $quantity, 2);
            $total = bcadd($total, $lineTotal, 2);
            $count += (int) $quantity;

            $lines[] = [
                'article_ulid' => $article->ulid,
                'name' => $article->displayName(),
                'unit_price' => $price,
                'quantity' => (int) $quantity,
                'line_total' => $lineTotal,
                'out_of_stock' => $setting->stock_policy === 'mark_out_of_stock'
                    && ! $this->stock->hasStock((int) $article->id, (int) $branch->id),
            ];
        }

        return ['branch_ulid' => $branchUlid, 'items' => $lines, 'total' => $total, 'count' => $count];
    }

    /**
     * @return array{branch_ulid?: string, items?: array<string, int>}
     */
    private function raw(): array
    {
        /** @var array{branch_ulid?: string, items?: array<string, int>} $cart */
        $cart = session()->get(self::KEY, ['branch_ulid' => null, 'items' => []]);

        return $cart;
    }

    private function requireServedBranch(Store $store, string $branchUlid): int
    {
        $branch = Branch::query()->where('ulid', $branchUlid)->first();

        $served = $store->storeBranches()->pluck('branch_id')->all();

        if ($branch === null || ! in_array((int) $branch->id, array_map('intval', $served), true)) {
            throw new UnprocessableEntityHttpException('Esa sucursal no atiende pedidos en línea.');
        }

        return (int) $branch->id;
    }

    /**
     * @return array{0: Article, 1: ArticleStoreSetting}
     */
    private function requireStoreArticle(string $articleUlid): array
    {
        $article = Article::query()->where('ulid', $articleUlid)->first();
        $setting = $article === null ? null : ArticleStoreSetting::query()->where('article_id', $article->id)->first();

        if ($article === null || $setting === null || ! $setting->is_in_store) {
            throw new UnprocessableEntityHttpException('Ese artículo no está en la tienda.');
        }

        return [$article, $setting];
    }

    private function assertCanSell(Article $article, ArticleStoreSetting $setting, int $branchId): void
    {
        if ($setting->stock_policy === 'sell_always') {
            return;
        }

        // `hide` y `mark_out_of_stock` no se pueden agregar sin existencia.
        if (! $this->stock->hasStock((int) $article->id, $branchId)) {
            throw new UnprocessableEntityHttpException('Ese artículo está agotado en la sucursal elegida.');
        }
    }
}
