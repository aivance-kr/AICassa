<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>운영자 로그인 — AICassa</title>
    <style>
        :root { --primary:#7c3aed; --primary-dark:#6d28d9; --border:#e2e8f0; --muted:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic',sans-serif; color:#1e293b; background:#f8fafc; }
        header { background:var(--primary); color:#fff; padding:14px 24px; }
        header .brand { font-weight:700; font-size:18px; }
        .wrap { max-width:420px; margin:64px auto; padding:0 20px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:32px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .card h1 { font-size:20px; margin:0 0 4px; }
        .card .sub { color:var(--muted); font-size:13px; margin:0 0 20px; }
        label { display:block; font-size:13px; color:var(--muted); margin:14px 0 4px; }
        input[type=text], input[type=password] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px; }
        .btn { width:100%; margin-top:20px; background:var(--primary); color:#fff; padding:11px; border:none; border-radius:8px; font-size:15px; cursor:pointer; }
        .btn:hover { background:var(--primary-dark); }
        .alert { padding:11px 14px; border-radius:8px; font-size:14px; margin-bottom:16px; }
        .alert.err { background:#fee2e2; color:#991b1b; }
        .alert.ok { background:#ede9fe; color:#5b21b6; }
    </style>
</head>
<body>
    <header>
        <div class="brand">🛠️ AICassa 운영자</div>
    </header>

    <div class="wrap">
        <div class="card">
            <h1>운영자 로그인</h1>
            <p class="sub">연도별 세법 파라미터 관리 전용 영역입니다.</p>

            <?php if (session()->getFlashdata('error') !== null): ?>
                <div class="alert err"><?= esc(session()->getFlashdata('error')) ?></div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('message') !== null): ?>
                <div class="alert ok"><?= esc(session()->getFlashdata('message')) ?></div>
            <?php endif; ?>

            <form action="/operator/login" method="post">
                <?= csrf_field() ?>

                <label for="operator_id">아이디</label>
                <input type="text" id="operator_id" name="operator_id" autocomplete="username"
                    value="<?= esc(old('operator_id') ?? '') ?>" required>

                <label for="operator_password">비밀번호</label>
                <input type="password" id="operator_password" name="operator_password"
                    autocomplete="current-password" required>

                <button type="submit" class="btn">로그인</button>
            </form>
        </div>
    </div>
</body>
</html>
