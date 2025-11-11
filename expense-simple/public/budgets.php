<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

$expenseCategories = [
  'Food',
  'Shopping',
  'Travel',
  'Bills',
  'Entertainment',
  'Health',
  'Education',
  'Other'
];

/* ---------- Category suggestions (from user's expenses) ---------- */
$catStmt = $pdo->prepare("
  SELECT DISTINCT TRIM(category) AS c
  FROM expenses
  WHERE user_id=? AND category IS NOT NULL AND TRIM(category)!=''
  ORDER BY LOWER(c)
");
$catStmt->execute([$u['id']]);
$categorySuggestions = $catStmt->fetchAll(PDO::FETCH_COLUMN);

/* ---------- Create ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create') {
  $name = trim($_POST['name'] ?? '');
  $period = $_POST['period_type'] ?? 'monthly';
  $amount = (float)($_POST['amount'] ?? 0);

  $category = trim($_POST['category'] ?? '');
  if ($category === '') $category = null;

  $monthVal = $_POST['month_value'] ?? null;
  $yearVal  = $_POST['year_value'] ?? null;
  $start = $_POST['start_date'] ?? null;
  $end   = $_POST['end_date']   ?? null;

  $errors = [];
  if ($name === '')            $errors[] = 'Name is required.';
  if ($amount <= 0)            $errors[] = 'Budget amount must be > 0.';
  if (!in_array($period, ['one_off','monthly','yearly'], true)) $errors[] = 'Invalid period type.';
  if ($period==='one_off') {
    if (!$start || !$end)      $errors[] = 'Start and end date are required for one-off budget.';
    elseif ($start > $end)     $errors[] = 'Start date must be before end date.';
  }
  if ($period==='monthly' && !$monthVal) $errors[] = 'Please select a month.';
  if ($period==='yearly' && !$yearVal)   $errors[] = 'Please select a year.';

   // Normalize category casing to match an existing one (avoids "travel" vs "Travel")
  if ($category !== null) {
    $pick = $pdo->prepare("SELECT category FROM expenses WHERE user_id=? AND LOWER(TRIM(category))=LOWER(?) LIMIT 1");
    $pick->execute([$u['id'], $category]);
    $existing = $pick->fetchColumn();
    if ($existing) $category = trim($existing);
  }


  if (!$errors) {
    $st = $pdo->prepare("INSERT INTO budgets(user_id,name,category,period_type,amount,start_date,end_date,month_value,year_value)
                         VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([$u['id'],$name,$category,$period,$amount,$start,$end,$monthVal,$yearVal]);
    header('Location: budgets.php'); exit;
  }
 
}




/* ---------- Delete ---------- */
if (($_GET['action'] ?? '') === 'delete') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id) {
    $st = $pdo->prepare("DELETE FROM budgets WHERE id=? AND user_id=?");
    $st->execute([$id, $u['id']]);
  }
  header('Location: budgets.php'); exit;
}

/* ---------- Helpers ---------- */
function budget_window(array $b): array {
  $today = new DateTime('today');

  if ($b['period_type']==='one_off') {
    $start = new DateTime($b['start_date'] ?? '1970-01-01');
    $end   = new DateTime($b['end_date']   ?? '2099-12-31');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
  }

  if ($b['period_type'] === 'monthly') {
    $year  = $b['year_value'] ?? $today->format('Y'); // fallback: current year
    $month = $b['month_value'] ?? $today->format('m'); // fallback: current month

    // First day of month
    $start = new DateTime("$year-$month-01");
    // Last day of month
    $end   = (clone $start)->modify('last day of this month');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
  }

  if ($b['period_type'] === 'yearly') {
    $year  = $b['year_value'] ?? $today->format('Y'); // fallback: current year
    return ["$year-01-01", "$year-12-31"];
  }

  // Fallback: whole year
  $year = $today->format('Y');
  return ["$year-01-01", "$year-12-31"];
}

/* ---------- Fetch budgets ---------- */
$st = $pdo->prepare("SELECT * FROM budgets WHERE user_id=? ORDER BY created_at DESC");
$st->execute([$u['id']]);
$budgets = $st->fetchAll();



