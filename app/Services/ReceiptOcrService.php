<?php

namespace App\Services;

use App\DTOs\OcrResult;
use App\Enums\AccountCategory;
use App\Enums\EntryType;
use App\Exceptions\NotFoundException;
use App\Exceptions\OcrProcessingException;
use App\Exceptions\ValidationException;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Models\PartnerModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * 영수증/세금계산서 사진 AI 판독(유스케이스).
 *
 * 업로드 검증 → writable/uploads/receipts/ 저장 → Claude Vision 호출 → JSON 파싱 →
 * 계정과목·거래처 이름을 기존 등록건과 정확일치 매핑까지 담당한다.
 * 부가세는 계산하지 않는다 — 최종 저장 시 LedgerService 가 항상 재계산한다(단일 진실 소스).
 */
final class ReceiptOcrService
{
    private const TIMEOUT    = 25;   // 초. Vision 은 5초를 넘길 수 있어 명시적으로 늘린다.
    private const MAX_TOKENS = 1024;
    private const MAX_BYTES  = 8 * 1024 * 1024; // 8MB

    /**
     * @var list<string> 실제 MIME(finfo) 화이트리스트.
     */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private BusinessModel $businesses;
    private AccountModel $accounts;
    private PartnerModel $partners;
    private ?AnthropicClient $ai;
    private AccountClassifierService $classifier;

    public function __construct(
        ?BusinessModel $businesses = null,
        ?AccountModel $accounts = null,
        ?PartnerModel $partners = null,
        ?AnthropicClient $ai = null,
        ?AccountClassifierService $classifier = null,
    ) {
        $this->businesses = $businesses ?? model(BusinessModel::class);
        $this->accounts   = $accounts ?? model(AccountModel::class);
        $this->partners   = $partners ?? model(PartnerModel::class);
        // AI 클라이언트는 Services 팩토리에서 env 기반 주입. null 이면 키 없음(=AI 판독 비활성).
        $this->ai         = $ai;
        $this->classifier = $classifier ?? service('accountClassifierService');
    }

