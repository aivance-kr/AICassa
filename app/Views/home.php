<?php
/**
 * 랜딩 페이지 — AICassa 간편장부 웹 ERP
 * 서비스 소개 + 로그인/회원가입(Shield) 을 한 화면에 담는다.
 */

// 회원가입 폼에서 유효성 오류가 났으면(=username 이 입력값에 있으면) 회원가입 탭을 기본으로 연다.
$openRegister = old('username') !== null;

// Shield 플래시 메시지 수집
$flashError  = session()->getFlashdata('error');
$flashErrors = session()->getFlashdata('errors');
$flashMsg    = session()->getFlashdata('message');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AICassa 간편장부 — 웹으로 쓰는 국세청 간편장부</title>
    <style>
        :root {
            --primary:#0F6E56; --secondary:#1D9E75; --accent:#34d399;
            --ink:#0f172a; --muted:#64748b; --border:#e2e8f0; --bg:#f8fafc;
        }
        * { box-sizing:border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic','Apple SD Gothic Neo',sans-serif; color:var(--ink); background:var(--bg); line-height:1.55; }
        a { color:var(--primary); }

        /* 헤더 */
        .top { position:sticky; top:0; z-index:10; background:rgba(255,255,255,.9); backdrop-filter:blur(8px); border-bottom:1px solid var(--border); }
        .top .wrap { max-width:1120px; margin:0 auto; padding:14px 24px; display:flex; align-items:center; justify-content:space-between; }
        .brand { font-weight:800; font-size:19px; color:var(--primary); letter-spacing:-.02em; }
        .brand span { color:var(--accent); }
        .top nav a { color:var(--muted); text-decoration:none; margin-left:22px; font-size:14px; }
        .top nav a:hover { color:var(--ink); }
        .top nav a.cta { color:#fff; background:var(--secondary); padding:8px 16px; border-radius:8px; font-weight:600; }

        /* 히어로 */
        .hero { max-width:1120px; margin:0 auto; padding:56px 24px 40px; display:grid; grid-template-columns:1.1fr .9fr; gap:48px; align-items:start; }
        .hero .badge { display:inline-block; background:#dcfce7; color:var(--primary); font-size:13px; font-weight:600; padding:6px 12px; border-radius:999px; margin-bottom:20px; }
        .hero h1 { font-size:40px; line-height:1.2; letter-spacing:-.03em; margin:0 0 18px; }
        .hero h1 em { color:var(--primary); font-style:normal; }
        .hero p.lead { font-size:17px; color:var(--muted); margin:0 0 28px; max-width:520px; }
        .hero .points { list-style:none; padding:0; margin:0 0 8px; display:grid; gap:10px; }
        .hero .points li { font-size:15px; padding-left:28px; position:relative; }
        .hero .points li::before { content:'✓'; position:absolute; left:0; top:0; color:var(--secondary); font-weight:800; }

        /* 인증 카드 */
        .auth { background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:0 12px 32px -12px rgba(15,110,86,.25); padding:8px; }
        .tabs { display:flex; background:var(--bg); border-radius:12px; padding:4px; margin:8px 8px 4px; }
        .tabs button { flex:1; border:none; background:transparent; padding:10px; border-radius:9px; font-size:14px; font-weight:600; color:var(--muted); cursor:pointer; }
        .tabs button.active { background:#fff; color:var(--primary); box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .panel { padding:16px 20px 22px; display:none; }
        .panel.active { display:block; }
        .panel h2 { font-size:18px; margin:4px 0 4px; }
        .panel p.sub { font-size:13px; color:var(--muted); margin:0 0 16px; }
        .field { margin-bottom:14px; }
        .field label { display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px; }
        .field input { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:9px; font-size:14px; }
        .field input:focus { outline:none; border-color:var(--secondary); box-shadow:0 0 0 3px rgba(29,158,117,.15); }
        .field .hint { font-size:12px; color:var(--muted); margin-top:5px; }
        .row-between { display:flex; align-items:center; justify-content:space-between; font-size:13px; margin:2px 0 16px; }
        .row-between label { display:flex; align-items:center; gap:7px; color:var(--muted); cursor:pointer; }
        .row-between a { text-decoration:none; }
        .btn-primary { width:100%; background:var(--secondary); color:#fff; border:none; padding:12px; border-radius:9px; font-size:15px; font-weight:700; cursor:pointer; }
        .btn-primary:hover { background:var(--primary); }
        .alert { margin:8px 8px 4px; padding:11px 14px; border-radius:10px; font-size:13px; }
        .alert.err { background:#fee2e2; color:#991b1b; }
        .alert.ok  { background:#dcfce7; color:#166534; }
        .alert ul { margin:6px 0 0; padding-left:18px; }

        /* 기능 섹션 */
        .features { max-width:1120px; margin:0 auto; padding:24px 24px 64px; }
        .features h3 { text-align:center; font-size:14px; letter-spacing:.08em; text-transform:uppercase; color:var(--secondary); margin:0 0 8px; }
        .features h4 { text-align:center; font-size:26px; letter-spacing:-.02em; margin:0 0 40px; }
        .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:24px; }
        .card .ico { width:42px; height:42px; border-radius:10px; background:#dcfce7; color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:14px; }
        .card h5 { font-size:16px; margin:0 0 8px; }
        .card p { font-size:14px; color:var(--muted); margin:0; }

        /* 플로우 */
        .flow { background:#fff; border-top:1px solid var(--border); border-bottom:1px solid var(--border); }
        .flow .wrap { max-width:1120px; margin:0 auto; padding:48px 24px; text-align:center; }
        .flow h4 { font-size:24px; margin:0 0 32px; letter-spacing:-.02em; }
        .steps { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; }
        .step { background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:16px 22px; font-size:15px; font-weight:600; }
        .step small { display:block; font-weight:400; color:var(--muted); font-size:12px; margin-top:4px; }
        .arrow { color:var(--secondary); font-size:20px; font-weight:800; }

        /* 푸터 */
        footer { max-width:1120px; margin:0 auto; padding:32px 24px 48px; color:var(--muted); font-size:13px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; }

        @media (max-width:860px) {
            .hero { grid-template-columns:1fr; padding-top:36px; }
            .hero h1 { font-size:32px; }
            .grid { grid-template-columns:1fr; }
            .steps { flex-direction:column; }
            .arrow { transform:rotate(90deg); }
        }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <div class="brand">AI<span>Cassa</span> 간편장부</div>
            <nav>
                <a href="#features">기능</a>
                <a href="#flow">이용 흐름</a>
                <a href="#auth" class="cta">시작하기</a>
            </nav>
        </div>
    </header>

    <!-- 히어로 + 인증 -->
    <section class="hero" id="auth">
        <div>
            <span class="badge">국세청 간편장부 제도 기반 · SaaS</span>
            <h1>엑셀·매크로 없이,<br><em>웹으로 쓰는 간편장부</em></h1>
            <p class="lead">
                Windows·MS Excel 전용 프로그램을 벗어나 어디서든 브라우저로.
                거래 입력부터 부가세·감가상각 자동계산, 종합소득세 신고서식 생성까지 한 곳에서 처리하세요.
            </p>
            <ul class="points">
                <li>사용자별 <strong>다중 사업장·다중 소득</strong> 장부 분리 지원</li>
                <li>증빙유형 기준 <strong>부가세 자동 계산</strong>, 자산 <strong>감가상각 자동 반영</strong></li>
                <li>입력 → 영업현황표 집계 → <strong>신고서식 자동 생성</strong> 파이프라인</li>
                <li>엑셀 <strong>가져오기/내보내기</strong>로 기존 자료 그대로 이관</li>
            </ul>
        </div>

        <div class="auth">
            <?php if ($flashMsg): ?>
                <div class="alert ok"><?= esc($flashMsg) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert err"><?= esc($flashError) ?></div>
            <?php endif; ?>
            <?php if (is_array($flashErrors) && $flashErrors !== []): ?>
                <div class="alert err">
                    입력값을 확인해 주세요.
                    <ul>
                        <?php foreach ($flashErrors as $e): ?>
                            <li><?= esc($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="tabs">
                <button type="button" id="tab-login" onclick="showTab('login')">로그인</button>
                <button type="button" id="tab-register" onclick="showTab('register')">회원가입</button>
            </div>

            <!-- 로그인 -->
            <form class="panel" id="panel-login" action="<?= site_url('login') ?>" method="post">
                <?= csrf_field() ?>
                <h2>로그인</h2>
                <p class="sub">이메일과 비밀번호로 로그인하세요.</p>
                <div class="field">
                    <label for="login-email">이메일</label>
                    <input type="email" name="email" id="login-email" value="<?= esc(old('email'), 'attr') ?>" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="login-password">비밀번호</label>
                    <input type="password" name="password" id="login-password" autocomplete="current-password" required>
                </div>
                <div class="row-between">
                    <label><input type="checkbox" name="remember" value="1"> 로그인 유지</label>
                    <a href="<?= site_url('login/magic-link') ?>">비밀번호를 잊으셨나요?</a>
                </div>
                <button type="submit" class="btn-primary">로그인</button>
            </form>

            <!-- 회원가입 -->
            <form class="panel" id="panel-register" action="<?= site_url('register') ?>" method="post">
                <?= csrf_field() ?>
                <h2>회원가입</h2>
                <p class="sub">계정을 만들고 바로 장부 작성을 시작하세요.</p>
                <div class="field">
                    <label for="reg-username">아이디</label>
                    <input type="text" name="username" id="reg-username" value="<?= esc(old('username'), 'attr') ?>" autocomplete="username" required>
                    <div class="hint">영문·숫자·마침표 3~30자</div>
                </div>
                <div class="field">
                    <label for="reg-email">이메일</label>
                    <input type="email" name="email" id="reg-email" value="<?= esc(old('email'), 'attr') ?>" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="reg-password">비밀번호</label>
                    <input type="password" name="password" id="reg-password" autocomplete="new-password" required>
                    <div class="hint">8자 이상 권장 · 아이디/이메일과 유사하지 않게</div>
                </div>
                <div class="field">
                    <label for="reg-password-confirm">비밀번호 확인</label>
                    <input type="password" name="password_confirm" id="reg-password-confirm" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn-primary">가입하고 시작하기</button>
            </form>
        </div>
    </section>

    <!-- 기능 -->
    <section class="features" id="features">
        <h3>Features</h3>
        <h4>간편장부 작성에 필요한 모든 것</h4>
        <div class="grid">
            <div class="card">
                <div class="ico">📒</div>
                <h5>표준 간편장부 입력</h5>
                <p>국세청 8개 열(일자·계정과목·거래내용·거래처·수입·비용·자산증감·비고) 표준 서식 그대로 거래를 기록합니다.</p>
            </div>
            <div class="card">
                <div class="ico">🧮</div>
                <h5>부가세 자동 계산</h5>
                <p>세금계산서·현금영수증 등 증빙유형을 키로 공급가액과 부가세를 자동 분리·계산합니다.</p>
            </div>
            <div class="card">
                <div class="ico">🏢</div>
                <h5>자산대장·감가상각</h5>
                <p>사업용 유·무형자산을 등록하면 감가상각 스케줄을 자동 산출해 비용으로 반영합니다.</p>
            </div>
            <div class="card">
                <div class="ico">📊</div>
                <h5>영업현황표 집계</h5>
                <p>입력한 장부를 계정과목별로 자동 집계해 매출·비용·소득 현황을 한눈에 확인합니다.</p>
            </div>
            <div class="card">
                <div class="ico">📄</div>
                <h5>신고서식 자동 생성</h5>
                <p>집계 결과로 종합소득세 신고서식을 자동 생성하고 인쇄용 PDF·엑셀로 내보냅니다.</p>
            </div>
            <div class="card">
                <div class="ico">🔄</div>
                <h5>엑셀 가져오기·내보내기</h5>
                <p>기존 엑셀 장부·거래처 자료를 그대로 업로드하고, 결과물도 엑셀로 다운로드합니다.</p>
            </div>
        </div>
    </section>

    <!-- 이용 흐름 -->
    <section class="flow" id="flow">
        <div class="wrap">
            <h4>가입부터 신고서식까지, 4단계</h4>
            <div class="steps">
                <div class="step">① 사업장 등록<small>사업장·소득별 장부 분리</small></div>
                <span class="arrow">→</span>
                <div class="step">② 거래 입력<small>직접 입력 또는 엑셀 가져오기</small></div>
                <span class="arrow">→</span>
                <div class="step">③ 자동 집계<small>부가세·감가상각·영업현황표</small></div>
                <span class="arrow">→</span>
                <div class="step">④ 신고서식 출력<small>PDF·엑셀 다운로드</small></div>
            </div>
        </div>
    </section>

    <footer>
        <div>© <?= date('Y') ?> AICassa 간편장부. 국세청 간편장부 제도 기반 웹 ERP.</div>
        <div>본 서비스의 계산 결과는 참고용이며 최종 신고 전 세무 전문가 확인을 권장합니다.</div>
    </footer>

    <script>
        function showTab(name) {
            var isReg = name === 'register';
            document.getElementById('panel-login').classList.toggle('active', !isReg);
            document.getElementById('panel-register').classList.toggle('active', isReg);
            document.getElementById('tab-login').classList.toggle('active', !isReg);
            document.getElementById('tab-register').classList.toggle('active', isReg);
            location.hash = 'auth';
        }
        // 회원가입 오류로 되돌아온 경우 회원가입 탭을 연다.
        showTab(<?= $openRegister ? "'register'" : "'login'" ?>);
    </script>
</body>
</html>
