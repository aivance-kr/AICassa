<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 장부(거래) 모델. 항상 business_id 스코프로 조회한다.
 */
class LedgerEntryModel extends Model
{
    protected $table          = 'ledger_entries';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'business_id', 'fiscal_year', 'entry_date', 'entry_type',
        'account_id', 'partner_id', 'asset_id', 'description', 'supply_amount',
        'vat', 'evidence_type', 'receipt_path',
    ];

    /**
     * @var array<string, string>
     */
    protected $validationRules = [
        'business_id'   => 'required|is_natural_no_zero',
        'entry_date'    => 'required|valid_date[Y-m-d]',
        'entry_type'    => 'required|in_list[income,expense,asset_purchase,asset_disposal]',
        'description'   => 'required|max_length[255]',
        'supply_amount' => 'required|integer',
    ];

    /**
     * 사업장 스코프 단건 조회.
     *
     * @return array<string, mixed>|null
     */
    public function findScoped(int $businessId, int $entryId): ?array
    {
        return $this->where('business_id', $businessId)
            ->where('id', $entryId)
            ->first();
    }

    /**
     * 필터 조건으로 장부를 조회한다.
     *
     * @param array<string, mixed> $filters fiscal_year, entry_type, date_from, date_to, partner_id
     *
     * @return list<array<string, mixed>>
     */
    public function filtered(int $businessId, array $filters = []): array
    {
        $builder = $this->where('business_id', $businessId);

        if (! empty($filters['fiscal_year'])) {
            $builder->where('fiscal_year', (int) $filters['fiscal_year']);
        }
        if (! empty($filters['entry_type'])) {
            $builder->where('entry_type', (string) $filters['entry_type']);
        }
        if (! empty($filters['date_from'])) {
            $builder->where('entry_date >=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $builder->where('entry_date <=', (string) $filters['date_to']);
        }
        if (! empty($filters['partner_id'])) {
            $builder->where('partner_id', (int) $filters['partner_id']);
        }

        return $builder->orderBy('entry_date', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /**
     * 과거 거래에서 계정과목 사용 빈도를 집계한다(자동분류 이력 근거).
     * account_id 가 채워진 확정 거래만 대상으로 하며, 조건이 좁을수록 신뢰도가 높다.
     *
     * @param string      $entryType   income|expense
     * @param string|null $description 정확일치 거래내용(주면 해당 내용과 동일한 건만)
     * @param int|null    $partnerId   거래처(주면 해당 거래처 건만)
     *
     * @return array<int, int> account_id => 건수 (내림차순)
     */
    public function accountUsage(int $businessId, string $entryType, ?string $description = null, ?int $partnerId = null): array
    {
        $builder = $this->builder()
            ->select('account_id, COUNT(*) AS cnt')
            ->where('business_id', $businessId)
            ->where('entry_type', $entryType)
            ->where('account_id IS NOT NULL')
            ->where('deleted_at', null);

        if ($description !== null && $description !== '') {
            $builder->where('description', $description);
        }
        if ($partnerId !== null) {
            $builder->where('partner_id', $partnerId);
        }

        $rows = $builder->groupBy('account_id')
            ->orderBy('cnt', 'DESC')
            ->orderBy('account_id', 'ASC')
            ->get()
            ->getResultArray();

        $usage = [];

        foreach ($rows as $row) {
            $usage[(int) $row['account_id']] = (int) $row['cnt'];
        }

        return $usage;
    }

    /**
     * AI 폴백 few-shot 용 최근 분류 표본(거래내용 → 계정과목 id).
     * account_id 가 채워진 최신 거래부터 반환한다.
     *
     * @return list<array{description: string, account_id: int}>
     */
    public function recentClassified(int $businessId, string $entryType, int $limit): array
    {
        $rows = $this->builder()
            ->select('description, account_id')
            ->where('business_id', $businessId)
            ->where('entry_type', $entryType)
            ->where('account_id IS NOT NULL')
            ->where("description != ''")
            ->where('deleted_at', null)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map(
            static fn (array $r): array => [
                'description' => (string) $r['description'],
                'account_id'  => (int) $r['account_id'],
            ],
            $rows,
        );
    }

    /**
     * 사업장에 장부가 기록된 귀속연도 목록(내림차순).
     *
     * @return list<int>
     */
    public function fiscalYears(int $businessId): array
    {
        $rows = $this->builder()
            ->select('fiscal_year')
            ->where('business_id', $businessId)
            ->where('deleted_at', null)
            ->distinct()
            ->orderBy('fiscal_year', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['fiscal_year'], $rows);
    }
}
