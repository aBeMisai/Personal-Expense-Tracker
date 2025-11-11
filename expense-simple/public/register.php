<?php
require_once __DIR__.'/../inc/auth.php';
if (current_user()) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name  = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = $_POST['password'] ?? '';
  if ($name==='' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass)<6) {
    $err = 'Something went wrong. Please try again.';
  } else {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO users(name,email,password,created_at) VALUES (?,?,?,NOW())");
      $st->execute([$name,$email,$hash]);
      $_SESSION['uid'] = (int)$pdo->lastInsertId();
      header('Location: dashboard.php'); exit;
    } catch(Throwable $e){ $err = "Signup failed: " . $e->getMessage(); }
  }
}
require_once __DIR__.'/../inc/layout.php';
layout_header('Sign Up');
?>
<div class="auth-wrap">
  <section class="auth-left">
    <div class="w-100" style="max-width:560px;">
      <h2 class="mb-1" style="font-weight:800;">Create an Account</h2>
      <p class="text-muted mb-4">Join us today by entering your details below.</p>

      <?php if ($err): ?><div class="alert alert-danger py-2"><?=h($err)?></div><?php endif; ?>

      <form method="post" class="card p-4">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input class="form-control" name="name" placeholder="Jane Doe" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email Address</label>
            <input type="email" class="form-control" name="email" placeholder="jane@example.com" required>
          </div>
          <div class="col-12">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" placeholder="Min 8 Characters" required>
          </div>
          <div class="col-12">
            <button class="btn btn-primary w-100 py-2">SIGN UP</button>
          </div>
        </div>
      </form>

      <div class="mt-3 small">Already have an account? <a href="login.php">Login</a></div>
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
        <canvas id="demoBar2"></canvas>
      </div>
    </div>
  </aside>
</div>

<script>
new Chart(document.getElementById('demoBar2'), {
  type: 'bar',
  data: {
    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul'],
    datasets: [
      { label:'Income',  data:[120,160,230,260,80,200,260] },
      { label:'Expense', data:[100,130,210,230,60,170,190] }
    ]
  }
});
</script>
<?php layout_footer(); ?>
