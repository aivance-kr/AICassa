<?= $this->extend('operator/layout') ?>

<?= $this->section('content') ?>
<h1>연도별 세법 파라미터</h1>
<p class="page-desc">부가세율·감가상각 기준·신고서식 버전 등을 귀속연도별로 관리합니다. 특정 연도가 없으면 그 이하 가장 최근 연도값이 적용됩니다.</p>

<form class="toolbar" method="post" action="/operator/tax-parameters/years">
    <?= csrf_field() ?>
    <input type="number" name="fiscal_year" placeholder="예: 2026" min="2000" max="2099"
        style="width:140px;" required>
    <button type="submit" class="btn">새 연도 추가(최신값 복제)</button>
</form>

<?php if ($years === []): ?>
    <p class="muted">등록된 연도가 없습니다. 먼저 시더(<code>php spark db:seed TaxParameterSeeder</code>)를 실행하거나 새 연도를 추가하세요.</p>
<?php else: ?>
    <table class="tbl">
        <thead>
            <tr>
                <th>귀속연도</th>
                <th>파라미터 수</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($years as $row): ?>
                <?php $y = (int) $row['fiscal_year']; ?>
                <tr>
                    <td><strong><?= esc($y) ?></strong>년</td>
                    <td><?= esc($row['param_count']) ?>개</td>
                    <td>
                        <a href="/operator/tax-parameters/<?= $y ?>">상세</a>
                        <a href="/operator/tax-parameters/<?= $y ?>/edit">수정</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?= $this->endSection() ?>
