<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 거래처 모델. 항상 business_id 스코프로 조회한다.
 */
class PartnerModel extends Model
{
    protected $table          = 'partners';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    /** @var list<string> */
    protected $allowedFields = ['business_id', 'name', 'biz_reg_no', 'phone'];

    /** @var array<string, string> */
    protected $validationRules = [
        'business_id' => 'required|is_natural_no_zero',
        'name'        => 'required|max_length[200]',
        'biz_reg_no'  => 'permit_empty|max_length[20]',
    ];

    /**
     * 사업장 스코프 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function forBusiness(int $businessId): array
    {
        return $this->where('business_id', $businessId)
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /**
     * 사업장 스코프 단건 조회.
     *
     * @return array<string, mixed>|null
     */
    public function findScoped(int $businessId, int $partnerId): ?array
    {
        return $this->where('business_id', $businessId)
            ->where('id', $partnerId)
            ->first();
    }

    /**
     * 사업장 내 동일 사업자등록번호 거래처 존재 여부(중복 방지).
     * $excludeId 로 자기 자신은 제외(수정 시).
     */
    public function bizRegNoExists(int $businessId, string $bizRegNo, ?int $excludeId = null): bool
    {
        $builder = $this->where('business_id', $businessId)
            ->where('biz_reg_no', $bizRegNo);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }
}
