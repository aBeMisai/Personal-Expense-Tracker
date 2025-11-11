<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

/** -----------------------
 * Filter Handling
 * ----------------------*/
$today = new DateTime();
$defaultYM = $today->format('Y-m');
$defaultY  = $today->format('Y');

$period = $_GET['period'] ?? 'month';            // 'month' | 'year' | 'all'
$ym     = $_GET['ym']     ?? $defaultYM;         // e.g. '2025-08'
$year   = $_GET['year']   ?? $defaultY;          // e.g. '2025'

// Build WHERE clause used across queries
$cond   = "user_id=?";
$params = [$u['id']];

if ($period === 'month' && preg_match('/^\d{4}-\d{2}$/', $ym)) {
  $cond   .= " AND DATE_FORMAT(date,'%Y-%m')=?";
  $params[] = $ym;
} elseif ($period === 'year' && preg_match('/^\d{4}$/', $year)) {
  $cond   .= " AND YEAR(date)=?";
  $params[] = $year;
} else {
  $period = 'all'; // fallback if invalid input
}

// Helper to sum
$sum = function(string $sql, array $p = []) use ($pdo) {
  $s = $pdo->prepare($sql);
  $s->execute($p);
  $row = $s->fetch();
  return (float)($row['s'] ?? 0);
};

// Totals (respect filter)
$totalIncome  = $sum("SELECT SUM(amount) s FROM income   WHERE $cond",   $params);
$totalExpense = $sum("SELECT SUM(amount) s FROM expenses WHERE $cond",   $params);
$balance      = $totalIncome - $totalExpense;

// Recent (respect filter)
$recentSql = "
  SELECT date, category AS label, amount, 'Expense' AS type
  FROM expenses WHERE $cond
  UNION ALL
  SELECT date, type AS label, amount, 'Income' AS type
  FROM income WHERE $cond
  ORDER BY date DESC, type DESC
  LIMIT 7
";
$recent = $pdo->prepare($recentSql);
$recent->execute(array_merge($params, $params));
$recentRows = $recent->fetchAll();

// Category breakdown (respect filter)
$catStmt = $pdo->prepare("SELECT category, SUM(amount) s FROM expenses WHERE $cond GROUP BY category ORDER BY s DESC");
$catStmt->execute($params);
$cats = $catStmt->fetchAll();

/** -----------------------
 * Monthly comparison: last 6 months (overall trend)
 * ----------------------*/
$months = [];
$incomeByMonth = [];
$expenseByMonth = [];

$dt = new DateTime('first day of this month');
for ($i = 5; $i >= 0; $i--) {
  $m = (clone $dt)->modify("-$i months")->format('Y-m'); // e.g. 2025-08
  $months[] = $m;

  // Sum income for month
  $stInc = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS s
                          FROM income
                          WHERE user_id=? AND DATE_FORMAT(date,'%Y-%m')=?");
  $stInc->execute([$u['id'], $m]);
  $incomeByMonth[] = (float)$stInc->fetchColumn();

  // Sum expenses for month
  $stExp = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS s
                          FROM expenses
                          WHERE user_id=? AND DATE_FORMAT(date,'%Y-%m')=?");
  $stExp->execute([$u['id'], $m]);
  $expenseByMonth[] = (float)$stExp->fetchColumn();
}

