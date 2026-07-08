<?php

namespace App\Services;

use App\DTOs\LedgerData;
use App\Enums\AccountCategory;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Models\LedgerEntryModel;
use App\Models\PartnerModel;

/**
 * 장부(거래) 유스케이스.
 * 부가세 자동계산·귀속연도 파생, 사업장 소유권 및 계정/거래처 참조 무결성을 담당한다.
 */
final class LedgerService
{
    private LedgerEntryModel $ledger;
    private BusinessModel $businesses;
    private AccountModel $accounts;
    private PartnerModel $partners;
    private VatCalculatorService $vat;

    public function __construct(
        ?LedgerEntryModel $ledger = null,
        ?BusinessModel $businesses = null,
        ?AccountModel $accounts = null,
        ?PartnerModel $partners = null,
        ?VatCalculatorService $vat = null,
    ) {
        $this->ledger     = $ledger ?? model(LedgerEntryModel::class);
        $this->businesses = $businesses ?? model(BusinessModel::class);
        $this->accounts   = $accounts ?? model(AccountModel::class);
        $this->partners   = $partners ?? model(PartnerModel::class);
        $this->vat        = $vat ?? new VatCalculatorService();
    }

    /**
     * 필터 조건 장부 목록(계정·거래처명, 라벨 부가). 사업장 소유권 검증.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function listForBusiness(int $userId, int $businessId, array $filters = []): array
    {
        $this->assertOwned($userId, $businessId);

        $entries     = $this->ledger->filtered($businessId, $filters);
        $accountMap  = $this->accounts->nameMap();
        $partnerMap  = $this->partnerNameMap($businessId);

        return array_map(static function (array $row) use ($accountMap, $partnerMap): array {
            $accountId              = $row['account_id'] === null ? null : (int) $row['account_id'];
            $partnerId              = $row['partner_id'] === null ? null : (int) $row['partner_id'];
            $row['account_name']    = $accountId !== null ? ($accountMap[$accountId] ?? '') : '';
            $row['partner_name']    = $partnerId !== null ? ($partnerMap[$partnerId] ?? '') : '';
            $row['entry_type_label'] = (EntryType::tryFrom((string) $row['entry_type']) ?? EntryType::Expense)->label();
            $evidence               = EvidenceType::tryFrom((string) ($row['evidence_type'] ?? ''));
            $row['evidence_label']  = $evidence?->label() ?? '';

            return $row;
        }, $entries);
    }

    /**
     * 장부 목록 합계(수입·비용 금액/부가세).
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return array{income:int, income_vat:int, expense:int, expense_vat:int}
     */
    public function summarize(array $entries): array
    {
        $sum = ['income' => 0, 'income_vat' => 0, 'expense' => 0, 'expense_vat' => 0];

        foreach ($entries as $row) {
            $amount = (int) $row['supply_amount'];
            $vat    = (int) $row['vat'];
            if ($row['entry_type'] === EntryType::Income->value) {
                $sum['income'] += $amount;
                $sum['income_vat'] += $vat;
            } elseif ($row['entry_type'] === EntryType::Expense->value) {
                $sum['expense'] += $amount;
                $sum['expense_vat'] += $vat;
            }
        }

        return $sum;
    }

    /**
     * 단건 조회.
     *
     * @return array<string, mixed>
     */
    public function get(int $userId, int $businessId, int $entryId): array
    {
        $this->assertOwned($userId, $businessId);

        $entry = $this->ledger->findScoped($businessId, $entryId);
        if ($entry === null) {
            throw new NotFoundException('장부 항목을 찾을 수 없습니다.');
        }

        return $entry;
    }

    /**
     * 장부 등록.
     *
     * @return int 생성된 항목 ID
     */
    public function create(int $userId, int $businessId, LedgerData $data): int
    {
        $this->assertOwned($userId, $businessId);
        $this->validateReferences($businessId, $data);

        if (! $this->ledger->insert($this->toRow($businessId, $data))) {
            throw new ValidationException($this->ledger->errors());
        }

        return (int) $this->ledger->getInsertID();
    }

