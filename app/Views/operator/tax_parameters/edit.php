<?= $this->extend('operator/layout') ?>

<?= $this->section('content') ?>
<?php
    $grouped = [];
    foreach ($params as $p) {
        $grouped[(string) $p['category']][] = $p;
    }
    $catLabel = static fn (string $c): string => (\App\Enums\TaxParameterCategory::tryFrom($c)?->label()) ?? $c;
    // old() 우선(검증 실패 재입력) → 없으면 저장값
    $val = static function (array $p): string {
        $key    = (string) $p['param_key'];
        $oldAll = old('params');
        if (is_array($oldAll) && array_key_exists($key, $oldAll)) {
            return (string) $oldAll[$key];
        }

        if ((string) $p['value_type'] === 'json') {
            return (string) json_encode(
                json_decode((string) $p['param_value'], true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            );
        }

        return (string) $p['param_value'];
    };
?>
<div class="toolbar">
    <a href="/operator/tax-parameters/<?= (int) $year ?>" class="btn secondary">← 상세</a>
</div>
<h1><?= esc($year) ?>년 세법 파라미터 수정</h1>
<p class="page-desc">값을 일괄 편집합니다. JSON(표) 항목은 유효한 JSON 배열이어야 저장됩니다.</p>

<form class="card" method="post" action="/operator/tax-parameters/<?= (int) $year ?>">
    <?= csrf_field() ?>

    <?php foreach ($grouped as $cat => $rows): ?>
        <h2 style="font-size:15px; margin:18px 0 10px;">
            <span class="cat"><?= esc($catLabel($cat)) ?></span>
        </h2>
        <?php foreach ($rows as $p): ?>
            <?php
                $key    = (string) $p['param_key'];
                $isJson = \App\Enums\TaxParameterType::from((string) $p['value_type'])->isComplex();
                $inputId = 'p_' . $key;
            ?>
            <div class="field">
                <label for="<?= esc($inputId) ?>">
                    <?= esc($p['label']) ?>
                    <?php if ($p['unit'] !== null && $p['unit'] !== ''): ?>
                        <span class="unit">(<?= esc($p['unit']) ?>)</span>
                    <?php endif; ?>
                    <span class="muted">· <code><?= esc($key) ?></code></span>
                </label>
                <?php if ($isJson): ?>
                    <textarea id="<?= esc($inputId) ?>" name="params[<?= esc($key) ?>]"><?= esc($val($p)) ?></textarea>
                <?php else: ?>
                    <?php $type = (string) $p['value_type'] === 'string' ? 'text' : 'number'; ?>
                    <input type="<?= $type ?>" step="any" id="<?= esc($inputId) ?>"
                        name="params[<?= esc($key) ?>]" value="<?= esc($val($p)) ?>">
                <?php endif; ?>
                <?php if ($p['description'] !== null && $p['description'] !== ''): ?>
                    <p class="desc"><?= esc($p['description']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="actions">
        <button type="submit" class="btn">저장</button>
        <a href="/operator/tax-parameters/<?= (int) $year ?>" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>
