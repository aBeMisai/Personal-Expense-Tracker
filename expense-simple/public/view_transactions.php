<?php
require_once __DIR__.'/../inc/layout.php';
require_login();

/* ---------- Helpers ---------- */
function budget_window(array $b): array {
  $today = new DateTime('today');

  if ($b['period_type']==='one_off') {
    $start = new DateTime($b['start_date'] ?? '1970-01-01');
    $end   = new DateTime($b['end_date']   ?? '2099-12-31');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
  }

  if ($b['period_type']==='monthly') {
    $start = new DateTime($today->format('Y-m-01'));
    $end   = new DateTime($today->format('Y-m-t')); // last day
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
  }

  $year  = $today->format('Y');
  return ["$year-01-01", "$year-12-31"];
}


$u = current_user();

$budgetId = (int)($_GET['budget'] ?? 0);

// fetch budget
$st = $pdo->prepare("SELECT * FROM budgets WHERE id=? AND user_id=?");
$st->execute([$budgetId, $u['id']]);
$b = $st->fetch();

if (!$b) { die("Budget not found."); }

// get window
[$ws, $we] = budget_window($b);

// fetch expenses in that window
$sql = "SELECT * FROM expenses WHERE user_id=? AND date BETWEEN ? AND ?";
$params = [$u['id'], $ws, $we];
if ($b['category']) {
    $sql .= " AND category=?";
    $params[] = $b['category'];
}
$st = $pdo->prepare($sql . " ORDER BY date DESC");
$st->execute($params);
$expenses = $st->fetchAll();

layout_header("Transactions for ".$b['name']);
?>
<h4>Transactions for Budget: <?= h($b['name']) ?></h4>
<p class="text-muted"><?= h($ws) ?> → <?= h($we) ?> <?= $b['category'] ? "• ".h($b['category']) : '' ?></p>

<?php if (!$expenses): ?>
  <div class="alert alert-info">No transactions in this budget window.</div>
<?php else: ?>
  <table class="table table-sm">
    <thead>
      <tr><th>Date</th><th>Category</th><th>Amount</th><th>Note</th></tr>
    </thead>
    <tbody>
      <?php foreach ($expenses as $e): ?>
        <tr>
          <td><?= h($e['date']) ?></td>
          <td><?= h($e['category']) ?></td>
          <td>-RM<?= number_format($e['amount'],2) ?></td>
          <td><?= h($e['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<a href="budgets.php" class="btn btn-secondary">⬅ Back to Budgets</a>
<?php layout_footer(); ?>
