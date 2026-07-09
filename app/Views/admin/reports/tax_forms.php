<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid   = $business['id'];
    $inv   = $statement['inventory'];
    $money = static fn (int $n): string => number_format($n);
    $methodLabel = static fn (?string $v): string => match ($v) {
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
        default             => '-',
    };
?>
<h1>종합소득세 신고서식 <span class="muted">— <?= esc($business['name']) ?> · <?= (int) $year ?>년 귀속</span></h1>

<div class="toolbar">
    <form method="get" style="display:flex; gap:8px; align-items:center;">
        <label class="muted" style="font-size:13px;">귀속연도</label>
        <select name="fiscal_year" onchange="this.form.submit()">
            <?php if ($years === []): ?><option value="<?= (int) $year ?>"><?= (int) $year ?></option><?php endif; ?>
            <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <a href="/admin/businesses/<?= $bid ?>/reports/tax-forms/print?fiscal_year=<?= (int) $year ?>" class="btn" target="_blank" rel="noopener">인쇄 (PDF)</a>
    <a href="/admin/businesses/<?= $bid ?>/reports/tax-forms/excel?fiscal_year=<?= (int) $year ?>" class="btn secondary">엑셀 다운로드</a>
    <a href="/admin/businesses/<?= $bid ?>/ledger" class="btn secondary">장부</a>
    <a href="/admin/businesses" class="btn secondary">← 사업장 목록</a>
</div>

<!-- 재고 입력(매출원가 계산용) -->
<h2 style="font-size:16px; margin-top:8px;">재고 입력 <span class="muted" style="font-weight:400; font-size:13px;">(매출원가 계산)</span></h2>
<form class="card" method="post" action="/admin/businesses/<?= $bid ?>/reports/tax-forms/inventory">
    <?= csrf_field() ?>
    <input type="hidden" name="fiscal_year" value="<?= (int) $year ?>">
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px;">
        <div><label>기초 상품재고</label><input type="text" name="goods_begin" inputmode="numeric" value="<?= $money($inv['goods_begin']) ?>"></div>
        <div><label>기말 상품재고</label><input type="text" name="goods_end" inputmode="numeric" value="<?= $money($inv['goods_end']) ?>"></div>
        <div><label>기초 재료재고</label><input type="text" name="materials_begin" inputmode="numeric" value="<?= $money($inv['materials_begin']) ?>"></div>
        <div><label>기말 재료재고</label><input type="text" name="materials_end" inputmode="numeric" value="<?= $money($inv['materials_end']) ?>"></div>
    </div>
    <div class="actions"><button type="submit" class="btn secondary">재고 저장</button></div>
</form>

<!-- ① 간편장부 소득금액계산서 -->
<h2 style="font-size:16px;">① 간편장부 소득금액계산서</h2>
<table class="tbl" style="margin-bottom:24px;">
    <tbody>
        <tr><th style="width:40%;">총수입금액</th><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
        <tr><th>필요경비</th><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
        <tr class="total"><th>소득금액 (총수입금액 − 필요경비)</th><td class="num"><strong><?= $money($statement['income_amount']) ?></strong></td></tr>
    </tbody>
</table>

<!-- ② 총수입금액 및 필요경비명세서 -->
<h2 style="font-size:16px;">② 총수입금액 및 필요경비명세서</h2>
<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
    <div>
        <h3 style="font-size:14px; color:var(--muted);">수입</h3>
        <table class="tbl">
            <tbody>
            <?php foreach ($statement['revenue_by_account'] as $name => $amt): ?>
                <tr><th><?= esc($name) ?></th><td class="num"><?= $money($amt) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><th>계</th><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
            </tbody>
        </table>
    </div>
    <div>
        <h3 style="font-size:14px; color:var(--muted);">필요경비</h3>
        <table class="tbl">
            <tbody>
            <tr><th>매출원가(상품)</th><td class="num"><?= $money($statement['goods_cogs']) ?></td></tr>
            <?php if ($statement['materials_cost'] !== 0): ?>
                <tr><th>재료비</th><td class="num"><?= $money($statement['materials_cost']) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($statement['expense_by_account'] as $name => $amt): ?>
                <?php if (in_array($name, ['상품매입', '재료매입'], true)) {
                    continue;
                } ?>
                <tr><th><?= esc($name) ?></th><td class="num"><?= $money($amt) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><th>계</th><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ③ 감가상각비 조정명세서 -->
<h2 style="font-size:16px;">③ 감가상각비 조정명세서</h2>
<?php if ($depreciation === []): ?>
    <p class="muted"><?= (int) $year ?>년 감가상각 대상 자산이 없습니다.</p>
<?php else: ?>
    <table class="tbl">
        <thead>
            <tr><th>자산명</th><th>상각방법</th><th class="num">취득금액</th><th class="num">당기 감가상각비</th><th class="num">감가상각누계액</th><th class="num">기말 장부가액</th></tr>
        </thead>
        <tbody>
        <?php foreach ($depreciation as $d): ?>
            <tr>
                <td><?= esc($d['name']) ?></td>
                <td><?= esc($methodLabel($d['method'])) ?></td>
                <td class="num"><?= $money($d['acquisition_cost']) ?></td>
                <td class="num"><?= $money($d['depreciation']) ?></td>
                <td class="num"><?= $money($d['accumulated']) ?></td>
                <td class="num"><?= $money($d['book_value']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<style>
    table.tbl th { text-align:left; }
    table.tbl td.num, table.tbl th.num { text-align:right; font-variant-numeric:tabular-nums; }
    table.tbl tr.total th, table.tbl tr.total td { background:#ecfdf5; font-weight:700; }
</style>
<?= $this->endSection() ?>
