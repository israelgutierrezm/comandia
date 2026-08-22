<?php

declare(strict_types=1);

namespace App\Modules\Pos\Reporting;

use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Shared\Domain\Reporting\Dimension;
use App\Modules\Shared\Domain\Reporting\FilterSpec;
use App\Modules\Shared\Domain\Reporting\Measure;
use App\Modules\Shared\Domain\Reporting\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antifraude / «robo hormiga»: descuentos y cortesías MANUALES por quién los autorizó (§9, D264, D312).
 *
 * La pregunta que este reporte contesta es «¿quién está regalando dinero?». Por eso agrupa por el AUTORIZADOR —quien
 * puso su PIN—, no por quien lo aplicó, y sólo mira los descuentos `source = manual`: las promociones (`source =
 * promotion`) las aplicó una regla, no una persona, y viven en su propio reporte (D313). `pos_discounts` es «zona de
 * máxima auditoría» (§6.3), y su índice `(tenant, authorized_by, created_at)` existe justo para esta consulta.
 *
 * La declara `Pos` (dueño de `pos_discounts`). El nombre del autorizador sale por join a `tenant_memberships → users`,
 * ambos dependencias declaradas de `Pos`.
 */
final class AntifraudDiscountsReport implements ReportDefinition
{
    public function key(): string
    {
        return 'antifraud.discounts';
    }

    public function label(): string
    {
        return 'Antifraude: descuentos y cortesías';
    }

    public function permission(): string
    {
        return 'audit.entries.view';
    }

    public function baseQuery(): Builder
    {
        return PosDiscount::query()
            ->join('pos_accounts', 'pos_accounts.id', '=', 'pos_discounts.pos_account_id')
            ->join('tenant_memberships', 'tenant_memberships.id', '=', 'pos_discounts.authorized_by_membership_id')
            ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            // Sólo lo que autorizó una PERSONA con su PIN. La promoción la aplicó una regla y no es robo hormiga.
            ->where('pos_discounts.source', 'manual');
    }

    public function branchColumn(): string
    {
        return 'pos_accounts.branch_id';
    }

    public function dateColumn(): ?string
    {
        return 'pos_discounts.created_at';
    }

    public function dimensions(): array
    {
        return [
            'authorizer' => new Dimension('authorizer', "CONCAT(users.first_name, ' ', users.paternal_surname)", 'Autorizó'),
            'kind' => new Dimension('kind', 'pos_discounts.kind', 'Tipo'),
        ];
    }

    public function measures(): array
    {
        return [
            'amount' => new Measure('amount', 'ROUND(SUM(pos_discounts.resulting_amount), 2)', 'Monto descontado', 'money'),
            'times' => new Measure('times', 'COUNT(*)', 'Veces', 'integer'),
        ];
    }

    public function filters(): array
    {
        return [
            'applied' => new FilterSpec('applied', 'pos_discounts.created_at', 'date_range', 'date'),
            'branch' => new FilterSpec('branch', 'pos_accounts.branch_id', 'eq', 'ulid', 'branches'),
        ];
    }

    public function groupings(): array
    {
        return ['authorizer', 'kind'];
    }

    public function defaultGrouping(): array
    {
        return ['authorizer'];
    }
}
