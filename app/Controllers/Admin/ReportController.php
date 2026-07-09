<?php

namespace App\Controllers\Admin;

use App\DTOs\InventoryData;
use App\Exceptions\NotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 리포트(Admin). 영업현황표 + 종합소득세 신고서식.
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
     * 종합소득세 신고서식(소득금액계산서·필요경비명세서·감가상각조정명세서) + 재고 입력.
     */
    public function taxForms(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $years     = service('ledgerService')->availableYears($this->authUserId(), $businessId);
        $requested = $this->request->getGet('fiscal_year');
        $year      = $requested !== null && $requested !== ''
            ? (int) $requested
            : ($years[0] ?? (int) date('Y'));

        $tax = service('taxFormService');

        return $this->render('admin/reports/tax_forms', [
            'business'     => $business,
            'years'        => $years,
            'year'         => $year,
            'statement'    => $tax->incomeStatement($this->authUserId(), $businessId, $year),
            'depreciation' => $tax->depreciationAdjustment($this->authUserId(), $businessId, $year),
        ]);
    }

    /**
     * 재고(기초·기말 상품/재료) 저장.
     */
    public function saveInventory(int $businessId): RedirectResponse
    {
        $year = (int) $this->request->getPost('fiscal_year');

        try {
            service('taxFormService')->saveInventory(
                $this->authUserId(),
                $businessId,
                $year,
                InventoryData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return redirect()->to("/admin/businesses/{$businessId}/reports/tax-forms?fiscal_year={$year}")
            ->with('message', '재고가 저장되었습니다.');
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
