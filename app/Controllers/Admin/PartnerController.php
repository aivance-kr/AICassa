<?php

namespace App\Controllers\Admin;

use App\DTOs\PartnerData;
use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 거래처 관리(Admin). 항상 사업장(business) 스코프 하위에서 동작한다.
 */
class PartnerController extends BaseAdminController
{
    /**
     * 거래처 목록.
     */
    public function index(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/partners/index', [
            'business' => $business,
            'partners' => service('partnerService')->listForBusiness($this->authUserId(), $businessId),
        ]);
    }

    /**
     * 등록 폼.
     */
    public function new(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/partners/form', ['business' => $business, 'partner' => null]);
    }

    /**
     * 등록 처리.
     */
    public function create(int $businessId): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('partnerService')->create(
                $this->authUserId(),
                $businessId,
                PartnerData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        } catch (AlreadyExistsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/partners")
            ->with('message', '거래처가 등록되었습니다.');
    }

    /**
     * 수정 폼.
     */
    public function edit(int $businessId, int $partnerId): RedirectResponse|string
    {
        try {
            $partner = service('partnerService')->get($this->authUserId(), $businessId, $partnerId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/partners")
                ->with('error', '거래처를 찾을 수 없습니다.');
        }

        return $this->render('admin/partners/form', [
            'business' => $this->business($businessId),
            'partner'  => $partner,
        ]);
    }

    /**
     * 수정 처리.
     */
    public function update(int $businessId, int $partnerId): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('partnerService')->update(
                $this->authUserId(),
                $businessId,
                $partnerId,
                PartnerData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/partners")
                ->with('error', '거래처를 찾을 수 없습니다.');
        } catch (AlreadyExistsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/partners")
            ->with('message', '거래처가 수정되었습니다.');
    }

    /**
     * 삭제 처리.
     */
    public function delete(int $businessId, int $partnerId): RedirectResponse
    {
        try {
            service('partnerService')->delete($this->authUserId(), $businessId, $partnerId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/partners")
                ->with('error', '거래처를 찾을 수 없습니다.');
        }

        return redirect()->to("/admin/businesses/{$businessId}/partners")
            ->with('message', '거래처가 삭제되었습니다.');
    }

    /**
     * CSV 업로드 폼.
     */
    public function importForm(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/partners/import', ['business' => $business, 'result' => null]);
    }

    /**
     * CSV 업로드 처리(일괄등록).
     */
    public function import(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $file = $this->request->getFile('csv');
        if ($file === null || ! $file->isValid() || ! in_array($file->getExtension(), ['csv', 'txt'], true)) {
            return redirect()->back()->with('error', 'CSV 파일을 선택하세요.');
        }

        $raw = (string) file_get_contents($file->getTempName());
        $csv = $this->toUtf8($raw);

        $rows   = service('partnerService')->parseCsv($csv);
        $result = service('partnerService')->importFromRows($this->authUserId(), $businessId, $rows);

        return $this->render('admin/partners/import', ['business' => $business, 'result' => $result]);
    }

    /**
     * 사업장 소유권 확인 후 반환. 없으면 null.
     *
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

    /**
     * 엑셀에서 저장한 CSV는 CP949(EUC-KR)인 경우가 많아 UTF-8로 변환한다.
     */
    private function toUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return (string) mb_convert_encoding($raw, 'UTF-8', 'CP949');
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
