<?php

namespace App\Models;

use App\Enums\AccountCategory;
use CodeIgniter\Model;

/**
 * 계정과목(참조 데이터) 모델.
 */
class AccountModel extends Model
{
    protected $table         = 'accounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /** @var list<string> */
    protected $allowedFields = ['category', 'name', 'is_manufacturing', 'sort_order'];

    /**
     * 카테고리별 계정 목록. 제조업이 아니면 제조 전용 계정을 제외한다.
     *
     * @return list<array<string, mixed>>
     */
    public function forCategory(AccountCategory $category, bool $includeManufacturing = true): array
    {
        $builder = $this->where('category', $category->value);
        if (! $includeManufacturing) {
            $builder->where('is_manufacturing', 0);
        }

        return $builder->orderBy('sort_order', 'ASC')->findAll();
    }

    /**
     * 카테고리 + 계정명으로 단건 조회(일괄 업로드 이름 해석용).
     *
     * @return array<string, mixed>|null
     */
    public function findByCategoryName(AccountCategory $category, string $name): ?array
    {
        return $this->where('category', $category->value)
            ->where('name', trim($name))
            ->first();
    }

    /**
     * id => name 매핑.
     *
     * @return array<int, string>
     */
    public function nameMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }

        return $map;
    }
}
