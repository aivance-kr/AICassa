<?php

namespace Config;

use App\Libraries\AnthropicClient;
use App\Libraries\SpreadsheetReader;
use App\Models\TaxParameterModel;
use App\Services\AccountClassifierService;
use App\Services\AnomalyDetectionService;
use App\Services\AssetAdvisorService;
use App\Services\AssetService;
use App\Services\BusinessService;
use App\Services\DepreciationService;
use App\Services\ExcelColumnMapperService;
use App\Services\LedgerImportService;
use App\Services\LedgerQueryParserService;
use App\Services\LedgerService;
use App\Services\PartnerService;
use App\Services\ReceiptOcrService;
use App\Services\SummaryService;
use App\Services\TaxFormExporter;
use App\Services\TaxFormService;
use App\Services\TaxParameterService;
use App\Services\TaxQaService;
use App\Services\TaxRuleResolver;
use App\Services\VatCalculatorService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * 부가세 자동계산 서비스.
     */
    public static function vatCalculator(bool $getShared = true): VatCalculatorService
    {
        if ($getShared) {
            return static::getSharedInstance('vatCalculator');
        }

        return new VatCalculatorService();
    }

    /**
     * 감가상각 계산 서비스.
     */
    public static function depreciation(bool $getShared = true): DepreciationService
    {
        if ($getShared) {
            return static::getSharedInstance('depreciation');
        }

        return new DepreciationService();
    }

    /**
     * 시행연도 기준 세법 룰셋 해석 서비스.
     * 룰셋 소스는 운영자 관리 DB(tax_parameters) 우선, 비어 있으면 Config\TaxRules.
     */
    public static function taxRuleResolver(bool $getShared = true): TaxRuleResolver
    {
        if ($getShared) {
            return static::getSharedInstance('taxRuleResolver');
        }

        return new TaxRuleResolver(null, model(TaxParameterModel::class));
    }

    /**
     * 연도별 세법 파라미터 운영자 관리 서비스.
     */
    public static function taxParameterService(bool $getShared = true): TaxParameterService
    {
        if ($getShared) {
            return static::getSharedInstance('taxParameterService');
        }

        return new TaxParameterService();
    }

    /**
     * 사업장 서비스.
     */
    public static function businessService(bool $getShared = true): BusinessService
    {
        if ($getShared) {
            return static::getSharedInstance('businessService');
        }

        return new BusinessService();
    }

    /**
     * 거래처 서비스.
     */
    public static function partnerService(bool $getShared = true): PartnerService
    {
        if ($getShared) {
            return static::getSharedInstance('partnerService');
        }

        return new PartnerService();
    }

    /**
     * 장부 서비스.
     */
    public static function ledgerService(bool $getShared = true): LedgerService
    {
        if ($getShared) {
            return static::getSharedInstance('ledgerService');
        }

        return new LedgerService();
    }

    /**
     * 장부 CSV 일괄 업로드 서비스.
     */
    public static function ledgerImportService(bool $getShared = true): LedgerImportService
    {
        if ($getShared) {
            return static::getSharedInstance('ledgerImportService');
        }

        return new LedgerImportService();
    }

    /**
     * 엑셀/CSV 임포트 컬럼 자동매핑 서비스(로컬 동의어 + AI 보완).
     */
    public static function excelColumnMapperService(bool $getShared = true): ExcelColumnMapperService
    {
        if ($getShared) {
            return static::getSharedInstance('excelColumnMapperService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 로컬 동의어 매칭만 동작).
        return new ExcelColumnMapperService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 업로드 파일(CSV/엑셀) → 문자열 그리드 리더.
     */
    public static function spreadsheetReader(bool $getShared = true): SpreadsheetReader
    {
        if ($getShared) {
            return static::getSharedInstance('spreadsheetReader');
        }

        return new SpreadsheetReader();
    }

    /**
     * 계정과목 자동분류 서비스(이력 캐시 + AI 폴백).
     */
    public static function accountClassifierService(bool $getShared = true): AccountClassifierService
    {
        if ($getShared) {
            return static::getSharedInstance('accountClassifierService');
        }

        // AI 폴백 클라이언트는 env 기반으로 주입(키 없으면 null → 이력 기반만 동작).
        return new AccountClassifierService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 자연어 장부 검색 파서 서비스(NL → 안전한 필터 DTO).
     */
    public static function ledgerQueryParserService(bool $getShared = true): LedgerQueryParserService
    {
        if ($getShared) {
            return static::getSharedInstance('ledgerQueryParserService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 키워드 검색으로 폴백).
        return new LedgerQueryParserService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 신고·결산 전 이상탐지 서비스(결정적 규칙 + 선택적 AI 오분류 점검).
     */
    public static function anomalyDetectionService(bool $getShared = true): AnomalyDetectionService
    {
        if ($getShared) {
            return static::getSharedInstance('anomalyDetectionService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 결정적 규칙만 수행).
        return new AnomalyDetectionService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 영수증/세금계산서 사진 AI 판독 서비스.
     */
    public static function receiptOcrService(bool $getShared = true): ReceiptOcrService
    {
        if ($getShared) {
            return static::getSharedInstance('receiptOcrService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 판독 시 "설정 미완료" 안내로 폴백).
        return new ReceiptOcrService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 영업현황표(집계) 서비스.
     */
    public static function summaryService(bool $getShared = true): SummaryService
    {
        if ($getShared) {
            return static::getSharedInstance('summaryService');
        }

        return new SummaryService();
    }

    /**
     * 자산대장·감가상각 서비스.
     */
    public static function assetService(bool $getShared = true): AssetService
    {
        if ($getShared) {
            return static::getSharedInstance('assetService');
        }

        return new AssetService();
    }

    /**
     * 자산 등록 어시스트 서비스(품목명 → 분류·상각방법 제안 + 결정적 내용연수·소액자산 판정).
     */
    public static function assetAdvisorService(bool $getShared = true): AssetAdvisorService
    {
        if ($getShared) {
            return static::getSharedInstance('assetAdvisorService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 이력·결정적 결과만 동작).
        return new AssetAdvisorService(ai: AnthropicClient::fromEnv());
    }

    /**
     * 종합소득세 신고서식 서비스.
     */
    public static function taxFormService(bool $getShared = true): TaxFormService
    {
        if ($getShared) {
            return static::getSharedInstance('taxFormService');
        }

        return new TaxFormService();
    }

    /**
     * 신고서식 엑셀 익스포터.
     */
    public static function taxFormExporter(bool $getShared = true): TaxFormExporter
    {
        if ($getShared) {
            return static::getSharedInstance('taxFormExporter');
        }

        return new TaxFormExporter();
    }

    /**
     * 세무 Q&A 챗봇 서비스(docs 근거 RAG · 인용 응답).
     */
    public static function taxQaService(bool $getShared = true): TaxQaService
    {
        if ($getShared) {
            return static::getSharedInstance('taxQaService');
        }

        // AI 클라이언트는 env 기반 주입(키 없으면 null → 검색만 하고 "확인 불가" 응답).
        return new TaxQaService(ai: AnthropicClient::fromEnv());
    }
}
