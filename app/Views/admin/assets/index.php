<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid    = $business['id'];
    $method = static fn (?string $v): string => match ($v) {
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
        default             => '-',
    };
?>
<h1>자산대장</h1>
<p class="page-desc">사업용 자산을 등록하고 감가상각비를 계산합니다. 취득·처분·감가상각 내역은 장부에 자동 반영됩니다.</p>

<div class="toolbar">
    <a href="/admin/businesses/<?= $bid ?>/assets/new" class="btn">+ 자산 등록</a>
</div>

<?php if ($assets === []): ?>
    <p class="muted">등록된 자산이 없습니다.</p>
<?php else: ?>
    <table class="tbl">
        <thead>
            <tr>
                <th>자산종류</th><th>자산명</th><th>취득일</th><th class="num">취득금액</th>
                <th>상각방법</th><th class="num">내용연수</th><th class="num">상각률</th><th>처분일</th><th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
            <tr>
                <td><?= esc($a['asset_type']) ?></td>
                <td><?= esc($a['name']) ?></td>
                <td><?= esc($a['acquired_at']) ?></td>
                <td class="num"><?= number_format((int) $a['acquisition_cost']) ?></td>
                <td><?= esc($method($a['depreciation_method'])) ?></td>
                <td class="num"><?= $a['useful_life'] !== null ? (int) $a['useful_life'] : '-' ?></td>
                <td class="num"><?= $a['depreciation_rate'] !== null ? esc($a['depreciation_rate']) : '-' ?></td>
                <td><?= esc($a['disposed_at'] ?? '-') ?></td>
                <td class="actions-cell">
                    <a href="/admin/businesses/<?= $bid ?>/assets/<?= $a['id'] ?>/schedule">감가상각</a>
                    <a href="/admin/businesses/<?= $bid ?>/assets/<?= $a['id'] ?>/edit">수정</a>
                    <a href="#" class="del" onclick="doPost('/admin/businesses/<?= $bid ?>/assets/<?= $a['id'] ?>/delete','삭제하시겠습니까?');return false;">삭제</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form id="actionForm" method="post" style="display:none;"><?= csrf_field() ?></form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function doPost(url, msg) {
    if (!msg || confirm(msg)) {
        const f = document.getElementById('actionForm');
        f.action = url;
        f.submit();
    }
}
</script>
<?= $this->endSection() ?>
