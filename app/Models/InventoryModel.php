<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 재고(매출원가 계산용) 모델. 사업장·귀속연도별 1건.
 */
class InventoryModel extends Model
{
    protected $table         = 'inventories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'business_id', 'fiscal_year',
        'goods_begin', 'goods_end', 'materials_begin', 'materials_end',
    ];

    /**
     * 사업장·연도 재고 단건 조회.
     *
     * @return array<string, mixed>|null
     */
    public function findForYear(int $businessId, int $fiscalYear): ?array
    {
        return $this->where('business_id', $businessId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }
}
