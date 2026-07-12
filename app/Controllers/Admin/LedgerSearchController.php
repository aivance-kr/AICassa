<?php

namespace App\Controllers\Admin;

use App\Exceptions\DomainException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 자연어 장부 검색(Admin). 자연어 질의를 안전한 필터로 해석해 장부 목록으로 리다이렉트한다.
 * 검색 자체는 기존 LedgerController::index() 필터 경로를 재사용한다(단일 렌더링 경로·URL 공유).
 */
class LedgerSearchController extends BaseAdminController
{
    /**
     * 자연어(q)를 필터로 변환해 장부 목록으로 리다이렉트한다.
     * GET /admin/businesses/{businessId}/ledger/search?q=...
     */
    public function search(int $businessId): RedirectResponse
    {
        $base = "/admin/businesses/{$businessId}/ledger";

        try {
            // 소유권 검증(미소유 시 도메인 예외).
            service('businessService')->get($this->authUserId(), $businessId);
        } catch (DomainException $e) {
            return redirect()->to('/admin/businesses')->with('error', $e->getMessage());
        }

        $query = trim((string) $this->request->getGet('q'));
        if ($query === '') {
            return redirect()->to($base);
        }

        $filter = service('ledgerQueryParserService')->parse($businessId, $query);

        // 해석된 필터를 쿼리스트링으로 넘겨 index 가 렌더링하게 한다(필터 노출·수정·공유 가능).
        $params      = $filter->toQueryParams();
        $params['q'] = $query; // 검색창에 원문을 되살리기 위해 유지
        $url         = $base . '?' . http_build_query($params);

        return redirect()->to($url);
    }
}
