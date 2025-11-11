<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

/* ---------- Fetch ALL Income + Expense ---------- */
$sql = "
  SELECT date, type AS label, amount, 'Income' AS entry_type
  FROM income
  WHERE user_id = ?
  UNION ALL
  SELECT date, category AS label, amount, 'Expense' AS entry_type
  FROM expenses
  WHERE user_id = ?
  ORDER BY date DESC
";


$stmt = $pdo->prepare($sql);
$stmt->execute([$u['id'], $u['id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Totals ---------- */
$totalIncome = 0;
$totalExpense = 0;
foreach ($rows as $r) {
  if ($r['entry_type'] === 'Income') $totalIncome += (float)$r['amount'];
  else $totalExpense += (float)$r['amount'];
}
$net = $totalIncome - $totalExpense;

layout_header('Total Transactions');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">All Transactions</h3>
  <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
</div>

<div class="soft-card mb-4 p-3">
  <div class="row text-center">
    <div class="col-md-4">
      <div class="fw-semibold text-success">Total Income</div>
      <div class="fs-4 fw-bold text-success">+RM<?= number_format($totalIncome,2) ?></div>
    </div>
    <div class="col-md-4">
      <div class="fw-semibold text-danger">Total Expense</div>
      <div class="fs-4 fw-bold text-danger">-RM<?= number_format($totalExpense,2) ?></div>
    </div>
    <div class="col-md-4">
      <div class="fw-semibold text-primary">Net Balance</div>
      <div class="fs-4 fw-bold <?= $net>=0?'text-success':'text-danger' ?>">
        <?= $net>=0?'+':'-' ?>RM<?= number_format(abs($net),2) ?>
      </div>
    </div>
  </div>
</div>

<div class="soft-card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Label</th>
          <th class="text-end">Amount (RM)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-secondary">No transactions yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['date']) ?></td>
            <td><?= h($r['entry_type']) ?></td>
            <td><?= h($r['label']) ?></td>
            <td class="text-end <?= $r['entry_type']==='Income'?'text-success':'text-danger' ?>">
              <?= $r['entry_type']==='Income'?'+':'-' ?>RM<?= number_format((float)$r['amount'],2) ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
