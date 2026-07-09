<?php

namespace App\DTOs;

/**
 * 일괄 업로드(CSV) 처리 결과.
 */
final readonly class ImportResult
{
    /**
     * @param int                                 $imported 등록 성공 건수
     * @param int                                 $skipped  건너뛴 건수
     * @param list<array{row:int, reason:string}> $errors   실패 행 상세
     */
    public function __construct(
        public int $imported,
        public int $skipped,
        public array $errors,
    ) {}
}
