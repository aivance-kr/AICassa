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
        'account_id', 'partner_id', 'description', 'supply_amount',
        'vat', 'evidence_type',
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
