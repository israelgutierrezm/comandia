<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Domain\Consumption\ConsumptionEntry;
use App\Modules\Shared\Domain\Contracts\ConsumptionHistoryProvider;

/**
 * Los consumos de un cliente, leídos de `pos_accounts` (§6.6).
 *
 * `Pos` responde esta pregunta que le hace `Customers` por el kernel, porque `Pos` ya depende de `Customers` y la
 * consulta al revés cerraría un ciclo — el mismo motivo de `PosLiveServiceProbe` y `PosCashSessionProbe`.
 *
 * El tenant lo impone el global scope de `PosAccount`; aquí sólo se filtra por cliente. Se devuelven primitivos
 * (`ConsumptionEntry`): `Customers` nunca ve un modelo de `Pos`.
 */
final readonly class PosConsumptionHistory implements ConsumptionHistoryProvider
{
    public function forCustomer(int $customerId, int $limit): array
    {
        return PosAccount::query()
            ->where('customer_id', $customerId)
            ->with('branch:id,name,timezone')
            // La cuenta se fecha por cuándo terminó: se pagó, o se cerró, o —si sigue viva— cuándo se abrió. El COALESCE
            // ordena por esa fecha efectiva sin depender de una columna que puede estar en null.
            ->orderByRaw('COALESCE(paid_at, closed_at, opened_at) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (PosAccount $account): ConsumptionEntry => new ConsumptionEntry(
                accountUlid: (string) $account->ulid,
                reference: $account->folio !== null ? $account->folioNumber() : ($account->label ?? 'Cuenta'),
                branchName: (string) ($account->branch?->name ?? ''),
                branchTimezone: (string) ($account->branch?->timezone ?? 'UTC'),
                occurredAt: (string) ($account->paid_at ?? $account->closed_at ?? $account->opened_at)?->toIso8601String(),
                total: (string) $account->total,
                status: $account->status->value,
            ))
            ->values()
            ->all();
    }
}