    /**
     * 업로드된 영수증 사진을 판독해 폼 자동채움용 데이터를 반환한다.
     *
     * @return array<string, mixed> receipt_path·entry_type·entry_date·description·supply_amount·
     *                              evidence_type·account_id·account_name·partner_id·partner_name
     *
     * @throws NotFoundException      사업장 소유권 없음
     * @throws OcrProcessingException Claude 호출·응답 파싱 실패
     * @throws ValidationException    파일 검증 실패
     */
    public function recognize(int $userId, int $businessId, ?UploadedFile $file): array
    {
        $business = $this->businesses->findOwned($userId, $businessId);
        if ($business === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }

        $this->assertValidFile($file);
        /** @var UploadedFile $file 위 검증 통과로 non-null 보장 */
        $mime         = (string) $file->getMimeType();
        $relativePath = $file->store('receipts'); // writable/uploads/receipts/{랜덤명}.ext
        $absolutePath = WRITEPATH . 'uploads/' . $relativePath;

        try {
            // 키가 없으면 AI 클라이언트가 주입되지 않는다(fromEnv() → null). 기존과 동일한 안내로 폴백.
            // 지역 변수로 받아 이후 호출 지점까지 non-null 이 좁혀지도록 한다(PHPStan).
            $ai = $this->ai;
            if ($ai === null) {
                throw new OcrProcessingException('AI 판독 설정이 완료되지 않았습니다(API 키 없음).');
            }

            $prompt = $this->buildPrompt((bool) $business['is_manufacturing']);
            $base64 = base64_encode((string) file_get_contents($absolutePath));
            // Vision 호출 실패는 RuntimeException → 아래 catch 가 OcrProcessingException 으로 감싼다.
            $text   = $ai->completeVision($base64, $mime, $prompt, self::MAX_TOKENS, self::TIMEOUT);
            $result = OcrResult::fromArray($this->parseJson($text));

            return $this->toResponse($businessId, $relativePath, $result, (bool) $business['is_manufacturing']);
        } catch (Throwable $e) {
            // 판독 실패 시 고아 파일을 즉시 정리한다.
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }

            throw $e instanceof OcrProcessingException
                ? $e
                : new OcrProcessingException('영수증 판독에 실패했습니다.', 0, $e);
        }
    }

    /**
     * 업로드 파일 검증(존재·유효·용량·실제 MIME).
     *
     * @throws ValidationException
     */
    private function assertValidFile(?UploadedFile $file): void
    {
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            throw new ValidationException(['receipt' => '이미지 파일을 첨부하세요.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException(['receipt' => '파일이 너무 큽니다(최대 8MB).']);
        }
        if (! in_array((string) $file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw new ValidationException(['receipt' => 'JPG·PNG·WEBP 이미지만 업로드할 수 있습니다.']);
        }
    }

    /**
     * 모델 출력 텍스트에서 JSON 객체를 파싱한다(코드펜스 방어적 제거).
     *
     * @return array<string, mixed>
     *
     * @throws OcrProcessingException
     */
    private function parseJson(string $text): array
    {
        $clean = trim($text);
        // ```json ... ``` 코드펜스를 제거한다.
        $clean = (string) preg_replace('/^```(?:json)?|```$/m', '', $clean);
        $clean = trim($clean);

        /** @var mixed $data */
        $data = json_decode($clean, true);
        if (! is_array($data)) {
            throw new OcrProcessingException('AI 응답을 해석할 수 없습니다.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * 판독 결과를 폼 자동채움용 배열로 변환한다.
     * 계정과목·거래처는 기존 등록건과 정확일치할 때만 id 를 채우고, 실패 시 이름(hint)만 남긴다.
     * 계정과목 정확일치에 실패하면 자동분류기(이력·AI)로 폴백해 draft 를 채운다.
     *
     * @return array<string, mixed>
     */
    private function toResponse(int $businessId, string $receiptPath, OcrResult $result, bool $isManufacturing): array
    {
        $category = $result->entryType === EntryType::Income
            ? AccountCategory::Income
            : AccountCategory::Expense;

        $accountId   = null;
        $accountName = $result->accountName;
        if ($result->accountName !== null) {
            $account   = $this->accounts->findByCategoryName($category, $result->accountName);
            $accountId = $account !== null ? (int) $account['id'] : null;
        }

        // 정확일치에 실패했으면 자동분류기로 폴백(이력 우선, 없으면 AI). 확신할 때만 채운다.
        if ($accountId === null && $result->description !== '') {
            $suggestion = $this->classifier->suggest(
                $businessId,
                $result->entryType,
                $result->description,
                $result->partnerName,
                $isManufacturing,
            );
            if ($suggestion->isConfident()) {
                $accountId   = $suggestion->accountId;
                $accountName = $suggestion->accountName;
            }
        }

        $partnerId = null;
        if ($result->partnerName !== null) {
            $partner   = $this->partners->findByName($businessId, $result->partnerName);
            $partnerId = $partner !== null ? (int) $partner['id'] : null;
        }

        return [
            'receipt_path'  => $receiptPath,
            'entry_type'    => $result->entryType->value,
            'entry_date'    => $result->entryDate,
            'description'   => $result->description,
            'supply_amount' => $result->supplyAmount,
            'evidence_type' => $result->evidenceType?->value,
            'account_id'    => $accountId,
            'account_name'  => $accountName,
            'partner_id'    => $partnerId,
            'partner_name'  => $result->partnerName,
        ];
    }

    /**
     * 계정과목·증빙유형을 실제 DB 목록으로 제한하는 프롬프트(닫힌 어휘)를 만든다.
     */
    private function buildPrompt(bool $isManufacturing): string
    {
        $income  = array_column($this->accounts->forCategory(AccountCategory::Income), 'name');
        $expense = array_column($this->accounts->forCategory(AccountCategory::Expense, $isManufacturing), 'name');

        $incomeList  = implode(', ', array_map('strval', $income));
        $expenseList = implode(', ', array_map('strval', $expense));

        return <<<PROMPT
            한국 간편장부용 영수증/세금계산서 이미지를 분석해 JSON 객체 하나만 출력하라(설명·마크다운·코드펜스 금지).
            스키마:
            {"entry_type":"income 또는 expense",
             "entry_date":"YYYY-MM-DD",
             "description":"거래내용(품목·상호 요약, 40자 이내)",
             "supply_amount":정수(부가세 제외 공급가액. 총액만 있으면 공급가액으로 환산),
             "evidence_type":"tax_invoice|invoice|credit_card|cash_receipt|simple_receipt|other 중 하나 또는 null",
             "account_name":"아래 계정과목 목록과 정확히 일치하는 문자열 또는 null",
             "partner_name":"공급자(판매처) 상호 또는 null"}

            규칙:
            - account_name 은 반드시 아래 목록의 문자열과 100% 동일해야 하며, 확신이 없으면 null.
            - 값을 읽을 수 없으면 문자열은 빈 값, 숫자는 0, 선택 항목은 null.
            수입 계정과목: {$incomeList}
            비용 계정과목: {$expenseList}
            PROMPT;
    }
}
