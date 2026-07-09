<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid    = $business['id'];
    $isEdit = $asset !== null;
    $action = $isEdit
        ? "/admin/businesses/{$bid}/assets/" . $asset['id']
        : "/admin/businesses/{$bid}/assets";
    $val = static fn (string $k, mixed $d = '') => old($k) ?? ($asset[$k] ?? $d);
?>
<h1><?= $isEdit ? '자산 수정' : '자산 등록' ?> <span class="muted">— <?= esc($business['name']) ?></span></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <label for="asset_type">자산종류 <span style="color:#dc2626">*</span></label>
    <select id="asset_type" name="asset_type" required>
        <option value="">선택</option>
        <?php foreach ($assetTypes as $t): ?>
            <option value="<?= esc($t['name']) ?>" <?= (string) $val('asset_type') === $t['name'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="name">자산명 <span style="color:#dc2626">*</span></label>
    <input type="text" id="name" name="name" value="<?= esc($val('name')) ?>" required>

    <label for="acquired_at">취득일 <span style="color:#dc2626">*</span></label>
    <input type="date" id="acquired_at" name="acquired_at" value="<?= esc($val('acquired_at')) ?>" required>

    <label for="acquisition_cost">취득금액 <span style="color:#dc2626">*</span></label>
    <input type="text" id="acquisition_cost" name="acquisition_cost" inputmode="numeric" value="<?= esc($val('acquisition_cost')) ?>" required>

    <label for="depreciation_method">상각방법</label>
    <select id="depreciation_method" name="depreciation_method">
        <option value="">선택 안 함</option>
        <?php foreach ($methods as $m): ?>
            <option value="<?= $m->value ?>" <?= (string) $val('depreciation_method') === $m->value ? 'selected' : '' ?>><?= esc($m->label()) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="useful_life">내용연수(년)</label>
    <input type="number" id="useful_life" name="useful_life" min="2" max="60" value="<?= esc($val('useful_life')) ?>">
    <p class="muted" style="margin-top:4px;">상각방법과 내용연수를 입력하면 상각률이 자동 조회됩니다.</p>

    <label for="disposed_at">처분일(선택)</label>
    <input type="date" id="disposed_at" name="disposed_at" value="<?= esc($val('disposed_at')) ?>">

    <label for="disposal_amount">처분금액(선택)</label>
    <input type="text" id="disposal_amount" name="disposal_amount" inputmode="numeric" value="<?= esc($val('disposal_amount')) ?>">

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '등록' ?></button>
        <a href="/admin/businesses/<?= $bid ?>/assets" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>
