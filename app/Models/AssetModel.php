<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 사업용 자산(자산대장) 모델. 항상 business_id 스코프로 조회한다.
 */
class AssetModel extends Model
{
    protected $table          = 'assets';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'business_id', 'asset_type', 'name', 'acquired_at', 'acquisition_cost',
        'disposed_at', 'disposal_amount', 'depreciation_method', 'useful_life',
        'depreciation_rate', 'accumulated_depreciation',
    ];

    /**
     * @var array<string, string>
     */
    protected $validationRules = [
        'business_id'      => 'required|is_natural_no_zero',
        'name'             => 'required|max_length[200]',
        'asset_type'       => 'required|max_length[50]',
        'acquired_at'      => 'required|valid_date[Y-m-d]',
        'acquisition_cost' => 'required|is_natural',
    ];

    /**
     * 사업장 스코프 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function forBusiness(int $businessId): array
    {
        return $this->where('business_id', $businessId)
            ->orderBy('acquired_at', 'ASC')
            ->findAll();
    }

    /**
     * 사업장 스코프 단건 조회.
     *
     * @return array<string, mixed>|null
     */
    public function findScoped(int $businessId, int $assetId): ?array
    {
        return $this->where('business_id', $businessId)
            ->where('id', $assetId)
            ->first();
    }
}