    /**
     * 장부 수정.
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, int $businessId, int $entryId, LedgerData $data): array
    {
        $this->get($userId, $businessId, $entryId); // 존재·소유권 검증
        $this->validateReferences($businessId, $data);

        if (! $this->ledger->update($entryId, $this->toRow($businessId, $data))) {
            throw new ValidationException($this->ledger->errors());
        }

        return $this->get($userId, $businessId, $entryId);
    }

    /**
     * 장부 삭제(소프트).
     */
    public function delete(int $userId, int $businessId, int $entryId): void
    {
        $this->get($userId, $businessId, $entryId);
        $this->ledger->delete($entryId);
    }

    /**
     * 복사 입력 — 기존 항목을 복제해 새 항목을 만든다(반복 거래용).
     *
     * @return int 복제된 항목 ID
     */
    public function copy(int $userId, int $businessId, int $entryId): int
    {
        $src = $this->get($userId, $businessId, $entryId);

        $row = [
            'business_id'   => $businessId,
            'fiscal_year'   => (int) $src['fiscal_year'],
            'entry_date'    => $src['entry_date'],
            'entry_type'    => $src['entry_type'],
            'account_id'    => $src['account_id'],
            'partner_id'    => $src['partner_id'],
            'description'   => $src['description'],
            'supply_amount' => (int) $src['supply_amount'],
            'vat'           => (int) $src['vat'],
            'evidence_type' => $src['evidence_type'],
        ];

        if (! $this->ledger->insert($row)) {
            throw new ValidationException($this->ledger->errors());
        }

        return (int) $this->ledger->getInsertID();
    }

    /**
     * 사업장에 기록된 귀속연도 목록.
     *
     * @return list<int>
     */
    public function availableYears(int $userId, int $businessId): array
    {
        $this->assertOwned($userId, $businessId);

        return $this->ledger->fiscalYears($businessId);
    }

    /**
     * DTO → DB 행. 부가세 자동계산·귀속연도 파생 적용.
     *
     * @return array<string, mixed>
     */
    private function toRow(int $businessId, LedgerData $data): array
    {
        $vat = $this->vat->calculate($data->supplyAmount, $data->evidenceType ?? EvidenceType::Other);

        return [
            'business_id'   => $businessId,
            'fiscal_year'   => (int) substr($data->entryDate, 0, 4),
            'entry_date'    => $data->entryDate,
            'entry_type'    => $data->entryType->value,
            'account_id'    => $data->accountId,
            'partner_id'    => $data->partnerId,
            'description'   => $data->description,
            'supply_amount' => $data->supplyAmount,
            'vat'           => $vat,
            'evidence_type' => $data->evidenceType?->value,
        ];
    }

    /**
     * 계정과목·거래처 참조 무결성 검증.
     */
    private function validateReferences(int $businessId, LedgerData $data): void
    {
        if ($data->accountId !== null) {
            $account = $this->accounts->find($data->accountId);
            if ($account === null) {
                throw new ValidationException(['account_id' => '존재하지 않는 계정과목입니다.']);
            }
            if ($account['category'] !== $this->categoryFor($data->entryType)->value) {
                throw new ValidationException(['account_id' => '거래 구분과 계정과목 분류가 일치하지 않습니다.']);
            }
        }

        if ($data->partnerId !== null && $this->partners->findScoped($businessId, $data->partnerId) === null) {
            throw new ValidationException(['partner_id' => '해당 사업장의 거래처가 아닙니다.']);
        }
    }

    private function categoryFor(EntryType $type): AccountCategory
    {
        return match ($type) {
            EntryType::Income                             => AccountCategory::Income,
            EntryType::Expense                            => AccountCategory::Expense,
            EntryType::AssetPurchase, EntryType::AssetDisposal => AccountCategory::Asset,
        };
    }

    /**
     * @return array<int, string>
     */
    private function partnerNameMap(int $businessId): array
    {
        $map = [];
        foreach ($this->partners->forBusiness($businessId) as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }

        return $map;
    }

    private function assertOwned(int $userId, int $businessId): void
    {
        if ($this->businesses->findOwned($userId, $businessId) === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
    }
}
