<?php

namespace Config;

use App\Services\AssetService;
use App\Services\BusinessService;
use App\Services\DepreciationService;
use App\Services\LedgerImportService;
use App\Services\LedgerService;
use App\Services\PartnerService;
use App\Services\SummaryService;
use App\Services\TaxFormExporter;
use App\Services\TaxFormService;
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
}
