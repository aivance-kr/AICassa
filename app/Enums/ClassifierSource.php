<?php

namespace App\Enums;

/**
 * 계정과목 자동분류 결과의 근거(어디서 나온 추천인지).
 */
enum ClassifierSource: string
{
    case History = 'history'; // 과거 거래 이력(LLM 미사용)
    case Ai      = 'ai';      // Claude 추론(이력 miss 시 폴백)
    case None    = 'none';    // 추천 없음

    public function label(): string
    {
        return match ($this) {
            self::History => '과거 이력',
            self::Ai      => 'AI 추천',
            self::None    => '추천 없음',
        };
    }
}
