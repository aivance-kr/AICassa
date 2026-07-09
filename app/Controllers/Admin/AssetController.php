<?php

namespace App\Controllers\Admin;

use App\DTOs\AssetData;
use App\Enums\AccountCategory;
use App\Enums\DepreciationMethod;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AccountModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 자산대장(Admin). 사업용 자산 관리 + 감가상각 스케줄·장부 반영.
 */
class AssetController extends BaseAdminController
{
    public function index(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/assets/index', [
            'business' => $business,
            'assets'   => service('assetService')->listForBusiness($this->authUserId(), $businessId),
        ]);
    }

    public function new(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/assets/form', $this->formData($business, null));
    }

    public function create(int $businessId): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('assetService')->create(
                $this->authUserId(),
                $businessId,
                AssetData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/assets")
            ->with('message', '자산이 등록되었습니다.');
    }

    public function edit(int $businessId, int $assetId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        try {
            $asset = service('assetService')->get($this->authUserId(), $businessId, $assetId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")->with('error', '자산을 찾을 수 없습니다.');
        }

        return $this->render('admin/assets/form', $this->formData($business, $asset));
    }

    public function update(int $businessId, int $assetId): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('assetService')->update(
                $this->authUserId(),
                $businessId,
                $assetId,
                AssetData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")->with('error', '자산을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/assets")->with('message', '자산이 수정되었습니다.');
    }

    public function delete(int $businessId, int $assetId): RedirectResponse
    {
        try {
            service('assetService')->delete($this->authUserId(), $businessId, $assetId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")->with('error', '자산을 찾을 수 없습니다.');
        }

        return redirect()->to("/admin/businesses/{$businessId}/assets")->with('message', '자산이 삭제되었습니다.');
    }

    /**
     * 감가상각 스케줄 조회.
     */
    public function schedule(int $businessId, int $assetId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        try {
            $asset    = service('assetService')->get($this->authUserId(), $businessId, $assetId);
            $schedule = service('assetService')->schedule($this->authUserId(), $businessId, $assetId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")->with('error', '자산을 찾을 수 없습니다.');
        }

        return $this->render('admin/assets/schedule', [
            'business' => $business,
            'asset'    => $asset,
            'schedule' => $schedule,
        ]);
    }

    /**
     * 특정 연도 감가상각비를 장부에 반영.
     */
    public function postDepreciation(int $businessId, int $assetId): RedirectResponse
    {
        $year = (int) $this->request->getPost('year');

        try {
            $entryId = service('assetService')->postDepreciation($this->authUserId(), $businessId, $assetId, $year);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")->with('error', '자산을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', implode(' ', $e->errors()));
        }

        $msg = $entryId !== null
            ? "{$year}년 감가상각비를 장부에 반영했습니다."
            : "{$year}년 감가상각비가 없습니다.";

        return redirect()->to("/admin/businesses/{$businessId}/assets/{$assetId}/schedule")->with('message', $msg);
    }

    /**
     * @param array<string, mixed>      $business
     * @param array<string, mixed>|null $asset
     *
     * @return array<string, mixed>
     */
    private function formData(array $business, ?array $asset): array
    {
        return [
            'business'   => $business,
            'asset'      => $asset,
            'assetTypes' => model(AccountModel::class)->forCategory(AccountCategory::Asset),
            'methods'    => DepreciationMethod::cases(),
            // 내용연수 미입력 시 적용될 업종 기준 기본값(폼 안내용)
            'autoUsefulLife' => service('assetService')->defaultUsefulLife($this->authUserId(), (int) $business['id']),
        ];
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

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'asset_type'       => 'required',
            'name'             => 'required|max_length[200]',
            'acquired_at'      => 'required|valid_date[Y-m-d]',
            'acquisition_cost' => 'required',
        ];
    }
}
