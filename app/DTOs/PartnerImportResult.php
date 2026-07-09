<?php

namespace App\DTOs;

/**
 * 거래처 CSV 일괄등록 결과.
 */
final readonly class PartnerImportResult
{
    /**
     * @param int                                 $imported 등록 성공 건수
     * @param int                                 $skipped  건너뛴 건수(중복·오류)
     * @param list<array{row:int, reason:string}> $errors   실패 행 상세
     */
    public function __construct(
        public int $imported,
        public int $skipped,
        public array $errors,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'skipped'  => $this->skipped,
            'errors'   => $this->errors,
        ];
    }
}
