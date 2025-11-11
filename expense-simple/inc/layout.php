<?php
require_once __DIR__.'/auth.php';

/* Safe HTML-escape helper (if not defined elsewhere) */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Helper to highlight the current page in the top nav */
if (!function_exists('nav_active')) {
  function nav_active(string $filename): string {
    return basename($_SERVER['PHP_SELF']) === $filename ? 'fw-bold text-primary' : '';
  }
}

function layout_header(string $title=''): void { $u = current_user(); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title ? "$title — SmartSpend" : "SmartSpend") ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Chart.js (kept in <head> so inline page scripts can use it immediately) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    :root{
      --bg:#f6f7fb;
      --panel:#ffffff;
      --ink:#0f172a;
      --muted:#6b7280;
      --accent:#7c3aed;       /* purple */
      --accent-2:#a78bfa;     /* light purple */
      --success:#16a34a;
      --danger:#ef4444;
    }
    body{ background:var(--bg); color:#111827; }

    /* Topbar (shown only when logged in) */
    .topbar {
      position: sticky; top: 0; z-index: 50;
      background:#fff; border-bottom:1px solid #eef0f5;
      box-shadow:0 6px 18px rgba(17,24,39,.04);
    }
    .brand { font-weight:800; }

    /* Content wrapper (no sidebar) */
    .content { padding:24px 0; }

    /* Auth split layout: form (left) + picture/chart (right) */
    .auth-wrap{ min-height:calc(100vh - 64px); display:grid; grid-template-columns: 1fr 1fr; gap:28px; align-items:center; }
    .auth-left{ padding:32px 0; }
    .auth-right{
      background:
        radial-gradient(900px 500px at 120% -20%, rgba(124,58,237,.20), transparent 60%),
        radial-gradient(700px 500px at -10% 100%, rgba(167,139,250,.25), transparent 60%),
        #f8f7ff;
      padding:32px; border-radius:20px; box-shadow:0 20px 50px rgba(17,24,39,.08);
      display:flex; align-items:center; justify-content:center;
    }
    .kpi{ background:#fff; border-radius:14px; padding:14px 18px; box-shadow:0 10px 30px rgba(17,24,39,.08); }
    .demo-card{ background:#fff; border-radius:20px; padding:18px; width:100%; max-width:560px;
      box-shadow:0 20px 50px rgba(17,24,39,.12); }
    .demo-title{ font-weight:700; margin-bottom:2px; }
    .demo-sub{ color:#6b7280; font-size:14px; margin-bottom:10px; }
    .badge-dot{ width:22px; height:22px; border-radius:50%; background:var(--accent); display:inline-block; margin-right:8px; }

    /* Cards/buttons used across pages */
    .soft-card{ background:var(--panel); border:0; border-radius:16px; padding:16px; box-shadow:0 12px 40px rgba(17,24,39,.06); }
    .btn-purple{ background:var(--accent); border-color:var(--accent); }
    .btn-purple:hover{ background:#6d28d9; border-color:#6d28d9; }
    .chip-pos{ color:var(--success); font-weight:700; }
    .chip-neg{ color:var(--danger); font-weight:700; }
    .listy .rowi{ display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #f0f2f7; padding:10px 0; }

    /* Forms */
    .form-control{ background:#f3f4f6; border-color:#e5e7eb; color:#111827; }
    .form-control:focus{ box-shadow:0 0 0 .2rem rgba(124,58,237,.15); border-color:var(--accent); }

    /* Charts */
    canvas { max-width:100%; }

    /* Responsive: stack form & picture on small screens */
    @media (max-width: 992px){
      .auth-wrap{ grid-template-columns: 1fr; }
      .auth-right{ order:2; }
      .auth-left{ order:1; }
    }

    .gradient-logo {
      font-weight: 800;
      font-size: 22px;
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      display: inline-block;
    }
  </style>
</head>
<body>

<?php if ($u): ?>
  <!-- Minimal top navigation (only when logged in) -->
  <header class="topbar">
    <div class="container d-flex align-items-center justify-content-between py-3">
      <div class="brand gradient-logo">SmartSpend</div>
      <nav class="d-flex gap-3">
        <a class="text-decoration-none <?= nav_active('dashboard.php') ?>" href="/expense-simple/public/dashboard.php">
          <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a class="text-decoration-none <?= nav_active('income.php') ?>" href="/expense-simple/public/income.php">
          <i class="bi bi-wallet2 me-1"></i>Income
        </a>
        <a class="text-decoration-none <?= nav_active('budgets.php') ?>" href="/expense-simple/public/budgets.php">
          <i class="bi bi-pie-chart me-1"></i>Budgets
        </a>
        <a class="text-decoration-none <?= nav_active('expenses.php') ?>" href="/expense-simple/public/expenses.php">
          <i class="bi bi-bag me-1"></i>Expense
        </a>
      
        <a class="text-decoration-none" href="/expense-simple/public/logout.php">
          <i class="bi bi-box-arrow-right me-1"></i>Logout
        </a>
      </nav>
    </div>
  </header>
<?php endif; ?>

  <main id="app" class="content">
    <div class="container">
<?php }

function layout_footer(): void { ?>
    </div>
  </main>

  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php }
