<?php

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 리포트(Admin). 영업현황표(월/분기/연간 집계).
 */
class ReportController extends BaseAdminController
{
    /**
     * 영업현황표.
     */
    public function businessStatus(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $years = service('ledgerService')->availableYears($this->authUserId(), $businessId);

        $requested = $this->request->getGet('fiscal_year');
        $year      = $requested !== null && $requested !== ''
            ? (int) $requested
            : ($years[0] ?? (int) date('Y'));

        $statement = service('summaryService')->monthlyStatement($this->authUserId(), $businessId, $year);

        return $this->render('admin/reports/business_status', [
            'business'  => $business,
            'statement' => $statement,
            'years'     => $years,
            'year'      => $year,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function business(int $businessId): ?array
    {
        try {
            return service('businessService')->get($this->authUserId(), $businessId);
        } catch (NotFoundException) {
            return null;
        }
    }
}
