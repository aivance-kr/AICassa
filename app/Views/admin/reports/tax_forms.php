<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid   = $business['id'];
    $inv   = $statement['inventory'];
    $mfg   = $statement['manufacturing'];
    $money = static fn (int $n): string => number_format($n);
    $methodLabel = static fn (?string $v): string => match ($v) {
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
        default             => '-',
    };
?>
<h1>종합소득세 신고서식 <span class="muted">— <?= (int) $year ?>년 귀속</span></h1>
<p class="page-desc">장부·재고·자산 데이터로 소득금액계산서·필요경비명세서·감가상각조정명세서를 생성합니다. 인쇄(PDF)·엑셀로 내보낼 수 있습니다.</p>
<p class="muted" style="margin:-4px 0 12px; font-size:12px;">
    서식 버전: <strong><?= esc($formVersion['version']) ?></strong>
    <?php if (! $formVersion['supported']): ?>
        · <span style="color:#dc2626;">⚠ 이 귀속연도는 서식 정확성이 보장되지 않습니다</span>
    <?php endif; ?>
</p>

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
</div>

<!-- 인적사항 -->
<h2 style="font-size:16px;">인적사항</h2>
<table class="tbl" style="margin-bottom:20px;">
    <tbody>
        <tr><th style="width:15%;">성명</th><td style="width:35%;"><?= esc($business['owner_name'] ?? '') ?></td>
            <th style="width:15%;">생년월일</th><td><?= esc($business['birth_date'] ?? '') ?></td></tr>
        <tr><th>상호</th><td><?= esc($business['name']) ?></td>
            <th>사업자등록번호</th><td><?= esc($business['biz_reg_no'] ?? '') ?></td></tr>
        <tr><th>소재지</th><td><?= esc($business['address'] ?? '') ?></td>
            <th>전화번호</th><td><?= esc($business['phone'] ?? '') ?></td></tr>
        <tr><th>업종</th><td><?= esc($business['industry_name'] ?? '') ?></td>
            <th>주업종코드</th><td><?= esc($business['industry_code'] ?? '') ?></td></tr>
        <tr><th>소득종류</th><td><?= esc($business['income_type'] ?? '') ?></td>
            <th>제조업 여부</th><td><?= ((int) ($business['is_manufacturing'] ?? 0)) === 1 ? '예' : '아니오' ?></td></tr>
    </tbody>
</table>

<!-- 재고 입력 -->
<h2 style="font-size:16px;">재고 입력 <span class="muted" style="font-weight:400; font-size:13px;">(매출원가 계산)</span></h2>
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

<!-- 세무조정 입력 -->
<h2 style="font-size:16px;">세무조정 입력 <span class="muted" style="font-weight:400; font-size:13px;">(장부금액에 대한 가산·제외)</span></h2>
<form class="card" method="post" action="/admin/businesses/<?= $bid ?>/reports/tax-forms/adjustments">
    <?= csrf_field() ?>
    <input type="hidden" name="fiscal_year" value="<?= (int) $year ?>">
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:12px;">
        <div><label>⑫ 수입에서 제외</label><input type="text" name="revenue_exclude" inputmode="numeric" value="<?= $money($adjustments['revenue_exclude']) ?>"></div>
        <div><label>⑬ 수입에 가산</label><input type="text" name="revenue_add" inputmode="numeric" value="<?= $money($adjustments['revenue_add']) ?>"></div>
        <div></div>
        <div><label>⑯ 필요경비에서 제외</label><input type="text" name="expense_exclude" inputmode="numeric" value="<?= $money($adjustments['expense_exclude']) ?>"></div>
        <div><label>⑰ 필요경비에 가산</label><input type="text" name="expense_add" inputmode="numeric" value="<?= $money($adjustments['expense_add']) ?>"></div>
        <div></div>
        <div><label>⑳ 기부금 한도초과액</label><input type="text" name="donation_over" inputmode="numeric" value="<?= $money($adjustments['donation_over']) ?>"></div>
        <div><label>㉑ 기부금이월액 중 산입액</label><input type="text" name="donation_carryover" inputmode="numeric" value="<?= $money($adjustments['donation_carryover']) ?>"></div>
        <div></div>
    </div>
    <div class="actions"><button type="submit" class="btn secondary">세무조정 저장</button></div>
