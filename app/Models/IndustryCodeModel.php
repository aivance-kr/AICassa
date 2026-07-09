<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 표준산업분류 업종코드(참조 데이터) 모델.
 * 업종코드로 업종별 자산 내용연수를 조회한다(감가상각 기본값 산정용).
 */
class IndustryCodeModel extends Model
{
    protected $table         = 'industry_codes';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = ['code', 'name', 'useful_life'];

    /**
     * 업종코드로 내용연수를 조회한다. 코드가 없거나 내용연수 미지정이면 null.
     */
    public function usefulLifeFor(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $row = $this->select('useful_life')->where('code', $code)->first();
        if ($row === null || $row['useful_life'] === null) {
            return null;
        }

        return (int) $row['useful_life'];
    }

    /**
     * 업종코드·업종명 부분일치로 검색한다(자동완성용). 키워드가 비면 빈 배열.
     *
     * @return list<array{code: string, name: string, useful_life: int|null}>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        /** @var list<array{code: string, name: string, useful_life: int|null}> $rows */
        $rows = $this->select('code, name, useful_life')
            ->groupStart()
            ->like('code', $keyword)
            ->orLike('name', $keyword)
            ->groupEnd()
            ->orderBy('code', 'ASC')
            ->limit($limit)
            ->find();

        return $rows;
    }

    /**
     * 업종코드가 참조 데이터에 존재하는지 확인한다(정확 일치).
     */
    public function existsByCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        return $this->where('code', $code)->countAllResults() > 0;
    }
}
