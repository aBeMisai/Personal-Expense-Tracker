<?php
require_once __DIR__.'/../inc/auth.php';
if (current_user()) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass = $_POST['password'] ?? '';
  $st = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1"); 
  $st->execute([$email]);
  $u = $st->fetch();

  if ($u && password_verify($pass, $u['password'])) {
    $_SESSION['uid'] = (int)$u['id'];
    header('Location: dashboard.php'); exit;
  } else {
      $err = 'Invalid email or password.'; }
}

require_once __DIR__.'/../inc/layout.php';
layout_header('Login');
?>
<div class="auth-wrap">
  <section class="auth-left">
    <div class="w-100" style="max-width:520px;">
     <h1 class="mb-3">Welcome to <span style="color:var(--accent);">SmartSpend</span></h1>
      <p class="text-muted mb-4">Please enter your details to log in</p>

      <?php if ($err): ?><div class="alert alert-danger py-2"><?=h($err)?></div><?php endif; ?>

      <form method="post" class="card p-4">
        <div class="mb-3">
          <label class="form-label">Email Address</label>
          <input type="email" class="form-control" name="email" placeholder="john@example.com" required>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" name="password" placeholder="Min 8 Characters" required>
        </div>
        <button class="btn btn-primary w-100 py-2">LOGIN</button>
      </form>

      <div class="mt-3 small">Don’t have an account? <a href="register.php">SignUp</a></div>
    </div>
  </section>

  <aside class="auth-right">
    <div class="w-100" style="max-width:680px;">
      <div class="kpi mb-4 d-inline-flex align-items-center gap-3">
        <span class="badge-dot"></span>
        <div>
          <div class="small text-muted">Track Your Income & Expenses</div>
          <div style="font-size:22px; font-weight:800;">$430,000</div>
        </div>
      </div>

      <div class="demo-card">
        <div class="demo-title">All Transactions</div>
        <div class="demo-sub">2nd Jan to 21th Dec</div>
        <canvas id="demoBar"></canvas>
      </div>
    </div>
  </aside>
</div>

<script>
const ctx = document.getElementById('demoBar');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul'],
    datasets: [
      { label: 'Income',  data: [120,160,230,260,80,200,260] },
      { label: 'Expense', data: [100,130,210,230,60,170,190] }
    ]
  }
});
</script>
<?php layout_footer(); ?>
