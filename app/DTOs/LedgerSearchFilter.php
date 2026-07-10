<?php

namespace App\DTOs;

use App\Enums\EntryType;

/**
 * 장부 검색 필터(값 객체).
 *
 * 자연어 검색의 최종 산출물이다. AI 는 절대 SQL 을 만들지 않는다 — AI 출력은 이 DTO 의
 * 타입 고정 필드(화이트리스트)로만 흡수되며, 알 수 없는 키/값은 버려진다. 여기서 통과한
 * 값만 Query Builder 바인딩으로 전달된다(인젝션 차단).
 */
final readonly class LedgerSearchFilter
{
    /**
     * keyword 최대 길이(과도한 LIKE 방지).
     */
    private const KEYWORD_MAX = 100;

    public function __construct(
        public ?int $fiscalYear = null,
        public ?EntryType $entryType = null,
        public ?string $dateFrom = null,   // Y-m-d
        public ?string $dateTo = null,     // Y-m-d
        public ?int $accountId = null,
        public ?int $partnerId = null,
        public ?int $amountMin = null,
        public ?int $amountMax = null,
        public ?string $keyword = null,
    ) {
    }

    /**
     * 빈 필터(아무 조건 없음).
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * 거래내용 키워드만 담은 필터(AI 미사용 폴백용).
     */
    public static function keywordOnly(string $keyword): self
    {
        return new self(keyword: self::normalizeKeyword($keyword));
    }

    /**
     * 적용된 조건이 하나도 없으면 true.
     */
    public function isEmpty(): bool
    {
        return $this->fiscalYear === null
            && $this->entryType === null
            && $this->dateFrom === null
            && $this->dateTo === null
            && $this->accountId === null
            && $this->partnerId === null
            && $this->amountMin === null
            && $this->amountMax === null
            && ($this->keyword === null || $this->keyword === '');
    }

    /**
     * Query Builder 로 넘길 필터 배열(알려진 키만 · null 제외).
     *
     * @return array<string, int|string>
     */
    public function toFilterArray(): array
    {
        $filters = [];

        if ($this->fiscalYear !== null) {
            $filters['fiscal_year'] = $this->fiscalYear;
        }
        if ($this->entryType !== null) {
            $filters['entry_type'] = $this->entryType->value;
        }
        if ($this->dateFrom !== null) {
            $filters['date_from'] = $this->dateFrom;
        }
        if ($this->dateTo !== null) {
            $filters['date_to'] = $this->dateTo;
        }
        if ($this->accountId !== null) {
            $filters['account_id'] = $this->accountId;
        }
        if ($this->partnerId !== null) {
            $filters['partner_id'] = $this->partnerId;
        }
        if ($this->amountMin !== null) {
            $filters['amount_min'] = $this->amountMin;
        }
        if ($this->amountMax !== null) {
            $filters['amount_max'] = $this->amountMax;
        }
        if ($this->keyword !== null && $this->keyword !== '') {
            $filters['keyword'] = $this->keyword;
        }

        return $filters;
    }

    /**
     * 리다이렉트 쿼리스트링용 파라미터(URL 공유·재조회 대응). 값은 문자열로 정규화.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        $params = [];

        foreach ($this->toFilterArray() as $key => $value) {
            $params[$key] = (string) $value;
        }

        return $params;
    }

    /**
     * keyword 정규화 — 공백 트림·최대 길이 컷. 빈 값은 null.
     */
    public static function normalizeKeyword(string $keyword): ?string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        return mb_substr($keyword, 0, self::KEYWORD_MAX);
    }
}
