<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

/* --- Fetch Budget --- */
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM budgets WHERE id=? AND user_id=?");
$st->execute([$id,$u['id']]);
$budget = $st->fetch();

if (!$budget) {
    die("Budget not found.");
}

/* --- Category suggestions --- */
$catStmt = $pdo->prepare("
  SELECT DISTINCT TRIM(category) AS c
  FROM expenses
  WHERE user_id=? AND category IS NOT NULL AND TRIM(category)!=''
  ORDER BY LOWER(c)
");
$catStmt->execute([$u['id']]);
$categorySuggestions = $catStmt->fetchAll(PDO::FETCH_COLUMN);

/* --- Handle Save --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='edit') {
    $name   = trim($_POST['name'] ?? '');
    $period = $_POST['period_type'] ?? 'monthly';
    $amount = (float)($_POST['amount'] ?? 0);

    $categorySel = trim($_POST['category'] ?? '');
    if ($categorySel === 'Others') {
        $category = trim($_POST['custom_category'] ?? '');
    } else {
        $category = $categorySel;
    }
    if ($category === '') $category = null;

    $start = $_POST['start_date'] ?? null;
    $end   = $_POST['end_date']   ?? null;

    if ($name !== '' && $amount > 0) {
        $st = $pdo->prepare("UPDATE budgets 
                             SET name=?, amount=?, category=?, period_type=?, start_date=?, end_date=? 
                             WHERE id=? AND user_id=?");
        $st->execute([$name, $amount, $category, $period, $start, $end, $id, $u['id']]);
        header("Location: budgets.php"); exit;
    } else {
        $error = "Please fill all required fields.";
    }
}

layout_header("Edit Budget");
?>
<h4 class="mb-3">Edit Budget</h4>

<div class="soft-card">
  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= $budget['id'] ?>">

    <!-- Name -->
    <div class="col-12">
      <label class="form-label fw-semibold">Name</label>
      <input class="form-control" name="name" value="<?= h($budget['name']) ?>">
    </div>

    <!-- Category -->
    <div class="col-12">
      <label class="form-label fw-semibold">Applies to Category</label>
      <select class="form-select" name="category" id="categorySelect">
        <option value="" <?= !$budget['category']?'selected':'' ?>>All categories</option>
        <?php foreach ($categorySuggestions as $c): ?>
          <option value="<?= h($c) ?>" <?= $budget['category']===$c?'selected':'' ?>>
            <?= h($c) ?>
          </option>
        <?php endforeach; ?>
        <option value="Others">Others</option>
      </select>
      <input type="text"
             class="form-control mt-2"
             name="custom_category"
             id="customCategoryInput"
             placeholder="Enter custom category"
             style="display:none;">
    </div>

    <!-- Period -->
    <div class="col-md-6">
      <label class="form-label fw-semibold">Period</label>
      <select class="form-select" name="period_type" id="period">
        <option value="monthly" <?= $budget['period_type']==='monthly'?'selected':'' ?>>Monthly</option>
        <option value="yearly"  <?= $budget['period_type']==='yearly'?'selected':'' ?>>Yearly</option>
        <option value="one_off" <?= $budget['period_type']==='one_off'?'selected':'' ?>>One-off (date range)</option>
      </select>
    </div>

    <!-- Amount -->
    <div class="col-md-6">
      <label class="form-label fw-semibold">Budget Amount (RM)</label>
      <input type="number" min="0" step="0.01" class="form-control"
             name="amount" value="<?= h($budget['amount']) ?>">
    </div>

    <!-- Date range (only for one-off) -->
    <div class="col-md-6 oneoff-field" style="<?= $budget['period_type']==='one_off'?'':'display:none' ?>">
      <label class="form-label fw-semibold">Start date</label>
      <input type="date" class="form-control" name="start_date" value="<?= h($budget['start_date']) ?>">
    </div>
    <div class="col-md-6 oneoff-field" style="<?= $budget['period_type']==='one_off'?'':'display:none' ?>">
      <label class="form-label fw-semibold">End date</label>
      <input type="date" class="form-control" name="end_date" value="<?= h($budget['end_date']) ?>">
    </div>

    <div class="col-12">
      <button class="btn btn-purple w-100">
        <i class="bi bi-save me-1"></i> Save Changes
      </button>
    </div>
  </form>
</div>

<script>
const periodSel = document.getElementById('period');
function syncFields(){
  const isOneOff = periodSel.value === 'one_off';
  document.querySelectorAll('.oneoff-field').forEach(el => el.style.display = isOneOff ? '' : 'none');
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
