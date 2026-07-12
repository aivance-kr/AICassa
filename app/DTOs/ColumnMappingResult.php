<?php

namespace App\DTOs;

/**
 * 엑셀/CSV 임포트 컬럼 자동매핑 분석 결과.
 *
 * 소스 헤더 행과 앞부분 샘플 행, 그리고 각 소스 컬럼(index)이 어느 표준 필드로
 * 매핑되는지에 대한 제안(assignments)을 담는다. 사용자 확인 UI 가 그대로 렌더한다.
 */
final readonly class ColumnMappingResult
{
    /**
     * @param list<string>            $headers     소스 헤더 셀(index 정렬)
     * @param list<list<string>>      $samples     앞부분 데이터 행(각 행은 헤더 index 정렬 셀 배열)
     * @param array<int, string|null> $assignments 소스컬럼 index → 표준 필드 키(제안), 미매핑은 null
     */
    public function __construct(
        public array $headers,
        public array $samples,
        public array $assignments,
    ) {
    }
}
