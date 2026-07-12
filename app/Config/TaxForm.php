<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 종합소득세 신고서식 버전 정책.
 * 서식은 매년 개정되므로 귀속연도별 서식 버전을 관리한다.
 * 서식이 바뀌면 해당 귀속연도부터 새 버전 키를 추가하고 latest 를 갱신한다.
 */
class TaxForm extends BaseConfig
{
    /**
     * 귀속연도 => 서식 버전 식별자.
     *
     * @var array<int, string>
     */
    public array $versions = [
        2023 => '2023-v1',
        2024 => '2024-v1',
        2025 => '2025-v1',
    ];

    /**
     * 맵에 없는 연도에 적용할 최신 서식 버전.
     */
    public string $latest = '2025-v1';

    /**
     * 이 연도 이전은 서식 정확성을 보장하지 않는다(경고 표시).
     */
    public int $earliestSupportedYear = 2020;
}
