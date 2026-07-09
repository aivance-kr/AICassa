<?php

namespace App\Services;

use App\DTOs\PartnerData;
use App\DTOs\PartnerImportResult;
use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\BusinessModel;
use App\Models\PartnerModel;

/**
 * 거래처 유스케이스. business_id 스코프 + 사업장 소유권(user_id) 검증.
 */
final class PartnerService
{
    private PartnerModel $partners;
    private BusinessModel $businesses;

    public function __construct(?PartnerModel $partners = null, ?BusinessModel $businesses = null)
    {
        $this->partners   = $partners ?? model(PartnerModel::class);
        $this->businesses = $businesses ?? model(BusinessModel::class);
    }

    /**
     * 거래처 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function listForBusiness(int $userId, int $businessId): array
    {
        $this->assertBusinessOwned($userId, $businessId);

        return $this->partners->forBusiness($businessId);
    }

    /**
     * 거래처 단건 조회.
     *
     * @return array<string, mixed>
     */
    public function get(int $userId, int $businessId, int $partnerId): array
    {
        $this->assertBusinessOwned($userId, $businessId);

        $partner = $this->partners->findScoped($businessId, $partnerId);
        if ($partner === null) {
            throw new NotFoundException('거래처를 찾을 수 없습니다.');
        }

        return $partner;
    }

    /**
     * 거래처 등록.
     *
     * @return int 생성된 거래처 ID
     */
    public function create(int $userId, int $businessId, PartnerData $data): int
    {
        $this->assertBusinessOwned($userId, $businessId);

        if ($data->bizRegNo !== null && $this->partners->bizRegNoExists($businessId, $data->bizRegNo)) {
            throw new AlreadyExistsException('이미 등록된 사업자등록번호입니다.');
        }

        $row                = $data->toDatabaseArray();
        $row['business_id'] = $businessId;

        if (! $this->partners->insert($row)) {
            throw new ValidationException($this->partners->errors());
        }

        return (int) $this->partners->getInsertID();
    }

    /**
     * 거래처 수정.
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, int $businessId, int $partnerId, PartnerData $data): array
    {
        $this->get($userId, $businessId, $partnerId); // 존재·소유권 검증

        if ($data->bizRegNo !== null
            && $this->partners->bizRegNoExists($businessId, $data->bizRegNo, $partnerId)) {
            throw new AlreadyExistsException('이미 등록된 사업자등록번호입니다.');
        }

        if (! $this->partners->update($partnerId, $data->toDatabaseArray())) {
            throw new ValidationException($this->partners->errors());
        }

        return $this->get($userId, $businessId, $partnerId);
    }

    /**
     * 거래처 삭제(소프트).
     */
    public function delete(int $userId, int $businessId, int $partnerId): void
    {
        $this->get($userId, $businessId, $partnerId);
        $this->partners->delete($partnerId);
    }

    /**
     * CSV 문자열을 거래처 행 배열로 파싱한다.
     * 컬럼 순서: 거래처상호, 사업자등록번호, 연락처. 첫 행이 헤더면 자동 스킵.
     *
     * @return list<array<string, mixed>>
     */
    public function parseCsv(string $csv): array
    {
        $rows  = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);

            // 헤더 행 스킵(첫 행에 '상호'/'거래처' 등 텍스트가 있으면)
            if ($index === 0 && $this->looksLikeHeader($cols[0] ?? '')) {
                continue;
            }

            $rows[] = [
                'name'       => $cols[0] ?? '',
                'biz_reg_no' => $cols[1] ?? '',
                'phone'      => $cols[2] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * 거래처 CSV 일괄등록. 유효하지 않거나 중복인 행은 건너뛰고 결과를 집계한다.
     *
     * @param list<array<string, mixed>> $rows parseCsv() 결과 또는 동일 구조 배열
     */
    public function importFromRows(int $userId, int $businessId, array $rows): PartnerImportResult
    {
        $this->assertBusinessOwned($userId, $businessId);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $seen     = []; // 파일 내 중복 방지용 사업자번호 집합

        $db = db_connect();
        $db->transStart();

        foreach ($rows as $index => $raw) {
            $rowNum = $index + 1;
            $data   = PartnerData::fromArray($raw);

            $reason = $this->validateImportRow($data, $businessId, $seen);
            if ($reason !== null) {
                $errors[] = ['row' => $rowNum, 'reason' => $reason];
                $skipped++;

                continue;
            }

            $row                = $data->toDatabaseArray();
            $row['business_id'] = $businessId;

            if (! $this->partners->insert($row)) {
                $errors[] = ['row' => $rowNum, 'reason' => '유효성 오류'];
                $skipped++;

                continue;
            }

            /** @var string $bizNo 검증에서 null 이 아님이 보장됨 */
            $bizNo        = $data->bizRegNo;
            $seen[$bizNo] = true;
            $imported++;
        }

        $db->transComplete();

        return new PartnerImportResult($imported, $skipped, $errors);
    }

    /**
     * 일괄등록 행 검증. 통과 시 null, 실패 시 사유 문자열.
     *
     * @param array<string, bool> $seen
     */
    private function validateImportRow(PartnerData $data, int $businessId, array $seen): ?string
    {
        if ($data->name === '') {
            return '거래처상호 필수';
        }
        if ($data->bizRegNo === null) {
            return '사업자등록번호 필수';
        }
        if (isset($seen[$data->bizRegNo])) {
            return '파일 내 중복 사업자등록번호';
        }
        if ($this->partners->bizRegNoExists($businessId, $data->bizRegNo)) {
            return '이미 등록된 사업자등록번호';
        }

        return null;
    }

    /**
     * 사업장이 해당 사용자 소유인지 검증. 아니면 예외.
     */
    private function assertBusinessOwned(int $userId, int $businessId): void
    {
        if ($this->businesses->findOwned($userId, $businessId) === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
    }

    private function looksLikeHeader(string $firstCell): bool
    {
        return str_contains($firstCell, '상호') || str_contains($firstCell, '거래처');
    }
}
