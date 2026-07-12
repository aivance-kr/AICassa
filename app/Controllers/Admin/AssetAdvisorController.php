<?php

namespace App\Controllers\Admin;

use App\Exceptions\DomainException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 자산 등록 어시스트(Admin). 자산 등록 폼에서 품목명으로 분류·상각방법·내용연수·소액자산 여부를 제안한다.
 * 제안만 수행하며, 실제 저장은 기존 AssetController::create() 경로를 탄다.
 */
class AssetAdvisorController extends BaseAdminController
{
    /**
     * 품목명(+취득금액·취득일)으로 자산 등록 초안을 제안해 JSON 으로 반환한다.
     * POST /admin/businesses/{businessId}/assets/advise
     */
    public function suggest(int $businessId): ResponseInterface
    {
        // OCR·계정분류 엔드포인트와 동일하게, CSRF 재생성 대응으로 새 토큰을 함께 싣는다.
        $csrf = ['csrf_name' => csrf_token(), 'csrf_hash' => csrf_hash()];

        try {
            // 사업장 소유권 검증(미소유 시 도메인 예외).
            service('businessService')->get($this->authUserId(), $businessId);

            $name = trim((string) $this->request->getPost('name'));
            if ($name === '') {
                return $this->response->setStatusCode(422)->setJSON($csrf + [
                    'error' => ['code' => 'VALIDATION_ERROR', 'message' => '자산명을 입력하세요.'],
                ]);
            }

            $advice = service('assetAdvisorService')->advise(
                $businessId,
                $name,
                $this->postedInt('acquisition_cost'),
                $this->postedDate('acquired_at'),
            );
        } catch (DomainException $e) {
            return $this->response->setStatusCode($e->httpStatusCode())->setJSON($csrf + [
                'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ]);
        }

        return $this->response->setJSON($advice->toArray() + $csrf);
    }

    /**
     * POST 정수 파싱(쉼표·공백 제거). 숫자가 아니면 null.
     */
    private function postedInt(string $key): ?int
    {
        $raw = str_replace([',', ' '], '', (string) $this->request->getPost($key));

        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * POST 날짜(Y-m-d) 파싱. 형식이 아니면 null.
     */
    private function postedDate(string $key): ?string
    {
        $raw = (string) $this->request->getPost($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }
}
