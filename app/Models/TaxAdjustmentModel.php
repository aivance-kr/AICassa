<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 세무조정 모델. 사업장·귀속연도별 1건.
 */
class TaxAdjustmentModel extends Model
{
    protected $table         = 'tax_adjustments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'business_id', 'fiscal_year',
        'revenue_exclude', 'revenue_add', 'expense_exclude', 'expense_add',
        'donation_over', 'donation_carryover',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function findForYear(int $businessId, int $fiscalYear): ?array
    {
        return $this->where('business_id', $businessId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }
}
