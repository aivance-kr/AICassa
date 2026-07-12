<?php

namespace App\Controllers\Admin;

use App\Models\IndustryCodeModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 업종코드(표준산업분류) 검색(Admin). 사업장 폼 자동완성용 JSON 응답.
 * 사업장 스코프 밖 참조 데이터이므로 세션 인증만 요구한다.
 */
class IndustryCodeController extends BaseAdminController
{
    /**
     * 업종코드·업종명 부분일치 검색. 정확 일치 코드 존재 여부도 함께 반환한다.
     * GET /admin/industry-codes/search?q=키워드
     */
    public function search(): ResponseInterface
    {
        $keyword = trim((string) $this->request->getGet('q'));
        $model   = model(IndustryCodeModel::class);

        return $this->response->setJSON([
            'results' => $model->search($keyword),
            'exists'  => $model->existsByCode($keyword),
        ]);
    }
}
