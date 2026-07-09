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
    private AccountModel $accounts;
    private LedgerEntryModel $ledgerEntries;
    private DepreciationService $depreciation;
    private LedgerService $ledger;

    public function __construct(
        ?AssetModel $assets = null,
        ?BusinessModel $businesses = null,
        ?DepreciationRateModel $rates = null,
        ?AccountModel $accounts = null,
        ?LedgerEntryModel $ledgerEntries = null,
        ?DepreciationService $depreciation = null,
        ?LedgerService $ledger = null,
    ) {
        $this->assets        = $assets ?? model(AssetModel::class);
        $this->businesses    = $businesses ?? model(BusinessModel::class);
        $this->rates         = $rates ?? model(DepreciationRateModel::class);
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

        $row                      = $data->toDatabaseArray();
        $row['business_id']       = $businessId;
        $row['depreciation_rate'] = $this->resolveRate($data);

        if (! $this->assets->insert($row)) {
            throw new ValidationException($this->assets->errors());
        }

        return (int) $this->assets->getInsertID();
    }

    /**
     * 자산 수정.
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, int $businessId, int $assetId, AssetData $data): array
    {
        $this->get($userId, $businessId, $assetId);

        $row                      = $data->toDatabaseArray();
        $row['depreciation_rate'] = $this->resolveRate($data);

        if (! $this->assets->update($assetId, $row)) {
            throw new ValidationException($this->assets->errors());
        }

        return $this->get($userId, $businessId, $assetId);
    }

    public function delete(int $userId, int $businessId, int $assetId): void
    {
        $this->get($userId, $businessId, $assetId);
        $this->assets->delete($assetId);
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

            return (int) $existing['id'];
        }

        return $this->ledger->create($userId, $businessId, $data);
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
     * 상각방법 + 내용연수로 상각률을 조회한다(둘 중 하나라도 없으면 null).
     */
    private function resolveRate(AssetData $data): ?float
    {
        if ($data->depreciationMethod === null || $data->usefulLife === null) {
            return null;
        }

        return $this->rates->rateFor($data->depreciationMethod, $data->usefulLife);
    }

    private function assertOwned(int $userId, int $businessId): void
    {
        if ($this->businesses->findOwned($userId, $businessId) === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
    }
}