// --- Helper: Budget window (reuse same logic as budgets.php) ---
function budget_window(array $b): array {
    $today = new DateTime('today');

    if ($b['period_type'] === 'one_off') {
        $start = new DateTime($b['start_date'] ?? '1970-01-01');
        $end   = new DateTime($b['end_date']   ?? '2099-12-31');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    if ($b['period_type'] === 'monthly') {
        $start = new DateTime($today->format('Y-m-01'));
        $end   = new DateTime($today->format('Y-m-t'));
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    // yearly
    $year  = $today->format('Y');
    return ["$year-01-01", "$year-12-31"];
}


// --- Budget Notifications ---
$budgetStmt = $pdo->prepare("
    SELECT id, name, category, amount, period_type, start_date, end_date
    FROM budgets 
    WHERE user_id=?
");


$budgetStmt->execute([$u['id']]);
$budgets = $budgetStmt->fetchAll();

$alerts = [];

foreach ($budgets as $b) {
    $limit = (float)$b['amount'];
    [$ws, $we] = budget_window($b);

    $spentStmt = $pdo->prepare("
        SELECT IFNULL(SUM(amount),0)
        FROM expenses
        WHERE user_id=? 
          AND date BETWEEN ? AND ?
          " . ($b['category'] ? " AND category=?" : "")
    );

    $budgetParams = [$u['id'], $ws, $we];
    if ($b['category']) $budgetParams[] = $b['category'];

    $spentStmt->execute($budgetParams);
    $spent = (float)$spentStmt->fetchColumn();

    // Percent progress
    $percent = $limit > 0 ? round(($spent / $limit) * 100) : 0;

    // Status logic
    if ($spent >= $limit) {
        $status = "⚠️ <b>{$b['name']}</b> exceeded! (Limit RM{$limit}, spent RM{$spent})";
    } elseif ($percent >= 80) {
        $status = "🔔 <b>{$b['name']}</b> is almost full (RM{$spent} / RM{$limit})";
    } else {
        $status = "✅ <b>{$b['name']}</b> is on track (RM{$spent} / RM{$limit})";
    }

    $alerts[] = $status;
}




layout_header('Dashboard');
?>

<h4 class="mb-3">Dashboard</h4>

<!-- Filter Bar -->
<div class="soft-card mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3">
      <label class="form-label fw-semibold">Period</label>
      <select name="period" id="period" class="form-select">
        <option value="month" <?= $period==='month'?'selected':'' ?>>Month</option>
        <option value="year"  <?= $period==='year'?'selected':'' ?>>Year</option>
        <option value="all"   <?= $period==='all'?'selected':''  ?>>All time</option>
      </select>
    </div>

    <div class="col-md-3 period-field month-field" style="<?= $period==='month'?'':'display:none' ?>">
      <label class="form-label fw-semibold">Month</label>
      <input type="month" name="ym" value="<?= h($ym) ?>" class="form-control">
    </div>

    <div class="col-md-3 period-field year-field" style="<?= $period==='year'?'':'display:none' ?>">
      <label class="form-label fw-semibold">Year</label>
      <input type="number" name="year" min="2000" max="2100" value="<?= h($year) ?>" class="form-control">
    </div>

    <div class="col-md-3">
      <button class="btn btn-purple w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>

    <div class="col-12">
      <div class="d-flex flex-wrap gap-2 mt-2">
        <a class="btn btn-sm btn-outline-secondary"
           href="?period=month&ym=<?= h($defaultYM) ?>">This Month</a>
        <a class="btn btn-sm btn-outline-secondary"
           href="?period=year&year=<?= h($defaultY) ?>">This Year</a>
        <a class="btn btn-sm btn-outline-secondary"
           href="?period=all">All Time</a>
      </div>
    </div>
  </form>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="kpi">
      <div class="icon"><i class="bi bi-credit-card"></i></div>
      <div>
        <div class="text-muted small">Total Balance</div>
        <div class="fs-4 fw-bold">RM<?= number_format($balance, 0) ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="kpi">
      <div class="icon"><i class="bi bi-cash-coin"></i></div>
      <div>
        <div class="text-muted small">Total Income</div>
        <div class="fs-4 fw-bold">RM<?= number_format($totalIncome, 0) ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="kpi">
      <div class="icon"><i class="bi bi-scissors"></i></div>
      <div>
        <div class="text-muted small">Total Expenses</div>
        <div class="fs-4 fw-bold">RM<?= number_format($totalExpense, 0) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Left column (Recent + Notifications stacked) -->
  <div class="col-lg-7">
    <div class="soft-card mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">Recent Transactions</div>
        <a class="btn btn-sm btn-outline-secondary" href="total_transaction.php">See All</a>
      </div>
      <div class="listy">
        <?php if (!$recentRows): ?>
          <div class="text-muted">No transactions for the selected period.</div>
        <?php endif; ?>
        <?php foreach ($recentRows as $r): $isInc = $r['type'] === 'Income'; ?>
          <div class="rowi">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-circle" style="width:38px;height:38px;background:#f6f3ff;display:grid;place-items:center;">
                <i class="bi <?= $isInc ? 'bi-graph-up-arrow' : 'bi-bag' ?>"></i>
              </div>
              <div>
                <div class="fw-semibold"><?= h($r['label']) ?></div>
                <div class="text-muted small"><?= h($r['date']) ?></div>
              </div>
            </div>
            <div class="<?= $isInc ? 'chip-pos' : 'chip-neg' ?>">
              <?= $isInc ? '+RM' : '-RM' ?><?= number_format((float)$r['amount'], 0) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Notifications (stacked below Recent, same width col-lg-7) -->
    <div class="soft-card">
      <div class="fw-bold mb-2">Notifications</div>
      <?php if ($alerts): ?>
        <ul class="mb-0">
          <?php foreach ($alerts as $a): ?>
            <li><?= $a ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="text-muted">No budget issues 👍</div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Donut -->
  <div class="col-lg-5">
    <div class="soft-card">
      <div class="fw-bold mb-2">Financial Overview</div>
      <canvas id="donut"></canvas>
      <?php if ($cats): ?>
        <div class="mt-3 small text-muted">
          Top categories:
          <?php
          $top = array_slice($cats, 0, 3);
          $labels = array_map(fn($c) => h($c['category']).' (RM'.number_format($c['s'],0).')', $top);
          echo implode(' • ', $labels);
          ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>



<!-- Monthly comparison -->
<div class="soft-card mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-bold">Compare Months (Income vs Expenses)</div>
    <div class="text-muted small">Last 6 months</div>
  </div>
  <div style="height:320px">
    <canvas id="barMonthly"></canvas>
  </div>
</div>

<script>
const periodSelect = document.getElementById('period');
function syncPeriodFields() {
  const isMonth = periodSelect.value === 'month';
  const isYear  = periodSelect.value === 'year';
  document.querySelector('.month-field').style.display = isMonth ? '' : 'none';
  document.querySelector('.year-field').style.display  = isYear  ? '' : 'none';
}
periodSelect.addEventListener('change', syncPeriodFields);
syncPeriodFields();

// Donut
const donut = document.getElementById('donut').getContext('2d');
new Chart(donut, {
  type: 'doughnut',
  data: {
    labels: ['Balance','Expenses','Income'],
    datasets: [{
      data: [<?= max($balance,0) ?>, <?= $totalExpense ?>, <?= $totalIncome ?>],
      borderWidth: 0
    }]
  },
  options: {
    cutout: '65%',
    plugins: { legend: { position:'bottom' }, tooltip: { enabled:true } }
  }
});

// Bar: last 6 months (overall)
(function(){
  const ctx = document.getElementById('barMonthly').getContext('2d');
  const labels  = <?= json_encode($months) ?>;          // ["2025-03","2025-04",...]
  const income  = <?= json_encode($incomeByMonth) ?>;
  const expense = <?= json_encode($expenseByMonth) ?>;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Income',   data: income,  borderWidth: 0 },
        { label: 'Expenses', data: expense, borderWidth: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true }
      }
    }
  });
})();
</script>

<?php layout_footer(); ?>
