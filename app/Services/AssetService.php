<?php

namespace App\Services;

use App\DTOs\AssetData;
use App\DTOs\LedgerData;
use App\Enums\AccountCategory;
use App\Enums\DepreciationMethod;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AccountModel;
use App\Models\AssetModel;
use App\Models\BusinessModel;
use App\Models\DepreciationRateModel;
use App\Models\IndustryCodeModel;
use App\Models\LedgerEntryModel;

/**
 * 사업용 자산(자산대장) 유스케이스.
 * 상각률 자동 조회, 감가상각 스케줄 산출, 감가상각비의 장부 반영을 담당한다.
 */
final class AssetService
{
    private AssetModel $assets;
    private BusinessModel $businesses;
    private DepreciationRateModel $rates;
    private IndustryCodeModel $industryCodes;
    private AccountModel $accounts;
    private LedgerEntryModel $ledgerEntries;
    private DepreciationService $depreciation;
    private LedgerService $ledger;

    public function __construct(
        ?AssetModel $assets = null,
        ?BusinessModel $businesses = null,
        ?DepreciationRateModel $rates = null,
        ?IndustryCodeModel $industryCodes = null,
        ?AccountModel $accounts = null,
        ?LedgerEntryModel $ledgerEntries = null,
        ?DepreciationService $depreciation = null,
        ?LedgerService $ledger = null,
    ) {
        $this->assets        = $assets ?? model(AssetModel::class);
        $this->businesses    = $businesses ?? model(BusinessModel::class);
        $this->rates         = $rates ?? model(DepreciationRateModel::class);
        $this->industryCodes = $industryCodes ?? model(IndustryCodeModel::class);
        $this->accounts      = $accounts ?? model(AccountModel::class);
        $this->ledgerEntries = $ledgerEntries ?? model(LedgerEntryModel::class);
        $this->depreciation  = $depreciation ?? new DepreciationService();
        $this->ledger        = $ledger ?? service('ledgerService');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForBusiness(int $userId, int $businessId): array
    {
        $this->assertOwned($userId, $businessId);

        return $this->assets->forBusiness($businessId);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $businessId, int $assetId): array
    {
        $this->assertOwned($userId, $businessId);

        $asset = $this->assets->findScoped($businessId, $assetId);
        if ($asset === null) {
            throw new NotFoundException('자산을 찾을 수 없습니다.');
        }

        return $asset;
    }

    /**
     * 자산 등록. 상각방법·내용연수가 있으면 상각률을 자동 조회해 저장한다.
     *
     * @return int 생성된 자산 ID
     */
    public function create(int $userId, int $businessId, AssetData $data): int
    {
        $this->assertOwned($userId, $businessId);

        $row                = $data->toDatabaseArray();
        $row['business_id'] = $businessId;
        $row                = $this->resolveDepreciation($businessId, $data, $row);

        if (! $this->assets->insert($row)) {
            throw new ValidationException($this->assets->errors());
        }

        $id    = (int) $this->assets->getInsertID();
        $asset = $this->assets->findScoped($businessId, $id);
        if ($asset !== null) {
            $this->syncLedgerEntries($businessId, $asset);
        }

        return $id;
    }

    /**
     * 자산 수정.
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, int $businessId, int $assetId, AssetData $data): array
    {
        $this->get($userId, $businessId, $assetId);

        $row = $data->toDatabaseArray();
        $row = $this->resolveDepreciation($businessId, $data, $row);

        if (! $this->assets->update($assetId, $row)) {
            throw new ValidationException($this->assets->errors());
        }

        $asset = $this->get($userId, $businessId, $assetId);
        $this->syncLedgerEntries($businessId, $asset);

        return $asset;
    }

    public function delete(int $userId, int $businessId, int $assetId): void
    {
        $this->get($userId, $businessId, $assetId);
        $this->removeLedgerEntries($businessId, $assetId); // 연동 전표(구입/매각/감가상각) 제거
        $this->assets->delete($assetId);
    }

    /**
     * 사업장 주업종코드 기준 기본 내용연수(자산 등록 폼 안내용). 없으면 null.
     *
     * 아직 취득일이 없는 신규 등록 안내이므로 현재 연도에 유효한 기준을 보여준다.
     */
    public function defaultUsefulLife(int $userId, int $businessId): ?int
    {
        $this->assertOwned($userId, $businessId);

        return $this->industryUsefulLife($businessId, (int) date('Y'));
    }

    /**
     * 자산의 연도별 감가상각 스케줄. 상각 정보가 부족하면 빈 배열.
     *
     * @return list<array{year:int, depreciation:int, accumulated:int, book_value:int}>
     */
    public function schedule(int $userId, int $businessId, int $assetId): array
    {
        $asset = $this->get($userId, $businessId, $assetId);

        return $this->scheduleFromAsset($asset);
    }

    /**
     * 특정 귀속연도 감가상각비를 장부에 비용(감가상각비)으로 반영한다.
     * 같은 자산·연도 항목이 있으면 갱신(멱등), 상각비 0이면 아무것도 하지 않고 null.
     *
     * @return int|null 반영된 장부 항목 ID
     */
    public function postDepreciation(int $userId, int $businessId, int $assetId, int $year): ?int
    {
        $asset    = $this->get($userId, $businessId, $assetId);
        $schedule = $this->scheduleFromAsset($asset);

        $amount = 0;

        foreach ($schedule as $line) {
            if ($line['year'] === $year) {
                $amount = $line['depreciation'];
                break;
            }
        }
        if ($amount <= 0) {
            return null;
        }

        $account = $this->accounts->findByCategoryName(AccountCategory::Expense, '감가상각비');
        if ($account === null) {
            throw new ValidationException(['account' => '감가상각비 계정과목이 없습니다.']);
        }

        $description = '감가상각비: ' . $asset['name'];
        $data        = new LedgerData(
            entryDate: sprintf('%04d-12-31', $year),
            entryType: EntryType::Expense,
            description: $description,
            supplyAmount: $amount,
            accountId: (int) $account['id'],
            evidenceType: EvidenceType::Other, // 감가상각비는 부가세 없음
        );

        $existing = $this->ledgerEntries
            ->where('business_id', $businessId)
            ->where('account_id', (int) $account['id'])
            ->where('fiscal_year', $year)
            ->where('description', $description)
            ->first();

        if ($existing !== null) {
            $this->ledger->update($userId, $businessId, (int) $existing['id'], $data);
            $entryId = (int) $existing['id'];
        } else {
            $entryId = $this->ledger->create($userId, $businessId, $data);
        }

        // 자산 연동(삭제 시 함께 정리되도록 asset_id 스탬프)
        $this->ledgerEntries->update($entryId, ['asset_id' => $assetId]);

        return $entryId;
    }

    /**
     * 자산_구입/자산_매각 전표를 장부와 동기화한다(멱등 upsert, 미처분 시 매각 전표 제거).
     *
     * @param array<string, mixed> $asset
     */
    private function syncLedgerEntries(int $businessId, array $asset): void
    {
        $db = db_connect();
        $db->transStart();
        $this->syncPurchaseEntry($businessId, $asset);
        $this->syncDisposalEntry($businessId, $asset);
        $db->transComplete();
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function syncPurchaseEntry(int $businessId, array $asset): void
    {
        $row = $this->assetEntryRow(
            $businessId,
            $asset,
            EntryType::AssetPurchase,
            (string) $asset['acquired_at'],
            (int) $asset['acquisition_cost'],
            '자산구입: ' . $asset['name'],
        );
        $this->upsertAssetEntry($businessId, (int) $asset['id'], EntryType::AssetPurchase, $row);
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function syncDisposalEntry(int $businessId, array $asset): void
    {
        $existing = $this->findAssetEntry($businessId, (int) $asset['id'], EntryType::AssetDisposal);

        // 처분 정보가 없으면 기존 매각 전표 제거
        if ($asset['disposed_at'] === null || $asset['disposal_amount'] === null) {
            if ($existing !== null) {
                $this->ledgerEntries->delete((int) $existing['id']);
            }

            return;
        }

        $row = $this->assetEntryRow(
            $businessId,
            $asset,
            EntryType::AssetDisposal,
            (string) $asset['disposed_at'],
            -1 * (int) $asset['disposal_amount'], // 매각은 공급가액 음수로 기록(원본 규칙)
            '자산처분: ' . $asset['name'],
        );

        if ($existing !== null) {
            $this->ledgerEntries->update((int) $existing['id'], $row);
        } else {
            $this->ledgerEntries->insert($row);
        }
    }

    /**
     * 자산 전표 행을 구성한다(부가세 없음, 자산종류=계정과목).
     *
     * @param array<string, mixed> $asset
     *
     * @return array<string, mixed>
     */
    private function assetEntryRow(int $businessId, array $asset, EntryType $type, string $date, int $amount, string $description): array
    {
        $account = $this->accounts->findByCategoryName(AccountCategory::Asset, (string) $asset['asset_type']);

        return [
            'business_id'   => $businessId,
            'asset_id'      => (int) $asset['id'],
            'fiscal_year'   => (int) substr($date, 0, 4),
            'entry_date'    => $date,
            'entry_type'    => $type->value,
            'account_id'    => $account !== null ? (int) $account['id'] : null,
            'partner_id'    => null,
            'description'   => $description,
            'supply_amount' => $amount,
            'vat'           => 0,
            'evidence_type' => EvidenceType::Other->value,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertAssetEntry(int $businessId, int $assetId, EntryType $type, array $row): void
    {
        $existing = $this->findAssetEntry($businessId, $assetId, $type);
        if ($existing !== null) {
            $this->ledgerEntries->update((int) $existing['id'], $row);
        } else {
            $this->ledgerEntries->insert($row);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAssetEntry(int $businessId, int $assetId, EntryType $type): ?array
    {
        return $this->ledgerEntries
            ->where('business_id', $businessId)
            ->where('asset_id', $assetId)
            ->where('entry_type', $type->value)
            ->first();
    }

    /**
     * 자산에 연동된 모든 장부 전표(구입/매각/감가상각) 소프트 삭제.
     */
    private function removeLedgerEntries(int $businessId, int $assetId): void
    {
        $rows = $this->ledgerEntries
            ->where('business_id', $businessId)
            ->where('asset_id', $assetId)
            ->findAll();

        foreach ($rows as $r) {
            $this->ledgerEntries->delete((int) $r['id']);
        }
    }

    /**
     * 자산 행으로부터 감가상각 스케줄을 만든다.
     *
     * @param array<string, mixed> $asset
     *
     * @return list<array{year:int, depreciation:int, accumulated:int, book_value:int}>
     */
    private function scheduleFromAsset(array $asset): array
    {
        $method = DepreciationMethod::tryFrom((string) ($asset['depreciation_method'] ?? ''));
        $rate   = $asset['depreciation_rate'] === null ? 0.0 : (float) $asset['depreciation_rate'];

        if ($method === null || $rate <= 0.0) {
            return [];
        }

        $acquiredAt = (string) $asset['acquired_at'];
        $disposedAt = $asset['disposed_at'] !== null ? (string) $asset['disposed_at'] : null;

        return $this->depreciation->generateSchedule(
            $method,
            (int) $asset['acquisition_cost'],
            $rate,
            (int) substr($acquiredAt, 0, 4),
            (int) substr($acquiredAt, 5, 2),
            $disposedAt !== null ? (int) substr($disposedAt, 0, 4) : null,
            $disposedAt !== null ? (int) substr($disposedAt, 5, 2) : null,
            (int) ($asset['accumulated_depreciation'] ?? 0),
        );
    }

    /**
     * 저장용 행에 유효 내용연수와 상각률을 채운다.
     *
     * 내용연수는 입력값을 우선하고, 없으면 사업장 주업종코드로 자동 조회한다
     * (원본 fGet내용연수: 표준산업분류연계표에서 업종별 자산 내용연수 조회).
     * 상각방법·내용연수가 모두 확정돼야 상각률을 조회한다.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function resolveDepreciation(int $businessId, AssetData $data, array $row): array
    {
        // 내용연수·상각률은 취득연도에 유효한 세법 기준으로 스냅샷한다(과거 재현 보장).
        $acquiredYear = (int) substr($data->acquiredAt, 0, 4);
        $usefulLife   = $data->usefulLife ?? $this->industryUsefulLife($businessId, $acquiredYear);

        $rate = null;
        if ($data->depreciationMethod !== null && $usefulLife !== null) {
            $rate = $this->rates->rateFor($data->depreciationMethod, $usefulLife, $acquiredYear);
        }

        $row['useful_life']       = $usefulLife;
        $row['depreciation_rate'] = $rate;

        return $row;
    }

    /**
     * 사업장 주업종코드로 취득연도에 유효한 업종별 자산 내용연수를 조회한다(없으면 null).
     */
    private function industryUsefulLife(int $businessId, int $fiscalYear): ?int
    {
        $business = $this->businesses->find($businessId);
        $code     = $business['industry_code'] ?? null;
        if ($code === null || $code === '') {
            return null;
        }

        return $this->industryCodes->usefulLifeFor((string) $code, $fiscalYear);
    }

    private function assertOwned(int $userId, int $businessId): void
    {
        if ($this->businesses->findOwned($userId, $businessId) === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
    }
}