</form>

<!-- ① 소득금액계산서 -->
<h2 style="font-size:16px;">① 간편장부 소득금액계산서</h2>
<table class="tbl" style="margin-bottom:24px;">
    <tbody>
        <tr><th style="width:60%;">⑪ 장부상 수입금액</th><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
        <tr><th>⑫ 수입금액에서 제외할 금액</th><td class="num"><?= $money($statement['revenue_exclude']) ?></td></tr>
        <tr><th>⑬ 수입금액에 가산할 금액</th><td class="num"><?= $money($statement['revenue_add']) ?></td></tr>
        <tr><th>⑭ 세무조정 후 수입금액 (⑪−⑫+⑬)</th><td class="num"><?= $money($statement['adjusted_revenue']) ?></td></tr>
        <tr><th>⑮ 장부상 필요경비</th><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
        <tr><th>⑯ 필요경비에서 제외할 금액</th><td class="num"><?= $money($statement['expense_exclude']) ?></td></tr>
        <tr><th>⑰ 필요경비에 가산할 금액</th><td class="num"><?= $money($statement['expense_add']) ?></td></tr>
        <tr><th>⑱ 세무조정 후 필요경비 (⑮−⑯+⑰)</th><td class="num"><?= $money($statement['adjusted_expense']) ?></td></tr>
        <tr><th>⑲ 차가감 소득금액 (⑭−⑱)</th><td class="num"><?= $money($statement['pre_income']) ?></td></tr>
        <tr><th>⑳ 기부금 한도초과액</th><td class="num"><?= $money($statement['donation_over']) ?></td></tr>
        <tr><th>㉑ 기부금이월액 중 필요경비산입액</th><td class="num"><?= $money($statement['donation_carryover']) ?></td></tr>
        <tr class="total"><th>㉒ 당해연도 소득금액 (⑲+⑳−㉑)</th><td class="num"><strong><?= $money($statement['income_amount']) ?></strong></td></tr>
    </tbody>
</table>

<!-- ② 총수입금액 및 필요경비명세서(부표) -->
<h2 style="font-size:16px;">② 총수입금액 및 필요경비명세서</h2>
<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
    <div>
        <h3 style="font-size:14px; color:var(--muted);">수입금액</h3>
        <table class="tbl">
            <tbody>
            <tr><th>⑪ 매출액</th><td class="num"><?= $money($statement['revenue_by_account']['매출'] ?? 0) ?></td></tr>
            <tr><th>⑫ 기타</th><td class="num"><?= $money($statement['revenue_by_account']['기타(수입)'] ?? 0) ?></td></tr>
            <tr class="total"><th>⑬ 수입금액 합계</th><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
            </tbody>
        </table>
    </div>
    <div>
        <h3 style="font-size:14px; color:var(--muted);">필요경비</h3>
        <table class="tbl">
            <tbody>
            <tr><th>⑰ 매출원가(상품)</th><td class="num"><?= $money($statement['goods_cogs']) ?></td></tr>
            <?php if ($mfg['total'] !== 0): ?>
                <tr><th>㉑ 재료비</th><td class="num"><?= $money($mfg['materials']) ?></td></tr>
                <tr><th>㉒ 노무비</th><td class="num"><?= $money($mfg['labor']) ?></td></tr>
                <tr><th>㉓ 경비</th><td class="num"><?= $money($mfg['overhead']) ?></td></tr>
                <tr><th>㉔ 당기제조비용 (㉑+㉒+㉓)</th><td class="num"><?= $money($mfg['total']) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($statement['general_admin'] as $row): ?>
                <tr><th><?= esc($row['name']) ?></th><td class="num"><?= $money($row['amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr><th>㊵ 일반관리비 등 계</th><td class="num"><?= $money($statement['general_admin_total']) ?></td></tr>
            <tr class="total"><th>㊶ 필요경비 합계</th><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
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
