<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>장부 CSV 일괄 업로드 <span class="muted">— <?= esc($business['name']) ?></span></h1>

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
            CSV 컬럼 순서: <strong>날짜, 구분, 계정과목, 거래내용, 거래처, 금액, 비고</strong><br>
            · 날짜: <code>2024-03-15</code> (. / 구분자 허용)<br>
            · 구분: <code>수입</code> 또는 <code>비용</code><br>
            · 계정과목: 매출·임차료 등 (해당 구분의 계정명, 생략 가능)<br>
            · 거래처: 등록된 거래처 상호 (생략 가능, 미등록 시 해당 행 건너뜀)<br>
            · 금액: 공급가액 (부가세는 비고 기준 자동계산)<br>
            · 비고: 세금계산서·계산서·신용카드·현금영수증·간이영수증·기타<br>
            첫 행이 머리글(날짜…)이면 자동으로 건너뜁니다. 엑셀 CSV(CP949)도 지원합니다.
        </p>
        <label for="csv">CSV 파일</label>
        <input type="file" id="csv" name="csv" accept=".csv,.txt" required>
        <div class="actions">
            <button type="submit" class="btn">업로드</button>
            <a href="/admin/businesses/<?= $bid ?>/ledger" class="btn secondary">취소</a>
        </div>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>
