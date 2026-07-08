<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid    = $business['id'];
    $isEdit = $partner !== null;
    $action = $isEdit
        ? "/admin/businesses/{$bid}/partners/" . $partner['id']
        : "/admin/businesses/{$bid}/partners";
    $val = static fn (string $k): string => (string) (old($k) ?? ($partner[$k] ?? ''));
?>
<h1><?= $isEdit ? '거래처 수정' : '거래처 등록' ?> <span class="muted">— <?= esc($business['name']) ?></span></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <label for="name">거래처 상호 <span style="color:#dc2626">*</span></label>
    <input type="text" id="name" name="name" value="<?= esc($val('name')) ?>" required>

    <label for="biz_reg_no">사업자등록번호</label>
    <input type="text" id="biz_reg_no" name="biz_reg_no" value="<?= esc($val('biz_reg_no')) ?>" placeholder="123-45-67890">

    <label for="phone">연락처</label>
    <input type="text" id="phone" name="phone" value="<?= esc($val('phone')) ?>">

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '등록' ?></button>
        <a href="/admin/businesses/<?= $bid ?>/partners" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>
