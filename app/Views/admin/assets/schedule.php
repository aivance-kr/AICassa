<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>감가상각 스케줄 <span class="muted">— <?= esc($asset['name']) ?></span></h1>

<div class="toolbar">
    <a href="/admin/businesses/<?= $bid ?>/assets" class="btn secondary">← 자산대장</a>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="muted" style="font-size:13px;">
        취득 <?= esc($asset['acquired_at']) ?> ·
        취득금액 <?= number_format((int) $asset['acquisition_cost']) ?>원 ·
        상각률 <?= $asset['depreciation_rate'] !== null ? esc($asset['depreciation_rate']) : '-' ?>
    </div>
</div>

<?php if ($schedule === []): ?>
    <p class="muted">상각방법·내용연수가 입력되어야 감가상각을 계산할 수 있습니다.
        <a href="/admin/businesses/<?= $bid ?>/assets/<?= $asset['id'] ?>/edit">자산 수정</a>에서 입력하세요.</p>
<?php else: ?>
    <table class="tbl">
        <thead>
            <tr><th>연도</th><th class="num">감가상각비</th><th class="num">감가상각누계액</th><th class="num">기말 장부가액</th><th>장부 반영</th></tr>
        </thead>
        <tbody>
        <?php foreach ($schedule as $line): ?>
            <tr>
                <td><?= (int) $line['year'] ?></td>
                <td class="num"><?= number_format((int) $line['depreciation']) ?></td>
                <td class="num"><?= number_format((int) $line['accumulated']) ?></td>
                <td class="num"><?= number_format((int) $line['book_value']) ?></td>
                <td>
                    <form method="post" action="/admin/businesses/<?= $bid ?>/assets/<?= $asset['id'] ?>/depreciation" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year" value="<?= (int) $line['year'] ?>">
                        <button type="submit" class="btn secondary" style="padding:2px 8px; font-size:12px;">비용 반영</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:8px;">‘비용 반영’은 해당 연도 감가상각비를 장부에 비용(감가상각비)으로 기록합니다(재실행 시 갱신).</p>
<?php endif; ?>
<?= $this->endSection() ?>
