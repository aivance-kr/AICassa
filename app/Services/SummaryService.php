<?php

namespace App\Services;

use App\Enums\EntryType;

/**
 * 영업현황표 — 사업장·귀속연도별 월/분기/합계 집계.
 * 원본 프로그램의 「통계」·「분석」 시트에 대응한다.
 */
final class SummaryService
{
    private LedgerService $ledger;

    public function __construct(?LedgerService $ledger = null)
    {
        $this->ledger = $ledger ?? service('ledgerService');
    }

    /**
     * 월별·분기별·연간 집계표.
     *
     * @return array{
     *     year: int,
     *     months: array<int, array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int}>,
     *     quarters: array<int, array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int}>,
     *     total: array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int}
     * }
     */
    public function monthlyStatement(int $userId, int $businessId, int $fiscalYear): array
    {
        // listForBusiness 가 사업장 소유권을 검증한다.
        $entries = $this->ledger->listForBusiness($userId, $businessId, ['fiscal_year' => $fiscalYear]);

        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = $this->emptyBucket();
        }

        foreach ($entries as $entry) {
            $month = (int) substr((string) $entry['entry_date'], 5, 2);
            if ($month < 1 || $month > 12) {
                continue;
            }
            $this->accumulate($months[$month], $entry);
        }

        $quarters = [];

        for ($q = 1; $q <= 4; $q++) {
            $bucket = $this->emptyBucket();

            for ($m = ($q - 1) * 3 + 1; $m <= $q * 3; $m++) {
                $this->mergeBucket($bucket, $months[$m]);
            }
            $quarters[$q] = $bucket;
        }

        $total = $this->emptyBucket();

        foreach ($quarters as $bucket) {
            $this->mergeBucket($total, $bucket);
        }

        return [
            'year'     => $fiscalYear,
            'months'   => $months,
            'quarters' => $quarters,
            'total'    => $total,
        ];
    }

    /**
     * @return array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int}
     */
    private function emptyBucket(): array
    {
        return [
            'income'      => 0,
            'income_vat'  => 0,
            'expense'     => 0,
            'expense_vat' => 0,
            'asset'       => 0,
            'asset_vat'   => 0,
        ];
    }

    /**
     * 한 거래를 월 버킷에 누적한다.
     *
     * @param array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int} $bucket
     * @param array<string, mixed>                                                                      $entry
     */
    private function accumulate(array &$bucket, array $entry): void
    {
        $amount = (int) $entry['supply_amount'];
        $vat    = (int) $entry['vat'];

        switch ((string) $entry['entry_type']) {
            case EntryType::Income->value:
                $bucket['income'] += $amount;
                $bucket['income_vat'] += $vat;
                break;

            case EntryType::Expense->value:
                $bucket['expense'] += $amount;
                $bucket['expense_vat'] += $vat;
                break;

            case EntryType::AssetPurchase->value:
            case EntryType::AssetDisposal->value:
                $bucket['asset'] += $amount;
                $bucket['asset_vat'] += $vat;
                break;
        }
    }

    /**
     * @param array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int} $target
     * @param array{income:int, income_vat:int, expense:int, expense_vat:int, asset:int, asset_vat:int} $source
     */
    private function mergeBucket(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            $target[$key] += $value;
        }
    }
}
