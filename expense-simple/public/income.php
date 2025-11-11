<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();
$incomeTypes = ['Salary','Investments','Part-Time','Bonus','Others'];


/* --------- FILTERS --------- */
$range    = $_GET['range']    ?? 'month';   // month | last_month | year | custom | all
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to']   ?? '');
$q        = trim($_GET['q'] ?? '');

$today = new DateTime('today');
if ($range === 'month') {
  $from = $today->format('Y-m-01');
  $to   = $today->format('Y-m-t');
} elseif ($range === 'last_month') {
  $first = (clone $today)->modify('first day of last month');
  $last  = (clone $today)->modify('last day of last month');
  $from = $first->format('Y-m-d');
  $to   = $last->format('Y-m-d');
} elseif ($range === 'year') {
  $from = $today->format('Y-01-01');
  $to   = $today->format('Y-12-31');
} elseif ($range === 'custom') {
  if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
  if ($to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';
} elseif ($range === 'all') {
  $from = $to = '';
} else {
  $range = 'month';
  $from = $today->format('Y-m-01');
  $to   = $today->format('Y-m-t');
}

/* Build WHERE */
$where = ["user_id=?"];
$args  = [$u['id']];

if ($from !== '' && $to !== '') {
  $where[] = "date BETWEEN ? AND ?";
  $args[] = $from; $args[] = $to;
} elseif ($from !== '') {
  $where[] = "date >= ?";
  $args[] = $from;
} elseif ($to !== '') {
  $where[] = "date <= ?";
  $args[] = $to;
}

if ($q !== '') {
  $where[] = "(type LIKE ? OR note LIKE ?)";
  $kw = "%$q%";
  $args[] = $kw; $args[] = $kw;
}

$whereSql = implode(' AND ', $where);

/* --- Add manual income --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='add') {
  $date   = $_POST['date'] ?? date('Y-m-d');
  $type   = trim($_POST['type'] ?? '');
  $other  = trim($_POST['other_type'] ?? '');
  $amount = (float)($_POST['amount'] ?? 0);
  $note   = trim($_POST['note'] ?? '');

  // If user chose Others and typed a custom label, use that as the final type.
  if ($type === 'Others' && $other !== '') $type = $other;

  // Safety: if someone tampers with the dropdown, still accept custom when they chose Others;
  // otherwise clamp to known choices.
  if ($type !== 'Others' && !in_array($type, $incomeTypes, true)) $type = 'Others';

  if ($type !== '' && $amount > 0) {
    $category = null; // we don't use category anymore
    $st=$pdo->prepare("INSERT INTO income(user_id,type,category,amount,note,date)
                       VALUES (?,?,?,?,?,?)");
    $st->execute([$u['id'],$type,$category,$amount,$note,$date]);
  }
  header('Location: income.php'); exit;
}

/* --- Update existing income --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id     = (int)$_POST['id'];
  $type   = trim($_POST['type'] ?? '');
  $other  = trim($_POST['other_type'] ?? '');
  $amount = (float)($_POST['amount'] ?? 0);
  $date   = $_POST['date'] ?? date('Y-m-d');
  $note   = trim($_POST['note'] ?? '');

  $cur = $pdo->prepare("SELECT id,user_id FROM income WHERE id=?");
  $cur->execute([$id]);
  $curRow = $cur->fetch();
  if (!$curRow || (int)$curRow['user_id'] !== (int)$u['id']) { header('Location: '.$_SERVER['PHP_SELF']); exit; }

  if ($type === 'Others' && $other !== '') $type = $other;
  if ($type !== 'Others' && !in_array($type, $incomeTypes, true)) $type = 'Others';

  $category = null;

  $st = $pdo->prepare("UPDATE income SET type=?, category=?, amount=?, note=?, date=? WHERE id=? AND user_id=?");
  $st->execute([$type,$category,$amount,$note,$date,$id,$u['id']]);

  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}


/* --- Delete --- */
if (isset($_GET['del'])) {
  $st=$pdo->prepare("DELETE FROM income WHERE id=? AND user_id=?");
  $st->execute([(int)$_GET['del'],$u['id']]);
  header('Location: income.php'); exit;
}

/* --- Load filtered data --- */
$listStmt = $pdo->prepare("
  SELECT
    id,
    user_id,
    `TYPE` AS type,   -- <- alias to lower-case
    `DATE` AS date,   -- <- alias to lower-case
    category,
    amount,
    note
  FROM income
  WHERE $whereSql
  ORDER BY `DATE` DESC, id DESC
");
$listStmt->execute($args);
$data = $listStmt->fetchAll();

/* For chart: aggregate per date */
if ($range === 'all') {
  $chartStmt = $pdo->prepare("
    SELECT DATE_FORMAT(`DATE`, '%Y-%m') AS ym, IFNULL(SUM(amount),0) AS s
    FROM income
    WHERE $whereSql
    GROUP BY DATE_FORMAT(`DATE`, '%Y-%m')
    ORDER BY ym ASC
  ");
  $chartStmt->execute($args);
  $labels = []; $vals = [];
  foreach ($chartStmt as $r) { $labels[] = $r['ym']; $vals[] = round((float)$r['s'], 2); }
  $chartTitle = 'Income by Month';
} else {
  $chartStmt = $pdo->prepare("
    SELECT `DATE` AS d, IFNULL(SUM(amount),0) AS s
    FROM income
    WHERE $whereSql
    GROUP BY `DATE`
    ORDER BY `DATE` ASC
  ");
  $chartStmt->execute($args);
  $labels = []; $vals = [];
  foreach ($chartStmt as $r) { $labels[] = $r['d']; $vals[] = round((float)$r['s'], 2); }
  $chartTitle = 'Income Overview';
}

/* Total */
$totStmt = $pdo->prepare("
  SELECT IFNULL(SUM(amount),0)
  FROM income
  WHERE $whereSql
");
$totStmt->execute($args);
$totalIncome = (float)$totStmt->fetchColumn();

layout_header('Income'); ?>

<!-- Include Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0"><?= h($chartTitle) ?></h4>
  <div class="d-flex gap-2">
    <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#addIncome"><i class="bi bi-plus-lg me-1"></i> Add Income</button>
  </div>
</div>

<div class="soft-card mb-3">
  <form method="get" class="row gy-2 gx-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label fw-semibold">Time range</label>
      <select name="range" id="rangeSel" class="form-select">
        <option value="month"      <?= $range==='month'?'selected':'' ?>>This month</option>
        <option value="last_month" <?= $range==='last_month'?'selected':'' ?>>Last month</option>
        <option value="year"       <?= $range==='year'?'selected':'' ?>>This year</option>
        <option value="custom"     <?= $range==='custom'?'selected':'' ?>>Custom</option>
        <option value="all"        <?= $range==='all'?'selected':'' ?>>All time</option>
      </select>
    </div>
    <div class="col-md-3 custom-field" style="display:none">
      <label class="form-label fw-semibold">From</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
    </div>
    <div class="col-md-3 custom-field" style="display:none">
      <label class="form-label fw-semibold">To</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Search</label>
      <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Type, Category or Notes">
    </div>
    <div class="col-md-3">
      <button class="btn btn-purple w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
    <div class="col-md-2">
      <a class="btn btn-outline-secondary w-100" href="income.php"><i class="bi bi-x-circle me-1"></i>Reset</a>
    </div>
  </form>

  <canvas id="incLine" width="400" height="400" class="mt-3"></canvas>
</div>

<div class="soft-card">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-bold">All Income</div>
    <div class="text-muted small">
      <?php if ($from || $to): ?>
        Range: <b><?= h($from ?: '…') ?></b> → <b><?= h($to ?: '…') ?></b>
      <?php else: ?>
        Range: <b>All time</b>
      <?php endif; ?>
      • Total: <span class="chip-pos">+RM<?= number_format($totalIncome, 2) ?></span>
      • Showing <?= count($data) ?> item(s)
    </div>
  </div>

  <div class="listy">
    <?php if (!$data): ?>
      <div class="text-muted">No income yet.</div>
    <?php endif; ?>

    <?php foreach ($data as $r): ?>
       <?php
      // Raw values (cast to string so h() never gets null)
      $rawType   = trim((string)($r['type'] ?? ''));
      $legacy    = trim((string)($r['category'] ?? '')); // old rows may have this
      $dateText  = trim((string)($r['date'] ?? ''));
      $noteText  = trim((string)($r['note'] ?? ''));
      $amountNum = (float)($r['amount'] ?? 0);

      // If type is empty, fall back to category
      if ($rawType === '' && $legacy !== '') $rawType = $legacy;

      // Decide what to display
      if (in_array($rawType, $incomeTypes, true)) {
        // Fixed types: Salary / Investments / Part-Time / Bonus / Others
        if ($rawType === 'Others') {
          // Prefer category for old rows; fall back to note
          $detail = $legacy !== '' ? $legacy : $noteText;
          $showType = $detail !== '' ? ('Other: '.$detail) : 'Other';
        } else {
          $showType = $rawType;
        }
      } else {
        // Custom type saved when choosing "Others"
        $showType = 'Other: '.$rawType;
      }

      // Choose an icon (optional)
      $icon = 'bi-graph-up';
      switch ($rawType) {
        case 'Salary':       $icon = 'bi-briefcase';     break;
        case 'Bonus':        $icon = 'bi-gift';          break;
        case 'Investments':  $icon = 'bi-piggy-bank';    break;
        case 'Part-Time':    $icon = 'bi-clock-history'; break;
        default:
          if ($rawType !== '' && !in_array($rawType, $incomeTypes, true)) {
            $icon = 'bi-cash-coin';
          }
      }
      ?>

      <div class="rowi">
        <!-- LEFT: icon + text -->
        <div class="d-flex align-items-center gap-3">
          <div class="inc-bubble">
            <i class="bi <?= h($icon) ?>"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= h($showType) ?></div>
            <?php if ($dateText !== ''): ?>
              <div class="text-muted small"><?= h($dateText) ?></div>
            <?php endif; ?>
            <?php if ($noteText !== ''): ?>
              <div class="small text-secondary"><?= h($noteText) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- RIGHT: amount + actions -->
        <div class="d-flex align-items-center gap-2">
          <div class="chip-pos">+RM<?= number_format($amountNum, 2) ?></div>

          <button
            type="button"
            class="btn btn-sm btn-outline-secondary edit-btn"
            data-bs-toggle="modal"
            data-bs-target="#editIncome"
            data-id="<?= (int)($r['id'] ?? 0) ?>"
            data-type="<?= h($rawType) ?>"
            data-amount="<?= h((string)$amountNum) ?>"
            data-date="<?= h($dateText) ?>"
            data-note="<?= h($noteText) ?>"
          >
            <i class="bi bi-pencil-square"></i>
          </button>

          <a class="btn btn-sm btn-outline-danger"
            href="?del=<?= (int)($r['id'] ?? 0) ?>"
            onclick="return confirm('Delete income?')">
            <i class="bi bi-trash"></i>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</div>

<!-- Add Income (manual) -->
<div class="modal fade" id="addIncome" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="add">
      <div class="modal-header"><h5 class="modal-title">Add Income</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Type *</label>
          <select class="form-select" name="type" id="add-type" required>
            <?php foreach ($incomeTypes as $t) echo "<option>".h($t)."</option>"; ?>
          </select>
        </div>

        <!-- Shown only if Type = Others -->
        <div class="mb-3" id="add-other-type-wrap" style="display:none">
          <label class="form-label">Specify type</label>
          <input class="form-control" name="other_type" id="add-other-type" placeholder="e.g., Rental, Commission">
        </div>

        <div class="mb-3"><label class="form-label">Amount</label><input type="number" step="0.01" min="0" class="form-control" name="amount" required></div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" class="form-control" name="date" required></div>
        <div class="mb-3"><label class="form-label">Note</label><input class="form-control" name="note" placeholder="Optional"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-purple">Add Income</button></div>
    </form>
  </div>
</div>

<!-- Edit Income Modal -->
<div class="modal fade" id="editIncome" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal-header">
        <h5 class="modal-title">Edit Income</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Type *</label>
          <select class="form-select" name="type" id="edit-type" required>
            <?php foreach ($incomeTypes as $t) echo "<option>".h($t)."</option>"; ?>
          </select>
        </div>

        <div class="mb-3" id="edit-other-type-wrap" style="display:none">
          <label class="form-label">Specify type</label>
          <input class="form-control" name="other_type" id="edit-other-type" placeholder="e.g., Rental, Commission">
        </div>

        <div class="mb-3"><label class="form-label">Amount</label>
          <input type="number" step="0.01" min="0" class="form-control" name="amount" id="edit-amount" required>
        </div>

        <div class="mb-3"><label class="form-label">Date</label>
          <input type="date" class="form-control" name="date" id="edit-date" required>
        </div>

        <div class="mb-3"><label class="form-label">Note</label>
          <input class="form-control" name="note" id="edit-note" placeholder="Optional">
        </div>
      </div>

      <div class="modal-footer"><button class="btn btn-purple">Save Changes</button></div>
    </form>
  </div>
</div>

<script>
/* When opening Edit modal:
   - If the record's type is one of the fixed options (Salary/Investments/Part-Time/Bonus/Others),
     just select it.
   - If it's a custom label (previously saved when choosing "Others"),
     select "Others" and show the text box with that custom label. */

function bindOtherTypeWithRequired(selectEl, wrapEl, inputEl) {
  const toggle = () => {
    const show = (selectEl.value === 'Others');
    wrapEl.style.display = show ? '' : 'none';
    inputEl.required = show;   // enforce filling when Others
    if (!show) inputEl.value = '';
  };
  selectEl.addEventListener('change', toggle);
  toggle();
}

/* --- Edit modal binding (keeps your existing behaviour) --- */
function bindOtherType(selectEl, wrapEl) {
  const toggle = () => { wrapEl.style.display = (selectEl.value === 'Others') ? '' : 'none'; };
  selectEl.addEventListener('change', toggle);
  toggle();
}

// Enable "Others → Specify type" toggle for the ADD modal
document.addEventListener('DOMContentLoaded', () => {
  const addTypeSel = document.getElementById('add-type');
  const addWrap    = document.getElementById('add-other-type-wrap');
  const addInput   = document.getElementById('add-other-type');
  if (addTypeSel && addWrap && addInput) {
    bindOtherTypeWithRequired(addTypeSel, addWrap, addInput);
  }
});


// Enable "Others → Specify type" toggle for the EDIT modal

document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    // Fill standard fields
    document.getElementById('edit-id').value     = btn.dataset.id;
    document.getElementById('edit-amount').value = btn.dataset.amount || '';
    document.getElementById('edit-date').value   = btn.dataset.date   || '';
    document.getElementById('edit-note').value   = btn.dataset.note   || '';

    // Decide how to set Type / Other Type
    const fixed       = <?= json_encode($incomeTypes) ?>;   // ['Salary','Investments','Part-Time','Bonus','Others']
    const currentType = btn.dataset.type || '';

    const editTypeSel = document.getElementById('edit-type');
    const otherWrap   = document.getElementById('edit-other-type-wrap');
    const otherInput  = document.getElementById('edit-other-type');

    if (fixed.includes(currentType)) {
      editTypeSel.value = currentType;
      otherInput.value  = '';
    } else {
      editTypeSel.value = 'Others';
      otherInput.value  = currentType;
    }
    // Also require the field when Others on EDIT modal:
    bindOtherTypeWithRequired(editTypeSel, otherWrap, otherInput);
  });
});
</script>

<style>
#incLine {
  max-width: 400px;
  max-height: 400px;
  margin: 0 auto;
}

.listy .rowi {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f0f2f7;
  padding: 10px 0;
}

.chip-pos {
  color: #10b981;
  font-weight: 700;
}

.soft-card {
  background: #ffffff;
  border: 0;
  border-radius: 16px;
  padding: 16px;
  box-shadow: 0 12px 40px rgba(17, 24, 39, .06);
}

.btn-purple {
  background: #6366f1;
  border-color: #6366f1;
}

.btn-purple:hover {
  background: #4f46e5;
  border-color: #4f46e5;
}
</style>

<!-- Include Bootstrap JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
  const canvas = document.getElementById('incLine');
  const labels = <?= json_encode($labels) ?>;   // PHP → JS
  const values = <?= json_encode($vals) ?>;

  // If nothing to draw, hide the canvas
  if (!canvas || labels.length === 0 || values.every(v => +v === 0)) {
    if (canvas) canvas.style.display = 'none';
    return;
  }

  // Generate enough colors for any number of slices
  const colors = labels.map((_, i) => `hsl(${(i * 47) % 360} 70% 55%)`);

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        label: 'Income',
        data: values,
        backgroundColor: colors,
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          labels: { boxWidth: 20, font: { size: 14 } }
        }
      }
    }
  });
})();
</script>

<?php layout_footer(); ?>
