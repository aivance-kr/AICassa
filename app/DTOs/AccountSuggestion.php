<?php

namespace App\DTOs;

use App\Enums\ClassifierSource;

/**
 * 계정과목 자동분류 결과(값 객체).
 *
 * 계산이 아니라 "제안"이다 — 최종 확정은 사람이 한다. 확신도(confidence)와 근거(source)를
 * 함께 담아, 호출측이 임계값으로 채택 여부를 판단하게 한다.
 */
final readonly class AccountSuggestion
{
    /**
     * @param int|null         $accountId   매핑된 계정과목 id(정확일치 성공 시). 실패 시 null.
     * @param string|null      $accountName 계정과목명(제안). id 가 null 이어도 힌트로 남을 수 있다.
     * @param float            $confidence  0.0 ~ 1.0
     * @param ClassifierSource $source      추천 근거
     */
    public function __construct(
        public ?int $accountId,
        public ?string $accountName,
        public float $confidence,
        public ClassifierSource $source,
    ) {
    }

    /**
     * 추천 없음(빈 결과).
     */
    public static function none(): self
    {
        return new self(null, null, 0.0, ClassifierSource::None);
    }

    /**
     * 폼 자동채움에 쓸 만큼 확신할 수 있는가.
     */
    public function isConfident(float $threshold = 0.7): bool
    {
        return $this->accountId !== null && $this->confidence >= $threshold;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_id'   => $this->accountId,
            'account_name' => $this->accountName,
            'confidence'   => round($this->confidence, 2),
            'source'       => $this->source->value,
        ];
    }
}
