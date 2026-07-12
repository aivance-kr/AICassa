<?php
    $money       = static fn (int $n): string => number_format($n);
    $mfg         = $statement['manufacturing'];
    $methodLabel = static fn (?string $v): string => match ($v) {
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
        default             => '-',
    };
?><!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>종합소득세 신고서식 — <?= esc($business['name']) ?> <?= (int) $year ?>년</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Malgun Gothic', '맑은 고딕', sans-serif; color: #111; margin: 24px; font-size: 13px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 22px 0 8px; border-bottom: 2px solid #333; padding-bottom: 4px; }
        .meta { color: #555; font-size: 12px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #999; padding: 5px 8px; }
        th { background: #f0f0f0; text-align: left; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.total th, tr.total td { background: #eaeaea; font-weight: 700; }
        .toolbar { margin-bottom: 16px; }
        .btn { display: inline-block; padding: 8px 14px; background: #0F6E56; color: #fff; text-decoration: none; border-radius: 6px; border: 0; cursor: pointer; font-size: 13px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none; }
            h2 { page-break-after: avoid; }
            table { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">인쇄 / PDF로 저장</button>
    </div>

    <h1>종합소득세 신고서식</h1>
    <div class="meta">귀속연도: <?= (int) $year ?>년 · 서식버전: <?= esc($formVersion['version']) ?></div>

    <!-- 인적사항 -->
    <h2>인적사항</h2>
    <table>
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
    </table>

    <!-- ① 소득금액계산서 -->
    <h2>① 간편장부 소득금액계산서</h2>
    <table>
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
        <tr class="total"><th>㉒ 당해연도 소득금액 (⑲+⑳−㉑)</th><td class="num"><?= $money($statement['income_amount']) ?></td></tr>
    </table>

    <!-- ② 총수입금액 및 필요경비명세서 -->
    <h2>② 총수입금액 및 필요경비명세서</h2>
    <div class="grid2">
        <div>
            <table>
                <thead><tr><th>수입금액</th><th class="num">금액</th></tr></thead>
                <tbody>
                <tr><td>⑪ 매출액</td><td class="num"><?= $money($statement['revenue_by_account']['매출'] ?? 0) ?></td></tr>
                <tr><td>⑫ 기타</td><td class="num"><?= $money($statement['revenue_by_account']['기타(수입)'] ?? 0) ?></td></tr>
                <tr class="total"><td>⑬ 수입금액 합계</td><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div>
            <table>
                <thead><tr><th>필요경비</th><th class="num">금액</th></tr></thead>
                <tbody>
                <tr><td>⑰ 매출원가(상품)</td><td class="num"><?= $money($statement['goods_cogs']) ?></td></tr>
                <?php if ($mfg['total'] !== 0): ?>
                    <tr><td>㉑ 재료비</td><td class="num"><?= $money($mfg['materials']) ?></td></tr>
                    <tr><td>㉒ 노무비</td><td class="num"><?= $money($mfg['labor']) ?></td></tr>
                    <tr><td>㉓ 경비</td><td class="num"><?= $money($mfg['overhead']) ?></td></tr>
                    <tr><td>㉔ 당기제조비용</td><td class="num"><?= $money($mfg['total']) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($statement['general_admin'] as $row): ?>
                    <tr><td><?= esc($row['name']) ?></td><td class="num"><?= $money($row['amount']) ?></td></tr>
                <?php endforeach; ?>
                <tr><td>㊵ 일반관리비 등 계</td><td class="num"><?= $money($statement['general_admin_total']) ?></td></tr>
                <tr class="total"><td>㊶ 필요경비 합계</td><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ③ 감가상각비 조정명세서 -->
    <h2>③ 감가상각비 조정명세서</h2>
    <?php if ($depreciation === []): ?>
        <p>해당 연도 감가상각 대상 자산이 없습니다.</p>
    <?php else: ?>
        <table>
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
</body>
</html>
