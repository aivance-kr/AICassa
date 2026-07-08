<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 사업장 모델. 항상 user_id 스코프로 조회한다.
 */
class BusinessModel extends Model
{
    protected $table          = 'businesses';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    /** @var list<string> */
    protected $allowedFields = [
        'user_id', 'name', 'owner_name', 'birth_date', 'biz_reg_no',
        'address', 'phone', 'industry_code', 'industry_name',
        'income_type', 'is_manufacturing',
    ];

    /** @var array<string, string> */
    protected $validationRules = [
        'user_id'    => 'required|is_natural_no_zero',
        'name'       => 'required|max_length[200]',
        'biz_reg_no' => 'permit_empty|max_length[20]',
    ];

    /**
     * 특정 사용자 소유의 사업장 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * 사용자 스코프로 단건 조회(소유권 확인 포함).
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(int $userId, int $businessId): ?array
    {
        return $this->where('user_id', $userId)
            ->where('id', $businessId)
            ->first();
    }
}
