<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $isEdit = $business !== null;
    $action = $isEdit ? '/admin/businesses/' . $business['id'] : '/admin/businesses';
    // old() 우선(검증 실패 재입력), 그다음 기존 값
    $val = static fn (string $k): string => (string) (old($k) ?? ($business[$k] ?? ''));
?>
<h1><?= $isEdit ? '사업장 수정' : '사업장 등록' ?></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <label for="name">상호 <span style="color:#dc2626">*</span></label>
    <input type="text" id="name" name="name" value="<?= esc($val('name')) ?>" required>

    <label for="owner_name">대표자명</label>
    <input type="text" id="owner_name" name="owner_name" value="<?= esc($val('owner_name')) ?>">

    <label for="biz_reg_no">사업자등록번호</label>
    <input type="text" id="biz_reg_no" name="biz_reg_no" value="<?= esc($val('biz_reg_no')) ?>" placeholder="123-45-67890">

    <label for="industry_name">업종</label>
    <input type="text" id="industry_name" name="industry_name" value="<?= esc($val('industry_name')) ?>">

    <label for="industry_code">주업종코드</label>
    <input type="text" id="industry_code" name="industry_code" value="<?= esc($val('industry_code')) ?>">

    <label for="address">사업장 소재지</label>
    <input type="text" id="address" name="address" value="<?= esc($val('address')) ?>">

    <label for="phone">연락처</label>
    <input type="text" id="phone" name="phone" value="<?= esc($val('phone')) ?>">

    <div class="checkbox">
        <input type="checkbox" id="is_manufacturing" name="is_manufacturing" value="1"
            <?= (old('is_manufacturing') ?? ($business['is_manufacturing'] ?? 0)) ? 'checked' : '' ?>>
        <label for="is_manufacturing" style="margin:0;">제조업 (재료매입·제조경비 계정 사용)</label>
    </div>

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '등록' ?></button>
        <a href="/admin/businesses" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>
