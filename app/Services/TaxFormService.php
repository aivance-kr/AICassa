<?php

namespace App\Services;

use App\DTOs\InventoryData;
use App\Enums\EntryType;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Models\InventoryModel;
use Config\TaxForm as TaxFormConfig;

/**
 * 종합소득세 신고서식 데이터 생성.
 *  - 간편장부 소득금액계산서 / 총수입금액 및 필요경비명세서
 *  - 감가상각비 조정명세서
 *
 * 계산식(docs/간편장부_계산식명세.md):
 *  - 매출원가 = 기초재고 + 당기매입 − 기말재고 (상품/재료 각각)
 *  - 소득금액 = 총수입금액 − 필요경비
 */
final class TaxFormService
{
    private BusinessModel $businesses;
    private InventoryModel $inventories;
    private LedgerService $ledger;
    private AssetService $assets;

    public function __construct(
        ?BusinessModel $businesses = null,
        ?InventoryModel $inventories = null,
        ?LedgerService $ledger = null,
        ?AssetService $assets = null,
    ) {
        $this->businesses  = $businesses ?? model(BusinessModel::class);
        $this->inventories = $inventories ?? model(InventoryModel::class);
        $this->ledger      = $ledger ?? service('ledgerService');
        $this->assets      = $assets ?? service('assetService');
    }

    /**
     * 귀속연도에 해당하는 서식 버전 정보.
     *
     * @return array{year:int, version:string, supported:bool}
     */
    public function formVersion(int $year): array
    {
        $config = config(TaxFormConfig::class);

        return [
            'year'      => $year,
            'version'   => $config->versions[$year] ?? $config->latest,
            'supported' => $year >= $config->earliestSupportedYear,
        ];
    }

    /**
     * 사업장·연도 재고(없으면 0).
     *
     * @return array{goods_begin:int, goods_end:int, materials_begin:int, materials_end:int}
     */
    public function inventory(int $userId, int $businessId, int $year): array
    {
        $this->assertOwned($userId, $businessId);
        $row = $this->inventories->findForYear($businessId, $year);

        return [
            'goods_begin'     => (int) ($row['goods_begin'] ?? 0),
            'goods_end'       => (int) ($row['goods_end'] ?? 0),
            'materials_begin' => (int) ($row['materials_begin'] ?? 0),
            'materials_end'   => (int) ($row['materials_end'] ?? 0),
        ];
    }

    /**
     * 재고 저장(사업장·연도별 upsert).
     */
    public function saveInventory(int $userId, int $businessId, int $year, InventoryData $data): void
    {
        $this->assertOwned($userId, $businessId);

        $existing = $this->inventories->findForYear($businessId, $year);
        $row      = $data->toDatabaseArray();

        if ($existing !== null) {
            $this->inventories->update((int) $existing['id'], $row);

            return;
        }

        $row['business_id'] = $businessId;
        $row['fiscal_year'] = $year;
        $this->inventories->insert($row);
    }

    /**
     * 간편장부 소득금액계산서 데이터.
     *
     * @return array{
     *     year:int,
     *     revenue_by_account: array<string, int>,
     *     total_revenue:int,
     *     expense_by_account: array<string, int>,
     *     inventory: array{goods_begin:int, goods_end:int, materials_begin:int, materials_end:int},
     *     goods_cogs:int,
     *     materials_cost:int,
     *     necessary_expense:int,
     *     income_amount:int
     * }
     */
    public function incomeStatement(int $userId, int $businessId, int $year): array
    {
        // listForBusiness 가 소유권을 검증한다.
        $entries = $this->ledger->listForBusiness($userId, $businessId, ['fiscal_year' => $year]);

        $revenueByAccount = [];
        $expenseByAccount = [];
        $totalRevenue     = 0;
        $totalExpenseRaw  = 0;

        foreach ($entries as $e) {
            $amount = (int) $e['supply_amount'];
            $name   = ($e['account_name'] ?? '') !== '' ? (string) $e['account_name'] : '(미지정)';

            if ($e['entry_type'] === EntryType::Income->value) {
                $revenueByAccount[$name] = ($revenueByAccount[$name] ?? 0) + $amount;
                $totalRevenue += $amount;
            } elseif ($e['entry_type'] === EntryType::Expense->value) {
                $expenseByAccount[$name] = ($expenseByAccount[$name] ?? 0) + $amount;
                $totalExpenseRaw += $amount;
            }
        }

        $inv               = $this->inventory($userId, $businessId, $year);
        $goodsPurchase     = $expenseByAccount['상품매입'] ?? 0;
        $materialsPurchase = $expenseByAccount['재료매입'] ?? 0;

        $goodsCogs     = $inv['goods_begin'] + $goodsPurchase - $inv['goods_end'];
        $materialsCost = $inv['materials_begin'] + $materialsPurchase - $inv['materials_end'];

        // 필요경비 = Σ비용 − (상품매입+재료매입) + (매출원가+재료비)
        //          = Σ비용 + (기초−기말)상품 + (기초−기말)재료
        $necessaryExpense = $totalExpenseRaw - $goodsPurchase - $materialsPurchase + $goodsCogs + $materialsCost;

        return [
            'year'               => $year,
            'revenue_by_account' => $revenueByAccount,
            'total_revenue'      => $totalRevenue,
            'expense_by_account' => $expenseByAccount,
            'inventory'          => $inv,
            'goods_cogs'         => $goodsCogs,
            'materials_cost'     => $materialsCost,
            'necessary_expense'  => $necessaryExpense,
            'income_amount'      => $totalRevenue - $necessaryExpense,
        ];
    }

    /**
     * 감가상각비 조정명세서 — 자산별 해당 연도 감가상각 내역.
     *
     * @return list<array{name:string, method:string|null, acquisition_cost:int, depreciation:int, accumulated:int, book_value:int}>
     */
    public function depreciationAdjustment(int $userId, int $businessId, int $year): array
    {
        $assets = $this->assets->listForBusiness($userId, $businessId);
        $lines  = [];

        foreach ($assets as $asset) {
            $schedule = $this->assets->schedule($userId, $businessId, (int) $asset['id']);
            if ($schedule === []) {
                continue;
            }

            foreach ($schedule as $line) {
                if ($line['year'] !== $year) {
                    continue;
                }
                $lines[] = [
                    'name'             => (string) $asset['name'],
                    'method'           => $asset['depreciation_method'] !== null ? (string) $asset['depreciation_method'] : null,
                    'acquisition_cost' => (int) $asset['acquisition_cost'],
                    'depreciation'     => $line['depreciation'],
                    'accumulated'      => $line['accumulated'],
                    'book_value'       => $line['book_value'],
                ];
                break;
            }
        }

        return $lines;
    }

    private function assertOwned(int $userId, int $businessId): void
    {
        if ($this->businesses->findOwned($userId, $businessId) === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
    }
}
