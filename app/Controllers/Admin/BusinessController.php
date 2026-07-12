<?php

namespace App\Controllers\Admin;

use App\DTOs\BusinessData;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 사업장 관리(Admin). 로그인 사용자 스코프로만 조작한다.
 */
class BusinessController extends BaseAdminController
{
    /**
     * 사업장 목록.
     */
    public function index(): string
    {
        return $this->render('admin/businesses/index', [
            'businesses' => service('businessService')->listForUser($this->authUserId()),
        ]);
    }

    /**
     * 등록 폼.
     */
    public function new(): string
    {
        return $this->render('admin/businesses/form', [
            'business' => null,
        ]);
    }

    /**
     * 등록 처리.
     */
    public function create(): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('businessService')->create(
                $this->authUserId(),
                BusinessData::fromArray($this->request->getPost()),
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to('/admin/businesses')->with('message', '사업장이 등록되었습니다.');
    }

    /**
     * 수정 폼.
     */
    public function edit(int $id): RedirectResponse|string
    {
        try {
            $business = service('businessService')->get($this->authUserId(), $id);
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/businesses/form', ['business' => $business]);
    }

    /**
     * 수정 처리.
     */
    public function update(int $id): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('businessService')->update(
                $this->authUserId(),
                $id,
                BusinessData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to('/admin/businesses')->with('message', '사업장이 수정되었습니다.');
    }

    /**
     * 삭제 처리.
     */
    public function delete(int $id): RedirectResponse
    {
        try {
            service('businessService')->delete($this->authUserId(), $id);
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return redirect()->to('/admin/businesses')->with('message', '사업장이 삭제되었습니다.');
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'name'       => 'required|max_length[200]',
            'biz_reg_no' => 'permit_empty|max_length[20]',
        ];
    }
}