layout_header('Budgets');
?>
<h4 class="mb-3">Budgets</h4>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="soft-card">
      <div class="fw-bold mb-2">Create Budget</div>
      
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2">
          <ul class="mb-0">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
     
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="create">

        <div class="col-12">
          <label class="form-label fw-semibold">Category</label>
          
          <select class="form-select" name="category" id="categorySelect">
            <option value="">All categories</option>
            <?php foreach ($expenseCategories as $c): ?>
              <option value="<?= h($c) ?>"><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>


          <input type="text"
                 class="form-control mt-2"
                 name="custom_category"
                 id="customCategoryInput"
                 placeholder="Enter custom category"
                 style="display:none;">

          <div class="form-text">
            Choose a category for this budget, select <b>Others</b> to type a new category, or pick <b>All categories</b>.
          </div>
        </div>
        
        <div class="col-12">
          <label class="form-label fw-semibold">Name</label>
          <input class="form-control" name="name" placeholder="e.g., Travel Thailand">
        </div>

        <!-- Period + Budget Amount side by side -->
        <div class="col-md-6">
          <label class="form-label fw-semibold">Period</label>
          <select class="form-select" name="period_type" id="period">
            <option value="monthly">Month</option>
            <option value="yearly">Year</option>
            <option value="one_off">Specific Date</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Budget Amount (RM)</label>
          <input type="number" min="0" step="0.01" class="form-control" name="amount" placeholder="1000">
        </div>

        <!-- Month dropdown (hidden initially) -->
        <div class="col-12 month-field" style="display:none;">
          <label class="form-label">Select Month</label>
          <select class="form-select" name="month_value">
            <?php 
              for ($m = 1; $m <= 12; $m++) {
                $monthName = date('F', mktime(0, 0, 0, $m, 1));
                echo "<option value='$m'>$monthName</option>";
              }
            ?>
          </select>
        </div>

        <!-- Year dropdown (hidden initially) -->
        <div class="col-12 year-field" style="display:none;">
          <label class="form-label">Select Year</label>
          <select class="form-select" name="year_value">
            <?php 
              $currentYear = date('Y');
              for ($i = 0; $i < 5; $i++) {
                $y = $currentYear + $i;
                echo "<option value='$y'>$y</option>";
              }
            ?>
          </select>
        </div>

              
              <!-- One-off fields -->
              <div class="col-md-6 oneoff-field" style="display:none">
                <label class="form-label fw-semibold">Start date</label>
                <input type="date" class="form-control" name="start_date">
              </div>
              <div class="col-md-6 oneoff-field" style="display:none">
                <label class="form-label fw-semibold">End date</label>
                <input type="date" class="form-control" name="end_date">
              </div>

              <div class="col-12">
                <button class="btn btn-purple w-100"><i class="bi bi-plus-lg me-1"></i>Create</button>
              </div>
            </form>
          </div>
        </div>

  <div class="col-lg-7">
    <div class="soft-card">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">Your Budgets</div>
      </div>

      <?php if (!$budgets): ?>
        <div class="text-muted">No budgets yet. Create your first budget on the left.</div>
      <?php else: ?>
        <div class="vstack gap-3">
          <?php foreach ($budgets as $b): ?>
            <?php
              // 🔥 NEW: Extra calculations
              $today = new DateTime();
              [$ws, $we] = budget_window($b);

              // calculate spent in this budget window
              $spentStmt = $pdo->prepare("
                SELECT IFNULL(SUM(amount),0)
                FROM expenses
                WHERE user_id=? 
                  AND date BETWEEN ? AND ?
                  " . ($b['category'] ? " AND category=?" : "")
              );
              $params = [$u['id'], $ws, $we];
              if ($b['category']) $params[] = $b['category'];
              $spentStmt->execute($params);
              $spent = (float)$spentStmt->fetchColumn();

              $limit = (float)$b['amount'];
              $daysLeft = max(0, $today->diff(new DateTime($we))->days);
              $remaining = max(0, $limit - $spent);
              $dailyAllowance = $daysLeft > 0 ? round($remaining / $daysLeft, 2) : 0;
              $percent = $limit > 0 ? round(($spent / $limit) * 100) : 0;

              if ($spent >= $limit) {
                  $status = ["Exceeded", "danger", "⚠️"];
              } elseif ($percent >= 80) {
                  $status = ["Almost full", "warning", "🔔"];
              } else {
                  $status = ["On track", "success", "✅"];
              }
            ?>

            <!-- 🔥 NEW Budget Card -->
            <div class="soft-card mb-3">
              <div class="fw-bold">🎯 Budget: <?= h($b['name']) ?> (<?= h($b['category'] ?? 'All') ?>)</div>
              <div class="text-muted small">
                Period: <?= h($ws) ?> → <?= h($we) ?>
              </div>

              <div class="mt-2">
                <strong>Spent:</strong> RM<?= number_format($spent,2) ?> /
                <strong>Budget:</strong> RM<?= number_format($limit,2) ?> (<?= $percent ?>%)<br>
                <strong>Remaining:</strong> RM<?= number_format($remaining,2) ?>
                <?php if ($daysLeft > 0): ?>
                  (≈ RM<?= number_format($dailyAllowance,2) ?>/day)
                <?php endif; ?>
              </div>

              <!-- Progress bar -->
              <div class="progress my-2" style="height:20px;">
                <div class="progress-bar bg-<?= $status[1] ?>"
                    role="progressbar"
                    style="width: <?= min($percent,100) ?>%;">
                  <?= $percent ?>%
                </div>
              </div>

              <div class="d-flex justify-content-between small">
                <span>Status: <?= $status[2] ?> <strong><?= $status[0] ?></strong></span>
                <span>⏳ <?= $daysLeft ?> days left</span>
              </div>

              <div class="mt-2">
                <a href="view_transactions.php?budget=<?= $b['id'] ?>" class="btn btn-sm btn-outline-secondary">View Transactions</a>
                <a href="edit_budget.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                <a href="budgets.php?action=delete&id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-danger"
                  onclick="return confirm('Delete this budget?');">Delete</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const periodSel = document.getElementById('period');
function syncFields(){
  const isOneOff = periodSel.value === 'one_off';
  const isMonthly = periodSel.value === 'monthly';
  const isYearly = periodSel.value === 'yearly';

  document.querySelectorAll('.oneoff-field').forEach(el => el.style.display = isOneOff ? '' : 'none');
  document.querySelectorAll('.month-field').forEach(el => el.style.display = isMonthly ? '' : 'none');
  document.querySelectorAll('.year-field').forEach(el => el.style.display = isYearly ? '' : 'none');
}
periodSel.addEventListener('change', syncFields);
syncFields();

const categorySelect = document.getElementById('categorySelect');
const customCategoryInput = document.getElementById('customCategoryInput');
function syncCustomCategory(){
  if (categorySelect.value === 'Others') {
    customCategoryInput.style.display = '';
    customCategoryInput.required = true;
  } else {
    customCategoryInput.style.display = 'none';
    customCategoryInput.required = false;
    customCategoryInput.value = '';
  }
}
categorySelect.addEventListener('change', syncCustomCategory);
syncCustomCategory();
</script>

<?php layout_footer(); ?>
