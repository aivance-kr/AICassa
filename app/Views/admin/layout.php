<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'AICassa 간편장부') ?></title>
    <style>
        :root { --primary:#0F6E56; --secondary:#1D9E75; --border:#e2e8f0; --muted:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic',sans-serif; color:#1e293b; background:#f8fafc; }
        header { background:var(--primary); color:#fff; padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
        header .brand { font-weight:700; font-size:18px; }
        header nav a { color:#d1fae5; text-decoration:none; margin-right:16px; font-size:14px; }
        header nav a:hover { color:#fff; }
        header .user { font-size:13px; color:#d1fae5; }
        header .user a { color:#fff; margin-left:12px; }
        main { max-width:1100px; margin:24px auto; padding:0 24px; }
        h1 { font-size:22px; margin:0 0 16px; }
        .btn { display:inline-block; background:var(--secondary); color:#fff; padding:8px 16px; border:none; border-radius:6px; text-decoration:none; font-size:14px; cursor:pointer; }
        .btn.secondary { background:#fff; color:var(--primary); border:1px solid var(--primary); }
        .btn.danger { background:#dc2626; }
        .toolbar { display:flex; gap:8px; margin-bottom:16px; }
        .flash { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .flash.ok { background:#dcfce7; color:#166534; }
        .flash.err { background:#fee2e2; color:#991b1b; }
        table.tbl { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
        table.tbl th, table.tbl td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--border); font-size:14px; }
        table.tbl th { background:#f1f5f9; color:var(--muted); font-weight:600; }
        form.card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:24px; max-width:560px; }
        form.card label { display:block; font-size:13px; color:var(--muted); margin:12px 0 4px; }
        form.card input[type=text], form.card input[type=file], form.card select {
            width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; }
        form.card .actions { margin-top:20px; display:flex; gap:8px; }
        .checkbox { display:flex; align-items:center; gap:8px; margin-top:12px; }
        .checkbox input { width:auto; }
        .muted { color:var(--muted); font-size:13px; }
        .actions-cell a { margin-right:10px; text-decoration:none; color:var(--primary); font-size:13px; }
        .actions-cell a.del { color:#dc2626; }
    </style>
</head>
<body>
    <header>
        <div class="brand">AICassa 간편장부</div>
        <nav>
            <a href="/admin/businesses">사업장</a>
        </nav>
        <div class="user">
            <?php $u = $authUser ?? null; ?>
            <?= esc($u?->email ?? $u?->username ?? '사용자') ?>
            <a href="/logout">로그아웃</a>
        </div>
    </header>
    <main>
        <?php if (session()->getFlashdata('message')): ?>
            <div class="flash ok"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="flash err"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        <?php $errs = session()->getFlashdata('errors'); ?>
        <?php if (is_array($errs) && $errs !== []): ?>
            <div class="flash err">
                <?php foreach ($errs as $e): ?>
                    <div><?= esc($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
