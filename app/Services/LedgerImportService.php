<?php

namespace App\Services;

use App\DTOs\ImportResult;
use App\DTOs\LedgerData;
use App\Enums\AccountCategory;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\RowException;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Models\PartnerModel;

/**
 * 장부 CSV 일괄 업로드.
 * 컬럼 순서: 날짜, 구분(수입/비용), 계정과목, 거래내용, 거래처, 금액, 비고(증빙).
 * 구분·계정과목·거래처·비고는 한글명으로 해석하며, 부가세는 LedgerService 가 자동계산한다.
 */
final class LedgerImportService
{
    /**
     * 한 번의 대량 임포트에서 허용할 AI 폴백 시도 수.
     * 이력 기반 분류는 제한하지 않으며, 한 HTTP 요청이 외부 API 비용을 증폭시키는 것을 막는다.
     */
    private const MAX_AI_FALLBACKS_PER_IMPORT = 20;

    private LedgerService $ledger;
    private BusinessModel $businesses;
    private AccountModel $accounts;
    private PartnerModel $partners;
    private AccountClassifierService $classifier;

    public function __construct(
        ?LedgerService $ledger = null,
        ?BusinessModel $businesses = null,
        ?AccountModel $accounts = null,
        ?PartnerModel $partners = null,
        ?AccountClassifierService $classifier = null,
    ) {
        $this->ledger     = $ledger ?? service('ledgerService');
        $this->businesses = $businesses ?? model(BusinessModel::class);
        $this->accounts   = $accounts ?? model(AccountModel::class);
        $this->partners   = $partners ?? model(PartnerModel::class);
        $this->classifier = $classifier ?? service('accountClassifierService');
    }

