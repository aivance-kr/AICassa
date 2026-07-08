<?php

namespace Config;

use App\Services\DepreciationService;
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
}
