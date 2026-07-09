<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>거래처 CSV 일괄 업로드 <span class="muted">— <?= esc($business['name']) ?></span></h1>

<?php if ($result !== null): ?>
    <div class="flash ok">
        등록 <?= (int) $result->imported ?>건, 건너뜀 <?= (int) $result->skipped ?>건
    </div>
    <?php if ($result->errors !== []): ?>
        <table class="tbl" style="margin-bottom:16px;">
            <thead><tr><th style="width:80px;">행</th><th>사유</th></tr></thead>
            <tbody>
            <?php foreach ($result->errors as $err): ?>
                <tr><td><?= (int) $err['row'] ?></td><td><?= esc($err['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <a href="/admin/businesses/<?= $bid ?>/partners" class="btn">거래처 목록으로</a>
<?php else: ?>
    <form class="card" method="post" action="/admin/businesses/<?= $bid ?>/partners/import" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <p class="muted">
            CSV 컬럼 순서: <strong>거래처상호, 사업자등록번호, 연락처</strong><br>
            첫 행이 머리글(상호…)이면 자동으로 건너뜁니다. 엑셀 CSV(CP949)도 지원합니다.<br>
            사업자등록번호가 비었거나 중복된 행은 건너뜁니다.
        </p>
        <label for="csv">CSV 파일</label>
        <input type="file" id="csv" name="csv" accept=".csv,.txt" required>
        <div class="actions">
            <button type="submit" class="btn">업로드</button>
            <a href="/admin/businesses/<?= $bid ?>/partners" class="btn secondary">취소</a>
        </div>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>
