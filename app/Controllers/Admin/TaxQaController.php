<?php

namespace App\Controllers\Admin;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 세무 Q&A 챗봇(Admin · 전역). docs 근거 기반 인용 응답.
 * 답변은 제안(참고용)이며 근거로 확인되지 않으면 "확인 불가"로 응답한다.
 */
class TaxQaController extends BaseAdminController
{
    /**
     * Q&A 화면.
     */
    public function index(): string
    {
        return $this->render('admin/tax_qa/index', [
            'docs' => service('taxQaService')->docFiles(),
        ]);
    }

    /**
     * 질문을 받아 근거 기반 답변을 JSON 으로 반환한다.
     * POST /admin/tax-qa/ask
     */
    public function ask(): ResponseInterface
    {
        // 다른 어시스트 엔드포인트와 동일하게, CSRF 재생성 대응으로 새 토큰을 함께 싣는다.
        $csrf     = ['csrf_name' => csrf_token(), 'csrf_hash' => csrf_hash()];
        $question = trim((string) $this->request->getPost('question'));

        if ($question === '') {
            return $this->response->setStatusCode(422)->setJSON($csrf + [
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => '질문을 입력하세요.'],
            ]);
        }

        $answer = service('taxQaService')->ask($question);

        return $this->response->setJSON($answer->toArray() + $csrf);
    }

    /**
     * 출처 섹션 원문 뷰어. doc 은 화이트리스트, anchor 는 형식 검증으로 경로우회를 차단한다.
     * GET /admin/tax-qa/source?doc=&anchor=
     */
    public function source(): RedirectResponse|string
    {
        $service = service('taxQaService');
        $doc     = (string) $this->request->getGet('doc');
        $anchor  = (string) $this->request->getGet('anchor');

        if (! in_array($doc, $service->docFiles(), true) || preg_match('/^sec-\d+$/', $anchor) !== 1) {
            return redirect()->to('/admin/tax-qa')->with('error', '잘못된 출처 요청입니다.');
        }

        $section = $service->section($doc, $anchor);
        if ($section === null) {
            return redirect()->to('/admin/tax-qa')->with('error', '출처 섹션을 찾을 수 없습니다.');
        }

        return $this->render('admin/tax_qa/source', ['section' => $section]);
    }
}