    /**
     * CSV 문자열을 장부 행 배열로 파싱한다. 첫 행이 머리글이면 건너뛴다.
     *
     * @return list<array<string, string>>
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

            if ($index === 0 && $this->looksLikeHeader((string) ($cols[0] ?? ''))) {
                continue;
            }

            $rows[] = [
                'date'        => trim((string) ($cols[0] ?? '')),
                'type'        => trim((string) ($cols[1] ?? '')),
                'account'     => trim((string) ($cols[2] ?? '')),
                'description' => trim((string) ($cols[3] ?? '')),
                'partner'     => trim((string) ($cols[4] ?? '')),
                'amount'      => trim((string) ($cols[5] ?? '')),
                'evidence'    => trim((string) ($cols[6] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * 명시적 컬럼 매핑으로 데이터 행을 표준 행 배열로 변환한다(자동매핑 확인 UI 경로).
     * 헤더는 이미 제거된 데이터 행만 받으며, 매핑에 없는 표준 필드는 빈 문자열로 채운다.
     *
     * @param list<list<string>> $dataRows 헤더를 제외한 셀 배열들
     * @param array<string, int> $mapping  표준 필드 키 → 소스 컬럼 index
     *
     * @return list<array<string, string>>
     */
    public function parseWithMapping(array $dataRows, array $mapping): array
    {
        // 표준 필드 키는 ExcelColumnMapperService 를 단일 출처로 삼는다(드리프트 방지).
        $fields = array_keys(ExcelColumnMapperService::fields());

        $rows = [];

        foreach ($dataRows as $cells) {
            $row = [];

            foreach ($fields as $field) {
                $idx         = $mapping[$field] ?? null;
                $row[$field] = $idx === null ? '' : trim((string) ($cells[$idx] ?? ''));
            }

            // 매핑된 값이 모두 비면(빈 행) 건너뛴다.
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 파싱된 행을 일괄 등록한다. 유효하지 않은 행은 건너뛰고 사유를 집계한다.
     *
     * @param list<array<string, string>> $rows
     */
    public function import(int $userId, int $businessId, array $rows): ImportResult
    {
        // 사업장 소유권 1회 검증
        $business = $this->businesses->findOwned($userId, $businessId);
        if ($business === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }
        $isManufacturing = (bool) $business['is_manufacturing'];

        $imported           = 0;
        $skipped            = 0;
        $errors             = [];
        $aiFallbackAttempts = 0;

        $db = db_connect();
        $db->transStart();

        foreach ($rows as $index => $raw) {
            $rowNum = $index + 1;

            try {
                $data = $this->buildData($businessId, $raw, $isManufacturing, $aiFallbackAttempts);
                $this->ledger->create($userId, $businessId, $data);
                $imported++;
            } catch (RowException $e) {
                $errors[] = ['row' => $rowNum, 'reason' => $e->getMessage()];
                $skipped++;
            } catch (DomainException $e) {
                $errors[] = ['row' => $rowNum, 'reason' => $e->getMessage()];
                $skipped++;
            }
        }

        $db->transComplete();

        return new ImportResult($imported, $skipped, $errors);
    }

    /**
     * 한 행을 검증·해석해 LedgerData 로 만든다. 실패 시 RowException.
     *
     * @param array<string, string> $raw
     */
    private function buildData(int $businessId, array $raw, bool $isManufacturing, int &$aiFallbackAttempts): LedgerData
    {
        $date = $this->normalizeDate($raw['date']);
        if ($date === null) {
            throw new RowException('날짜 형식 오류');
        }

        $type = EntryType::fromLabel($raw['type']);
        if ($type !== EntryType::Income && $type !== EntryType::Expense) {
            throw new RowException('구분은 수입/비용만 허용');
        }

        if (! is_numeric(str_replace([',', ' '], '', $raw['amount']))) {
            throw new RowException('금액은 숫자여야 함');
        }
        $amount = (int) str_replace([',', ' '], '', $raw['amount']);

        $accountId = null;
        if ($raw['account'] !== '') {
            $category = $type === EntryType::Income ? AccountCategory::Income : AccountCategory::Expense;
            $account  = $this->accounts->findByCategoryName($category, $raw['account']);
            if ($account === null) {
                throw new RowException("계정과목 '{$raw['account']}' 없음");
            }
            $accountId = (int) $account['id'];
        }

        $partnerId = null;
        if ($raw['partner'] !== '') {
            $partner = $this->partners->findByName($businessId, $raw['partner']);
            if ($partner === null) {
                throw new RowException("거래처 '{$raw['partner']}' 미등록");
            }
            $partnerId = (int) $partner['id'];
        }

        // 계정과목 칸이 비었으면 자동분류로 draft 를 채운다(이력 우선, 없으면 AI). 확신할 때만.
        if ($accountId === null && $raw['description'] !== '') {
            $allowAiFallback = $aiFallbackAttempts < self::MAX_AI_FALLBACKS_PER_IMPORT;
            if ($allowAiFallback) {
                $aiFallbackAttempts++;
            }
            $suggestion = $this->classifier->suggest(
                $businessId,
                $type,
                $raw['description'],
                $raw['partner'],
                $isManufacturing,
                $allowAiFallback,
            );
            if ($suggestion->isConfident()) {
                $accountId = $suggestion->accountId;
            }
        }

        $evidence = $raw['evidence'] === ''
            ? EvidenceType::Other
            : EvidenceType::fromLabel($raw['evidence']);
        if ($evidence === null) {
            throw new RowException("비고(증빙) '{$raw['evidence']}' 유형 오류");
        }

        if ($raw['description'] === '') {
            throw new RowException('거래내용 필수');
        }

        return new LedgerData(
            entryDate: $date,
            entryType: $type,
            description: $raw['description'],
            supplyAmount: $amount,
            accountId: $accountId,
            partnerId: $partnerId,
            evidenceType: $evidence,
        );
    }

    /**
     * 날짜 정규화. 구분자(. /)를 허용하고 Y-m-d 로 통일. 실패 시 null.
     */
    private function normalizeDate(string $value): ?string
    {
        $value = str_replace(['.', '/'], '-', trim($value));
        $ts    = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    private function looksLikeHeader(string $firstCell): bool
    {
        return str_contains($firstCell, '날짜') || str_contains($firstCell, '일자');
    }
}
