<?php

namespace App\Controllers\Admin;

use App\Exceptions\DomainException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 영수증/세금계산서 사진 AI 판독(Admin). 장부 입력 폼 자동채움용 JSON 응답.
 * 판독만 수행하며 장부 등록 권한은 없다 — 최종 등록은 기존 LedgerController::create() 경로를 탄다.
 */
class ReceiptOcrController extends BaseAdminController
{
    /**
     * 업로드한 영수증 이미지를 판독해 폼 필드 값을 JSON 으로 반환한다.
     * POST /admin/businesses/{businessId}/ledger/receipts/recognize
     *
     * CSRF 재생성(regenerate=true) 대응: 응답에 새 토큰을 실어 폼 hidden 필드를 갱신하게 한다.
     */
    public function recognize(int $businessId): ResponseInterface
    {
        // CSRF 재생성(regenerate=true)은 성공/실패와 무관하게 before 필터에서 이미 발생하므로,
        // 모든 응답에 새 토큰을 실어 폼 hidden 필드를 갱신하게 한다(이후 저장 제출 실패 방지).
        $csrf = ['csrf_name' => csrf_token(), 'csrf_hash' => csrf_hash()];

        try {
            $result = service('receiptOcrService')->recognize(
                $this->authUserId(),
                $businessId,
                $this->request->getFile('receipt'),
            );
        } catch (DomainException $e) {
            return $this->response->setStatusCode($e->httpStatusCode())->setJSON($csrf + [
                'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ]);
        }

        return $this->response->setJSON($result + $csrf);
    }
}
