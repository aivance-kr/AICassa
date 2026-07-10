<?php

namespace App\Controllers\Admin;

use App\Enums\EntryType;
use App\Exceptions\DomainException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 계정과목 자동분류(Admin). 장부 입력 폼에서 거래내용으로 계정과목을 추천한다.
 * 추천만 수행하며, 실제 저장은 기존 LedgerController::create() 경로를 탄다.
 */
class AccountClassifierController extends BaseAdminController
{
    /**
     * 거래내용(+거래처)으로 계정과목을 추천해 JSON 으로 반환한다.
     * POST /admin/businesses/{businessId}/ledger/classify-account
     */
    public function suggest(int $businessId): ResponseInterface
    {
        // OCR 엔드포인트와 동일하게, CSRF 재생성 대응으로 새 토큰을 함께 싣는다.
        $csrf = ['csrf_name' => csrf_token(), 'csrf_hash' => csrf_hash()];

        try {
            $business  = service('businessService')->get($this->authUserId(), $businessId);
            $entryType = EntryType::tryFrom((string) $this->request->getPost('entry_type'));
            if ($entryType !== EntryType::Income && $entryType !== EntryType::Expense) {
                return $this->response->setStatusCode(422)->setJSON($csrf + [
                    'error' => ['code' => 'VALIDATION_ERROR', 'message' => '구분은 수입/비용만 허용합니다.'],
                ]);
            }

            $partnerName = $this->request->getPost('partner_name');
            $suggestion  = service('accountClassifierService')->suggest(
                $businessId,
                $entryType,
                (string) $this->request->getPost('description'),
                is_string($partnerName) && $partnerName !== '' ? $partnerName : null,
                (bool) $business['is_manufacturing'],
            );
        } catch (DomainException $e) {
            return $this->response->setStatusCode($e->httpStatusCode())->setJSON($csrf + [
                'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ]);
        }

        return $this->response->setJSON($suggestion->toArray() + $csrf);
    }
}
