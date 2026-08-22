<?php

declare(strict_types=1);

namespace App\Modules\Pos\Reporting;

use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Reporting\Dimension;
use App\Modules\Shared\Domain\Reporting\FilterSpec;
use App\Modules\Shared\Domain\Reporting\Measure;
use App\Modules\Shared\Domain\Reporting\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ventas por artículo o categoría, con margen (Iteración 7, §6.7).
 *
 * La declara `Pos` porque lee `pos_order_items` —lo que se cobró—: el motor de `Reporting` no toca esta tabla, la recibe
 * a medio construir (ADR-007). Lee SÓLO tablas alcanzables por `Pos` (sus items, y por join `pos_accounts`/`articles`/
 * `article_categories`, que son dependencias declaradas); no cruza a otro módulo, porque el **costo ya viene congelado en
 * la línea** (D322): por eso el margen se calcula aquí sin unir a `Costing`.
 *
 * ## El margen es neto y del momento (D320)
 *
 * Venta neta = `line_total ÷ (1 + IVA)` —`line_total` ya trae los descuentos restados y el IVA incluido—. Costo =
 * `unit_cost × cantidad`, con el costo CONGELADO al vender. Margen = utilidad ÷ venta neta. Todo lo suma el servidor; el
 * porcentaje se redondea al presentar (D134). El scoping de tenant y sucursal lo pone el motor, no esta definición.
 */
final class SalesByArticleReport implements ReportDefinition
{
    private const NET = 'pos_order_items.line_total / (1 + pos_order_items.vat_rate/100)';
    private const COST = 'pos_order_items.unit_cost * pos_order_items.quantity';

    public function key(): string
    {
        return 'sales.by_article';
    }

    public function label(): string
    {
        return 'Ventas por artículo';
    }

    public function permission(): string
    {
        return 'finance.journal.view';
    }

    public function baseQuery(): Builder
    {
        return PosOrderItem::query()
            ->join('pos_accounts', 'pos_accounts.id', '=', 'pos_order_items.pos_account_id')
            ->leftJoin('articles', 'articles.id', '=', 'pos_order_items.article_id')
            ->leftJoin('article_categories', 'article_categories.id', '=', 'articles.category_id')
            // Una línea cancelada no se vendió: no cuenta para ventas ni para margen.
            ->where('pos_order_items.status', '!=', 'cancelled');
    }

    public function branchColumn(): string
    {
        return 'pos_accounts.branch_id';
    }

    public function dateColumn(): ?string
    {
        return 'pos_order_items.created_at';
    }

    public function dimensions(): array
    {
        return [
            // El nombre CONGELADO al vender (fiel al momento), no el actual del catálogo.
            'article' => new Dimension('article', 'pos_order_items.article_name', 'Artículo'),
            'category' => new Dimension('category', "COALESCE(article_categories.name, 'Sin categoría')", 'Categoría'),
        ];
    }

    public function measures(): array
    {
        return [
            'units' => new Measure('units', 'SUM(pos_order_items.quantity)', 'Unidades', 'quantity'),
            'net_sales' => new Measure('net_sales', 'ROUND(SUM('.self::NET.'), 2)', 'Venta neta', 'money'),
            'cost' => new Measure('cost', 'ROUND(SUM('.self::COST.'), 2)', 'Costo', 'money'),
            'margin_pct' => new Measure(
                'margin_pct',
                'ROUND((SUM('.self::NET.') - SUM('.self::COST.')) / NULLIF(SUM('.self::NET.'), 0) * 100, 2)',
                'Margen %',
                'percent',
            ),
        ];
    }

    public function filters(): array
    {
        return [
            'sold' => new FilterSpec('sold', 'pos_order_items.created_at', 'date_range', 'date'),
            'branch' => new FilterSpec('branch', 'pos_accounts.branch_id', 'eq', 'ulid', 'branches'),
            'category' => new FilterSpec('category', 'articles.category_id', 'eq', 'ulid', 'article_categories'),
        ];
    }

    public function groupings(): array
    {
        return ['article', 'category'];
    }

    public function defaultGrouping(): array
    {
        return ['article'];
    }
}
