<?php

namespace App\DTOs;

use App\Enums\ClassifierSource;
use App\Enums\DepreciationMethod;

/**
 * 자산 등록 어시스트 결과(값 객체).
 *
 * 품목명으로 자산분류·상각방법을 "제안"하고, 내용연수(업종기준)·소액자산 여부는
 * 결정적으로 채운 결과를 담는다. 계산이 아니라 초안(draft)이며 최종 확정은 사람이 한다.
 * AI 는 asset_type·method 만 제안하고 나머지 필드는 도메인 규칙으로 산출한다(환각 방지).
 */
final readonly class AssetAdviceResult
{
    /**
     * @param string|null             $assetType         제안 자산종류(닫힌 어휘와 정확일치 시). 실패 시 null
     * @param DepreciationMethod|null $method            제안 상각방법. 실패 시 null
     * @param int|null                $usefulLife        업종기준 내용연수(결정적). 업종코드 없으면 null
     * @param bool                    $isLowValue        취득금액이 소액자산 한도 이하인가(결정적)
     * @param int                     $lowValueThreshold 적용된 소액자산 한도(원, 취득연도 기준)
     * @param float                   $confidence        0.0 ~ 1.0 (자산분류 제안 확신도)
     * @param ClassifierSource        $source            제안 근거(이력·AI·없음)
     */
    public function __construct(
        public ?string $assetType,
        public ?DepreciationMethod $method,
        public ?int $usefulLife,
        public bool $isLowValue,
        public int $lowValueThreshold,
        public float $confidence,
        public ClassifierSource $source,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_type'          => $this->assetType,
            'depreciation_method' => $this->method?->value,
            'useful_life'         => $this->usefulLife,
            'is_low_value'        => $this->isLowValue,
            'low_value_threshold' => $this->lowValueThreshold,
            'confidence'          => round($this->confidence, 2),
            'source'              => $this->source->value,
        ];
    }
}
