<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>장부 엑셀·CSV 일괄 업로드 <span class="muted">— <?= esc($business['name']) ?></span></h1>

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
    <a href="/admin/businesses/<?= $bid ?>/ledger" class="btn">장부로 이동</a>
<?php else: ?>
    <form class="card" method="post" action="/admin/businesses/<?= $bid ?>/ledger/import" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <p class="muted">
            엑셀(<code>.xlsx</code>, <code>.xls</code>) 또는 CSV 파일을 올리면 <strong>헤더(첫 행)를 읽어 컬럼을 자동으로 매핑</strong>합니다.
            컬럼 순서는 상관없으며, 업로드 후 매핑을 확인·수정할 수 있습니다.<br>
            표준 항목: <strong>날짜, 구분(수입/비용), 계정과목, 거래내용, 거래처, 금액, 비고(증빙)</strong><br>
            · 날짜: <code>2024-03-15</code> (. / 구분자 허용)<br>
            · 금액: 공급가액 (부가세는 비고 기준 자동계산)<br>
            · 계정과목·거래처는 생략 가능(거래처 미등록 시 해당 행 건너뜀).<br>
            엑셀 CSV(CP949 인코딩)도 지원합니다.
        </p>
        <label for="csv">엑셀 · CSV 파일</label>
        <input type="file" id="csv" name="csv" accept=".csv,.txt,.xls,.xlsx" required>
        <div class="actions">
            <button type="submit" class="btn">업로드</button>
            <a href="/admin/businesses/<?= $bid ?>/ledger" class="btn secondary">취소</a>
        </div>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>
