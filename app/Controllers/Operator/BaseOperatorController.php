<?php

namespace App\Controllers\Operator;

use App\Controllers\BaseController;

/**
 * 운영자(세법 파라미터 관리) 컨트롤러 공통 기반.
 * 사업장 Admin(Shield) 과 분리된 영역으로, 세션의 운영자 식별자를 뷰에 자동 병합한다.
 */
abstract class BaseOperatorController extends BaseController
{
    /**
     * @var list<string>
     */
    protected $helpers = ['form', 'url'];

    /**
     * 공통 데이터를 병합해 운영자 뷰를 렌더링한다.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $shared = [
            'operatorId' => (string) (session()->get('operator_id') ?? '운영자'),
        ];

        return view($view, array_merge($shared, $data));
    }
}
