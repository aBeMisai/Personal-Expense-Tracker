<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

$cats = [
  'Food & Dining',
  'Transportation',
  'Bills & Utilities',
  'Shopping',
  'Personal Care',
  'Health & Fitness',
  'Education',
  'Entertainment & Leisure',
  'Travel & Vacation',
  'Other'
];

/* --------- HELPERS: normalize OCR values for the form --------- */
function normalize_date_for_input(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';

  // Try common formats first
  $fmts = ['Y-m-d','d/m/Y','d-m-Y','m/d/Y','d.m.Y','Y/m/d'];
  foreach ($fmts as $f) {
    $dt = DateTime::createFromFormat($f, $raw);
    if ($dt && $dt->format($f) === $raw) return $dt->format('Y-m-d'); // HTML <input type="date">
  }

  // Last chance: pick numbers like 30/09/2025, 30-09-25, 30.09.2025
  if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $raw, $m)) {
    $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $M = str_pad($m[2], 2, '0', STR_PAD_LEFT);
    $y = (int)$m[3]; if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, $M, $d);
  }

  return '';
}

function normalize_amount(string $raw): string {
  $raw = strtoupper(trim($raw));
  // Remove currency text and spaces
  $raw = str_replace(['RM','MYR','$',' '], '', $raw);
  // If it's like 38,02 (comma decimal, no dots), flip comma to dot
  if (preg_match('/^\d+,\d{2}$/', $raw)) $raw = str_replace(',', '.', $raw);
  // Remove thousands separators
  $raw = str_replace(',', '', $raw);
  return is_numeric($raw) ? number_format((float)$raw, 2, '.', '') : '';
}


/* --------- FILTERS --------- */
$range    = $_GET['range']    ?? 'month';   // month | last_month | year | custom | all
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to']   ?? '');
$category = trim($_GET['category'] ?? '');
$q        = trim($_GET['q'] ?? '');
$amin     = isset($_GET['amin']) ? trim($_GET['amin']) : '';
$amax     = isset($_GET['amax']) ? trim($_GET['amax']) : '';

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

if ($category !== '') {
  if ($category === 'Other') {
    $where[] = "category LIKE 'Other%'";
  } else {
    $where[] = "category = ?";
    $args[]  = $category;
  }
}

if ($q !== '') {
  $where[] = "(category LIKE ? OR note LIKE ?)";
  $kw = "%$q%";
  $args[] = $kw; $args[] = $kw;
}

if ($amin !== '' && is_numeric($amin)) { $where[] = "amount >= ?"; $args[] = (float)$amin; }
if ($amax !== '' && is_numeric($amax)) { $where[] = "amount <= ?"; $args[] = (float)$amax; }

$whereSql = implode(' AND ', $where);

/* --- Add manual expense (no receipt via this form) --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='add') {
  $date = $_POST['date'] ?? date('Y-m-d');
  $categoryIn = trim($_POST['category'] ?? 'Other');
  $amount = (float)($_POST['amount'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($categoryIn!=='' && $amount>0) {
    $receiptPath = trim($_POST['receipt_rel'] ?? '');
    if ($receiptPath === '') $receiptPath = null;
    $st=$pdo->prepare("INSERT INTO expenses(user_id,date,category,amount,note,receipt_blob,receipt_type,created_at) 
                   VALUES (?,?,?,?,?,?,?,NOW())");
    $st->bindValue(1, $u['id']);
    $st->bindValue(2, $date);
    $st->bindValue(3, $categoryIn);
    $st->bindValue(4, $amount);
    $st->bindValue(5, $note);
    $st->bindValue(6, null, PDO::PARAM_LOB);  // manual add has no receipt
    $st->bindValue(7, null);
    $st->execute();

  }
  header('Location: expenses.php'); exit;
}

/* --- Upload receipt -> open Review modal --- */
$review = null; $flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='upload_receipt') {
  if (!empty($_FILES['receipt']['name'])) {
    $okTypes = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf','image/webp'=>'webp','image/gif'=>'gif'];
    $tmp = $_FILES['receipt']['tmp_name'];
    $type = @mime_content_type($tmp) ?: ($_FILES['receipt']['type'] ?? '');
    
    if (!isset($okTypes[$type])) {
      $flash = 'Only JPG, PNG, WEBP, GIF, or PDF are allowed.';
    } else {
      
      $data = file_get_contents($tmp);
      $ext  = $okTypes[$type];
      $b64  = null;

      if (in_array($ext,['jpg','jpeg','png','webp','gif'])) {
        $b64 = 'data:'.$type.';base64,'.base64_encode($data);
      }

      $review = [
      'file' => $_FILES['receipt']['name'],
      'ext'  => $ext,
      'ts'   => date('Y-m-d'),
      'b64'  => $b64,
      'blob' => base64_encode($data),
      'type' => $type,
      ];
      // Save to temp location

        // === Run PaddleOCR (separate stdout/stderr; only parse stdout JSON) ===

        // 1) Paths
        $imagePath = realpath($tmp);
        $script    = dirname(__DIR__) . '/ocr/extract_receipt.py';
        $python    = getenv('SMARTSPEND_PYTHON') ?: 'C:\\Users\\acer\\AppData\\Local\\Programs\\Python\\Python310\\python.exe';

        // 2) Write a stable temp copy next to /ocr (Windows sometimes locks PHP temp files)
        $workImg = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . ('_work_' . uniqid() . '.' . $ext);
        file_put_contents($workImg, $data);

        // 3) Build command (DO NOT use 2>&1 — we want stderr separated)
        $cmd = (PHP_OS_FAMILY === 'Windows')
          ? '"' . $python . '" "' . $script . '" "' . $workImg . '"'
          : escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($workImg);

        // 4) Run with proc_open so we can read stdout and stderr independently
        $descriptors = [
          0 => ['pipe', 'r'], // stdin
          1 => ['pipe', 'w'], // stdout -> JSON
          2 => ['pipe', 'w'], // stderr -> logs
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = $stderr = ''; $exit = -1;
        if (is_resource($proc)) {
          fclose($pipes[0]);                                // we won't write to stdin
          $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
          $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
          $exit   = proc_close($proc);
        }

        // 5) Clean up the working image
        @unlink($workImg);

        // 6) Write a single debug file with everything (don’t overwrite it again later)
        file_put_contents(
          dirname(__DIR__) . '/ocr_last_output.txt',
          "CMD:\n$cmd\n\nEXIT:$exit\n\nSTDOUT:\n$stdout\n\nSTDERR:\n$stderr"
        );

        // 7) Decode ONLY stdout (this is the clean JSON from extract_receipt.py)
        $ocrData = json_decode($stdout, true);
        if ($ocrData === null) {
          error_log('OCR JSON decode error: ' . json_last_error_msg());
          $flash = 'Could not parse OCR output. Open ocr_last_output.txt for details.';
        }

        if (is_array($ocrData)) {
          // Helpers for mapping and normalization
          $mapToKnown = function(string $label, array $known) {
            $labelN = strtolower(trim($label));
            $syn = [
              'food & beverage' => 'Food & Dining',
              'f&b'             => 'Food & Dining',
              'groceries'       => 'Food & Dining',
              'utility'         => 'Bills & Utilities',
              'utilities'       => 'Bills & Utilities',
              'medical'         => 'Health & Fitness',
              'healthcare'      => 'Health & Fitness',
              'entertainment'   => 'Entertainment & Leisure',
              'shopping'        => 'Shopping',
              'travel'          => 'Travel & Vacation',
              'personal care'   => 'Personal Care',
            ];
            $labelStd = $syn[$labelN] ?? null;
            if ($labelStd && in_array($labelStd, $known, true)) return $labelStd;
            // if already a known one
            foreach ($known as $k) if (strtolower($k) === $labelN) return $k;
            return 'Other';
          };

          // Top fields
          $cleanDate = normalize_date_for_input($ocrData['date'] ?? '');
          $merchant  = trim($ocrData['merchant'] ?? '');
          $totalAmt  = normalize_amount($ocrData['amount'] ?? '');

          // Items (array of rows)
          $items = [];
          if (!empty($ocrData['items']) && is_array($ocrData['items'])) {
            foreach ($ocrData['items'] as $it) {
              $qty  = (int)($it['qty'] ?? 1);
              if ($qty < 1) $qty = 1;
              $desc = trim($it['desc'] ?? '');
              $amt  = normalize_amount((string)($it['total'] ?? $it['unit_price'] ?? ''));
              $cat  = $mapToKnown((string)($it['category'] ?? 'Other'), $cats);

              // Skip completely empty lines
              if ($desc === '' && ($amt === '' || (float)$amt <= 0)) continue;

              $items[] = [
                'qty'      => $qty,
                'desc'     => $desc,
                'category' => $cat,
                'amount'   => $amt,    // string "4.95"
              ];
            }
          }

          // If Python didn’t provide items, fallback to one row using total
          if (!$items) {
            $items[] = [
              'qty' => 1,
              'desc' => '',
              'category' => 'Other',
              'amount' => $totalAmt ?: '0.00',
            ];
          }

          // Recompute total from items if missing
          if ($totalAmt === '' || (float)$totalAmt <= 0) {
            $sum = 0.0;
            foreach ($items as $r) $sum += (float)($r['amount'] ?? 0);
            $totalAmt = number_format($sum, 2, '.', '');
          }

          // Build the data your form will use
          $review['ocr'] = [
            'merchant' => $merchant,
            'amount'   => $totalAmt,                             // numeric as string
            'date'     => $cleanDate ?: date('Y-m-d'),           // ALWAYS Y-m-d for <input type="date">
            'items'    => $items,                                // <<— NEW
            'raw'      => trim($ocrData['raw_text'] ?? ''),
          ];
        } else {
          error_log('PaddleOCR failed or returned non-JSON.');
        }

              
    }
  } else {
    $flash = 'Please choose a file.';
  }
}




/* --- Save expense from Review modal (with receipt) --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_receipt') {
  $date     = $_POST['date'] ?? date('Y-m-d');
  $merchant = trim($_POST['merchant'] ?? '');

  // Arrays from the multi-row table
  $qtyArr   = $_POST['qty']      ?? [];
  $descArr  = $_POST['desc']     ?? [];
  $catArr   = $_POST['category'] ?? [];
  $amtArr   = $_POST['amount']   ?? [];

  // bytes + mime from hidden inputs added in the Review form
  $blobB64 = $_POST['rcpt_blob_b64'] ?? '';
  $type    = $_POST['rcpt_type'] ?? null;
  $data    = $blobB64 !== '' ? base64_decode($blobB64) : null;

  // We attach the receipt BLOB only to the FIRST valid row (to avoid duplicating big blobs)
  $blobAttached = false;

  // Prepare statement once
  $st = $pdo->prepare("INSERT INTO expenses(user_id,date,category,amount,note,receipt_blob,receipt_type,created_at)
                       VALUES (?,?,?,?,?,?,?,NOW())");

  $rows = max(count($qtyArr), count($descArr), count($catArr), count($amtArr));
  for ($i = 0; $i < $rows; $i++) {
    $qty  = (int)($qtyArr[$i]  ?? 1);
    if ($qty < 1) $qty = 1;
    $desc = trim($descArr[$i] ?? '');
    $cat  = trim($catArr[$i]  ?? 'Other');
    $amtS = (string)($amtArr[$i] ?? '');
    $amt  = (float)$amtS;

    // Skip empty/invalid rows
    if (($desc === '' && $amt <= 0) || $cat === '') continue;

    // Note: keep merchant as a prefix in the note for searchability
    $note = $merchant ? ($merchant . ($desc ? " — " . $desc : "")) : $desc;

    // Attach blob only once
    $blob = null; $mime = null;
    if (!$blobAttached && $data) {
      $blob = $data; $mime = $type; $blobAttached = true;
    }

    $st->bindValue(1, $u['id']);
    $st->bindValue(2, $date);
    $st->bindValue(3, $cat);
    $st->bindValue(4, max(0, $amt));
    $st->bindValue(5, $note);
    $st->bindParam(6, $blob, PDO::PARAM_LOB);
    $st->bindValue(7, $mime);
    $st->execute();
  }

  $flash = 'Expense(s) saved with receipt.';
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}



/* --- Update existing expense (with BLOB) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id = (int)$_POST['id'];
  $categoryIn = trim($_POST['category']);
  $amount = (float)$_POST['amount'];
  $date = $_POST['date'];
  $note = trim($_POST['note'] ?? '');
  $removeReceipt = isset($_POST['remove_receipt']) ? 1 : 0;

  $cur = $pdo->prepare("SELECT id,user_id,receipt_blob,receipt_type FROM expenses WHERE id=?");
  $cur->execute([$id]);
  $curRow = $cur->fetch();
  if (!$curRow || (int)$curRow['user_id'] !== (int)$u['id']) {
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }

  $newData = $curRow['receipt_blob'];
  $newType = $curRow['receipt_type'];

  // Remove receipt if requested
  if ($removeReceipt) {
    $newData = null;
    $newType = null;
  }

  // Replace with uploaded file if provided
  if (!empty($_FILES['receipt']['name']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
    $tmp  = $_FILES['receipt']['tmp_name'];
    $type = @mime_content_type($tmp) ?: ($_FILES['receipt']['type'] ?? '');
    $ok   = ['image/jpeg','image/png','application/pdf','image/webp','image/gif'];
    if (in_array($type, $ok, true)) {
      $newData = file_get_contents($tmp);
      $newType = $type;
    }
  }

  $st = $pdo->prepare("UPDATE expenses 
                       SET category=?, amount=?, date=?, note=?, 
                           receipt_blob=?, receipt_type=? 
                       WHERE id=? AND user_id=?");
  $st->bindValue(1, $categoryIn);
  $st->bindValue(2, $amount);
  $st->bindValue(3, $date);
  $st->bindValue(4, $note);
  $st->bindParam(5, $newData, PDO::PARAM_LOB);
  $st->bindValue(6, $newType);
  $st->bindValue(7, $id);
  $st->bindValue(8, $u['id']);
  $st->execute();

  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

/* --- Delete --- */
if (isset($_GET['del'])) {
  $q=$pdo->prepare("SELECT receipt_blob FROM expenses WHERE id=? AND user_id=?");
  $q->execute([(int)$_GET['del'],$u['id']]);
  if ($row=$q->fetch() ) {
    
  }
  $st=$pdo->prepare("DELETE FROM expenses WHERE id=? AND user_id=?");
  $st->execute([(int)$_GET['del'],$u['id']]);
  header('Location: expenses.php'); exit;
}

/* --- Load filtered data --- */
$listStmt = $pdo->prepare("SELECT * FROM expenses WHERE $whereSql ORDER BY date DESC, id DESC");
$listStmt->execute($args);
$data = $listStmt->fetchAll();

/* For chart: aggregate per date (or per month for all-time) */
if ($range === 'all') {
  $chartStmt = $pdo->prepare("
    SELECT DATE_FORMAT(date,'%Y-%m') ym, IFNULL(SUM(amount),0) s
    FROM expenses
    WHERE $whereSql
    GROUP BY DATE_FORMAT(date,'%Y-%m')
    ORDER BY ym ASC
  ");
  $chartStmt->execute($args);
  $labels = []; $vals = [];
  foreach ($chartStmt as $r) { $labels[] = $r['ym']; $vals[] = round((float)$r['s'], 2); }
  $chartTitle = 'Expense by Month';
} else {
  $chartStmt = $pdo->prepare("
    SELECT date d, IFNULL(SUM(amount),0) s
    FROM expenses
    WHERE $whereSql
    GROUP BY date
    ORDER BY date ASC
  ");
  $chartStmt->execute($args);
  $labels = []; $vals = [];
  foreach ($chartStmt as $r) { $labels[] = $r['d']; $vals[] = round((float)$r['s'], 2); }
  $chartTitle = 'Expense Overview';
}

/* Total */
$totStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM expenses WHERE $whereSql");
$totStmt->execute($args);
$totalExpense = (float)$totStmt->fetchColumn();

layout_header('Expense'); ?>

<!-- Include Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0"><?= h($chartTitle) ?></h4>
  <div class="d-flex gap-2">
    <button class="btn btn-purple" id="voiceEntryBtn" data-bs-toggle="modal" data-bs-target="#voiceEntry">
      <i class="bi bi-mic-fill me-1"></i> Voice Entry
    </button>
    <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#uploadReceipt"><i class="bi bi-upload me-1"></i> Upload Receipt</button>
    <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#addExpense"><i class="bi bi-plus-lg me-1"></i> Add Expense</button>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-info py-2"><?=$flash?></div><?php endif; ?>

<!-- Filter Bar -->
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
      <label class="form-label fw-semibold">Category</label>
      <select name="category" class="form-select">
        <option value="">All</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= h($c) ?>" <?= $category===$c?'selected':'' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label fw-semibold">Amount (min)</label>
      <input type="number" step="0.01" name="amin" value="<?= h($amin) ?>" class="form-control" placeholder="e.g., 10">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold">Amount (max)</label>
      <input type="number" step="0.01" name="amax" value="<?= h($amax) ?>" class="form-control" placeholder="e.g., 500">
    </div>

    <div class="col-md-3">
      <label class="form-label fw-semibold">Search</label>
      <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Category or notes">
    </div>

    <div class="col-md-3">
      <button class="btn btn-purple w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
    <div class="col-md-2">
      <a class="btn btn-outline-secondary w-100" href="expenses.php"><i class="bi bi-x-circle me-1"></i>Reset</a>
    </div>
  </form>

  <canvas id="expLine" width="400" height="400" class="mt-3"></canvas>
</div>

<div class="soft-card">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-bold">All Expenses</div>
    <div class="text-muted small">
      <?php if ($from || $to): ?>
        Range: <b><?= h($from ?: '…') ?></b> → <b><?= h($to ?: '…') ?></b>
      <?php else: ?>
        Range: <b>All time</b>
      <?php endif; ?>
      • Total: <span class="chip-neg">-RM<?= number_format($totalExpense, 2) ?></span>
      • Showing <?= count($data) ?> item(s)
    </div>
  </div>

  <div class="listy">
    <?php if (!$data): ?>
      <div class="text-muted">No expenses yet.</div>
    <?php endif; ?>
    <?php foreach ($data as $r): ?>
      <div class="rowi">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle" style="width:38px;height:38px;background:#f6f3ff;display:grid;place-items:center;">
            <i class="bi bi-bag"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= h($r['category']) ?></div>
            <div class="text-muted small"><?= h($r['date']) ?></div>
            <?php if (!empty($r['note'])): ?>
              <div class="small text-secondary"><?= h($r['note']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <?php if (!empty($r['receipt_blob'])): ?>
            <a class="btn btn-sm btn-outline-primary" href="view_receipt.php?id=<?= (int)$r['id'] ?>" target="_blank">
            🧾 View
            </a>
        <?php endif; ?>
          <div class="chip-neg"><?= $r['amount'] > 0 ? '-RM' . number_format((float)$r['amount'], 2) : 'RM0.00' ?></div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary edit-btn"
            data-bs-toggle="modal"
            data-bs-target="#editExpense"
            data-id="<?= $r['id'] ?>"
            data-date="<?= h($r['date']) ?>"
            data-category="<?= h($r['category']) ?>"
            data-amount="<?= h($r['amount']) ?>"
            data-note="<?= h($r['note'] ?? '') ?>"
          >
            <i class="bi bi-pencil-square"></i>
          </button>
          <a class="btn btn-sm btn-outline-danger" href="?del=<?= $r['id'] ?>" onclick="return confirm('Delete expense?')">
            <i class="bi bi-trash"></i>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Add Expense (manual) -->
<div class="modal fade" id="addExpense" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="add">
      <div class="modal-header"><h5 class="modal-title">Add Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Category</label>
          <select class="form-select" name="category" required>
            <?php foreach($cats as $c) echo "<option>".h($c)."</option>"; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Amount</label><input type="number" step="0.01" min="0" class="form-control" name="amount" required></div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" class="form-control" name="date" required></div>
        <div class="mb-3"><label class="form-label">Note</label><input class="form-control" name="note" placeholder="Optional"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-purple">Add Expense</button></div>
    </form>
  </div>
</div>

<!-- Upload Receipt -->
<div class="modal fade" id="uploadReceipt" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_receipt">
      <div class="modal-header"><h5 class="modal-title">Upload Receipt</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <style>
          .dropzone{border:2px dashed #e5e7eb;border-radius:16px;padding:28px;text-align:center;background:#fff;box-shadow:0 10px 30px rgba(17,24,39,.06)}
          .dropzone.dragover{background:#faf5ff;border-color:var(--accent-2)}
          .dz-icon{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;background:#fff;margin:0 auto 10px;box-shadow:0 8px 24px rgba(17,24,39,.08)}
        </style>
        <div id="dz" class="dropzone">
          <div class="dz-icon"><i class="bi bi-upload" style="font-size:28px;color:var(--accent)"></i></div>
          <h5 class="mb-1">Drag &amp; Drop Receipts</h5>
          <div class="text-muted mb-3">or click here to upload (JPG, PNG, PDF)</div>
          <input id="fileInput" type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" hidden>
          <button type="button" class="btn btn-outline-secondary" id="chooseBtn"><i class="bi bi-folder2-open me-1"></i>Choose File</button>
          <div id="fileName" class="small text-secondary mt-2"></div>
        </div>
        <div class="d-flex mt-3 justify-content-end">
          <button type="submit" class="btn btn-purple" id="uploadBtn" disabled>Upload</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Voice Entry Modal (single-shot dictation that fills fields) -->
<div class="modal fade" id="voiceEntry" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="add">
      <div class="modal-header">
        <h5 class="modal-title">Voice Entry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small text-muted">Say: “Food and Dining 12 ringgit 50 today lunch with team”.</div>
        <div class="d-grid mb-3">
          <button type="button" class="btn btn-outline-secondary" id="ve-start">
            <i class="bi bi-mic-fill me-1"></i><span id="ve-label">Start Listening</span>
          </button>
        </div>
        <div class="mb-3">
          <label class="form-label">Heard</label>
          <textarea class="form-control" id="ve-heard" rows="2" readonly placeholder="…"></textarea>
        </div>
        <div class="mb-3"><label class="form-label">Category</label>
          <select class="form-select" name="category" id="ve-category" required>
            <?php foreach($cats as $c) echo "<option>".h($c)."</option>"; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Amount</label>
          <input type="number" step="0.01" min="0" class="form-control" name="amount" id="ve-amount" required>
        </div>
        <div class="mb-3"><label class="form-label">Date</label>
          <input type="date" class="form-control" name="date" id="ve-date" required value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="mb-3"><label class="form-label">Note</label>
          <input class="form-control" name="note" id="ve-note" placeholder="Optional">
        </div>
        <div class="small text-muted" id="ve-hint"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-purple">Save Expense</button>
      </div>
    </form>
  </div>
</div>

<!-- Review (Image left + Form right) -->
<div class="modal fade" id="reviewReceipt" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="save_receipt">
      <input type="hidden" name="rcpt_blob_b64" value="<?= $review ? h($review['blob']) : '' ?>">
      <input type="hidden" name="rcpt_type" value="<?= $review ? h($review['type']) : '' ?>">


      <div class="modal-header">
        <h5 class="modal-title">Edit Expense (1 of 1)</h5>
        
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <!-- LEFT: Receipt viewer -->
          <div class="col-lg-6 left-col">
            <div class="soft-card p-0" style="height:70vh;display:flex;flex-direction:column;overflow:hidden;">
              <div class="d-flex gap-2 p-2 border-bottom bg-light align-items-center">
                <div class="small text-muted flex-grow-1">Receipt Preview</div>
                <div class="btn-group btn-group-sm" role="group" aria-label="viewer tools">
                  <button type="button" class="btn btn-outline-secondary" id="zoomOutBtn" title="Zoom out">-</button>
                  <button type="button" class="btn btn-outline-secondary" id="zoomInBtn" title="Zoom in">+</button>
                  <button type="button" class="btn btn-outline-secondary" id="rotateBtn" title="Rotate">⟲</button>
                  <button type="button" class="btn btn-outline-secondary" id="resetBtn" title="Reset">Reset</button>
                </div>
              </div>
              <div id="viewerWrap" style="flex:1;overflow:auto;background:#fafafa;display:grid;place-items:center;">
                <?php if ($review && in_array($review['ext'],['jpg','jpeg','png','webp','gif'])): ?>
                  <img id="rcptImg" src="<?= h($review['b64']) ?>" alt="receipt" style="max-width:100%;max-height:100%;transform-origin:center center;">
                <?php elseif ($review && $review['ext']==='pdf'): ?>
                  <div class="text-center p-4">
                    <i class="bi bi-file-earmark-pdf" style="font-size:48px;color:var(--accent)"></i>
                    <div class="mt-2">Save this expense first, then open the PDF via the 🧾 View button in the list.</div>
                  </div>
                <?php else: ?>
                  <div class="text-muted p-4">No receipt preview.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- RIGHT: Form -->
          <div class="col-lg-6 right-col">
            <div class="soft-card">
              <div class="row g-3">

                <div class="col-md-6">
                  <label class="form-label">Expense Date *</label>
                  <input type="date" class="form-control" name="date" id="dateInput"
                         value="<?= ($review && !empty($review['ocr']['date']))
                                    ? h($review['ocr']['date'])
                                    : ($review ? h($review['ts']) : h(date('Y-m-d'))) ?>"
                         required>
                </div>
                

                <div class="col-md-6">
                  <label class="form-label">Merchant</label>
                  <input class="form-control" name="merchant" id="merchantInput"
                        value="<?= $review ? h($review['ocr']['merchant'] ?? '') : '' ?>"
                        placeholder="e.g., Starbucks">

                </div>

                <div class="col-12">
                <table class="table-blend align-middle">
                  <thead>
                    <tr>
                      <th class="eg-qty">Quantity</th>
                      <th class="eg-desc">Description / Note</th>
                      <th class="eg-cat">Category</th>
                      <th class="eg-amt">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    
                    <?php
                    $rows = isset($review['ocr']['items']) ? $review['ocr']['items'] : [];
                    if (!$rows) {
                      $rows = [['qty'=>1, 'desc'=>'', 'category'=>'Other', 'amount'=>'']];
                    }
                    foreach ($rows as $i => $r):
                    ?>
                      <tr>
                        <td>
                          <input type="number" class="form-control" min="1" step="1"
                                name="qty[]" value="<?= h((string)($r['qty'] ?? 1)) ?>">
                        </td>
                        <td>
                          <input class="form-control"
                                name="desc[]" placeholder="Item name / note"
                                value="<?= h($r['desc'] ?? '') ?>">
                        </td>
                        <td>
                          <select class="form-select" name="category[]">
                            <?php foreach ($cats as $c): ?>
                              <option value="<?= h($c) ?>" <?= ($r['category'] ?? '')===$c?'selected':'' ?>>
                                <?= h($c) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input type="number" step="0.01" min="0"
                                  class="form-control"
                                  name="amount[]" value="<?= h($r['amount'] ?? '') ?>"
                                  placeholder="0.00">
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    

                  </tbody>
                </table>

                <!-- Optional: show OCR raw text if you want -->
                <div class="small text-muted mt-2" id="ocrRaw" style="display:none"></div>
              </div>

               

                
              </div>
            </div>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-purple">Save and Close</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpense" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal-header">
        <h5 class="modal-title">Edit Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Category</label>
          <select class="form-select" name="category" id="edit-category" required>
            <?php foreach($cats as $c) echo "<option>".h($c)."</option>"; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount</label>
          <div class="input-group">
            <span class="input-group-text">RM</span>
            <input type="number" step="0.01" min="0" class="form-control" name="amount" id="edit-amount" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" class="form-control" name="date" id="edit-date" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Note</label>
          <input class="form-control" name="note" id="edit-note" placeholder="Optional">
        </div>

        <hr>
        <div class="mb-2 fw-semibold">Receipt</div>
        <div class="mb-2">
          <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="remove-receipt" name="remove_receipt">
          <label class="form-check-label" for="remove-receipt">Remove existing receipt</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-purple">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
/* Toggle custom range inputs */
const rangeSel = document.getElementById('rangeSel');
function syncCustom(){
  const on = rangeSel.value === 'custom';
  document.querySelectorAll('.custom-field').forEach(el => el.style.display = on ? '' : 'none');
}
rangeSel?.addEventListener('change', syncCustom); syncCustom();

/* Chart (Doughnut for Expense Overview) */
const c = document.getElementById('expLine');
new Chart(c, {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: 'Expense',
      data: <?= json_encode($vals) ?>,
      backgroundColor: [
        '#10b981', // Green
        '#f59e0b', // Yellow
        '#ef4444', // Red
        '#6366f1', // Blue
        '#e5e7eb'  // Gray
      ],
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false, // Ensure the chart fills the canvas
    plugins: {
      legend: {
        position: 'right',
        labels: {
          boxWidth: 20,
          font: {
            size: 14
          }
        }
      }
    }
  }
});

/* Dropzone */
const dz=document.getElementById('dz'), fileInput=document.getElementById('fileInput'), chooseBtn=document.getElementById('chooseBtn'), uploadBtn=document.getElementById('uploadBtn'), fileName=document.getElementById('fileName');
['dragenter','dragover'].forEach(ev=>dz?.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();dz.classList.add('dragover');}));
['dragleave','drop'].forEach(ev=>dz?.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();if(ev!=='drop')dz.classList.remove('dragover');}));
dz?.addEventListener('drop',e=>{dz.classList.remove('dragover'); if(e.dataTransfer.files?.[0]){fileInput.files=e.dataTransfer.files; fileName.textContent=fileInput.files[0].name; uploadBtn.disabled=false;}});
dz?.addEventListener('click',()=>chooseBtn.click());
chooseBtn?.addEventListener('click',()=>fileInput.click());
fileInput?.addEventListener('change',()=>{ if(fileInput.files?.[0]){fileName.textContent=fileInput.files[0].name; uploadBtn.disabled=false;}});

/* ===== Image viewer controls ===== */
(function(){
  const img = document.getElementById('rcptImg');
  if (!img) return;
  let scale = 1, rot = 0;
  const apply = () => { img.style.transform = `scale(${scale}) rotate(${rot}deg)`; };
  document.getElementById('zoomInBtn')?.addEventListener('click', ()=>{ scale = Math.min(5, scale + 0.2); apply(); });
  document.getElementById('zoomOutBtn')?.addEventListener('click', ()=>{ scale = Math.max(0.2, scale - 0.2); apply(); });
  document.getElementById('rotateBtn')?.addEventListener('click', ()=>{ rot = (rot + 90) % 360; apply(); });
  document.getElementById('resetBtn')?.addEventListener('click', ()=>{ scale = 1; rot = 0; apply(); });
})();



/* If a receipt was just uploaded, open the Review modal now */
document.addEventListener("DOMContentLoaded", () => {
  <?php if ($review): ?>
    const modalEl = document.getElementById('reviewReceipt');
    if (modalEl) {
      new bootstrap.Modal(modalEl).show();
    }
  <?php endif; ?>
});

// Fill Edit modal when clicking the pencil button
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('edit-id').value = btn.dataset.id;
    document.getElementById('edit-date').value = btn.dataset.date || '';
    document.getElementById('edit-amount').value = btn.dataset.amount || '';
    document.getElementById('edit-note').value = btn.dataset.note || '';
    const sel = document.getElementById('edit-category');
    if (sel) sel.value = btn.dataset.category || 'Uncategorized';
    const rm = document.getElementById('remove-receipt'); if (rm) rm.checked = false;
  });
});


</script>

<style>
#expLine {
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

.chip-neg {
  color: #ef4444;
  font-weight: 700;
}

.soft-card {
  background: #ffffff;
  border: 0;
  border-radius: 16px;
  padding: 16px;
  box-shadow: 0 12px 40px rgba(17, 24, 39, .06);
}

/* --- Review modal: blended soft table --- */
.table-blend {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  background: #f9fafb;        /* off-white to blend with page */
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 10px 28px rgba(17,24,39,.05);
}

.table-blend thead th {
  background: #f3f4f6;
  color: #374151;
  font-weight: 600;
  text-align: left;
  padding: 10px 12px;
}

.table-blend tbody td {
  padding: 10px 12px;
  vertical-align: middle;
  border-top: 1px solid #e5e7eb;  /* subtle divider */
}

.table-blend tbody tr:hover {
  background: #ffffff;
}

.table-blend .eg-qty  { width: 10%; }
.table-blend .eg-desc { width: 45%; }
.table-blend .eg-cat  { width: 25%; }
.table-blend .eg-amt  { width: 20%; }

/* Review modal sizing and better balance */
#reviewReceipt .modal-dialog { 
  --bs-modal-width: 1280px;    /* wider modal */
  max-width: 95vw;             /* prevent overflow */
}

/* Shift layout ratio: less width for left (preview), more for right (form) */
@media (min-width: 1200px){
  #reviewReceipt .left-col  { flex: 0 0 44%; max-width: 44%; }
  #reviewReceipt .right-col { flex: 0 0 56%; max-width: 56%; }
}
@media (min-width: 1400px){
  #reviewReceipt .left-col  { flex: 0 0 40%; max-width: 40%; }
  #reviewReceipt .right-col { flex: 0 0 60%; max-width: 60%; }
}

/* Shrink receipt viewer a bit and tighten table padding */
#reviewReceipt .receipt-card { height: 60vh; }
#reviewReceipt #rcptImg { 
  max-width: 100%; 
  max-height: 100%; 
  object-fit: contain;
}
#reviewReceipt .table-blend thead th,
#reviewReceipt .table-blend tbody td {
  padding: 8px 10px;
}



.btn-purple {
  background: #6366f1;
  border-color: #6366f1;
}

.btn-purple:hover {
  background: #4f46e5;
  border-color: #4f46e5;
}

/* Tiny pulsing dot to indicate listening */
.sr-listening::after {
  content: '';
  display: inline-block;
  width: 8px; height: 8px;
  margin-left: 6px;
  border-radius: 50%;
  background: #dc2626;
  box-shadow: 0 0 0 0 rgba(220,38,38,.7);
  animation: pulse 1.2s infinite;
}
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(220,38,38,.7); }
  70% { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
  100% { box-shadow: 0 0 0 0 rgba(220,38,38,0); }
}
</style>

<!-- Include Bootstrap JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Lightweight Web Speech API glue for Add Expense modal -->
<script>
(() => {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const supported = !!SpeechRecognition;

  const catBtn  = document.getElementById('voice-cat-btn');
  const amtBtn  = document.getElementById('voice-amt-btn');
  const noteBtn = document.getElementById('voice-note-btn');
  const catSel  = document.getElementById('add-category');
  const amtInp  = document.getElementById('add-amount');
  const noteInp = document.getElementById('add-note');

  const hintCat  = document.getElementById('voice-cat-hint');
  const hintAmt  = document.getElementById('voice-amt-hint');
  const hintNote = document.getElementById('voice-note-hint');

  function setHint(el, msg) { if (!el) return; el.textContent = msg || ''; el.style.display = msg ? 'block' : 'none'; }

  if (!supported) {
    // Graceful fallback: hide the buttons if API unsupported
    [catBtn, amtBtn, noteBtn].forEach(b => b && (b.style.display = 'none'));
    [hintCat, hintAmt, hintNote].forEach(h => h && setHint(h, 'Speech not supported in this browser'));
    return;
  }

  function recognizeOnce({ lang = 'en-US', interim = false } = {}) {
    return new Promise((resolve, reject) => {
      try {
        const rec = new SpeechRecognition();
        rec.lang = lang;
        rec.interimResults = interim;
        rec.maxAlternatives = 3;
        let finalText = '';
        rec.onresult = (evt) => {
          for (const res of evt.results) {
            if (res.isFinal) finalText = res[0].transcript.trim();
          }
        };
        rec.onerror = (e) => reject(e.error || 'speech-error');
        rec.onend = () => resolve(finalText);
        rec.start();
      } catch (e) {
        reject(e);
      }
    });
  }

  function toggleListening(btn, on) {
    if (!btn) return;
    btn.classList.toggle('sr-listening', !!on);
    btn.disabled = !!on;
  }

  // Map a spoken category to the closest option
  function pickCategoryFromSpeech(text) {
    if (!text) return null;
    const heard = text.toLowerCase();
    const opts = Array.from(catSel.options).map(o => o.text);
    // simple exact/contains/startsWith matching
    for (const o of opts) if (o.toLowerCase() === heard) return o;
    for (const o of opts) if (heard.includes(o.toLowerCase())) return o;
    // handle common variations
    const variants = [
      ['food & dining','food and dining','food dining','food'] ,
      ['entertainment & leisure','entertainment and leisure','entertainment','leisure'],
      ['bills & utilities','bills and utilities','utilities','bills'],
      ['health & fitness','health and fitness','health','fitness'],
      ['travel & vacation','travel and vacation','travel','vacation']
    ];
    for (const group of variants) {
      if (group.some(v => heard.includes(v))) return group[0].replace(/\b\w/g, m => m.toUpperCase());
    }
    return null;
  }

  async function handleCat() {
    if (!catBtn) return;
    try {
      setHint(hintCat, 'Listening… say a category');
      toggleListening(catBtn, true);
      const text = await recognizeOnce();
      const match = pickCategoryFromSpeech(text) || 'Other';
      catSel.value = match;
      setHint(hintCat, text ? `Heard: "${text}" → ${match}` : 'No speech captured');
    } catch(e) {
      setHint(hintCat, `Speech error: ${e}`);
    } finally {
      toggleListening(catBtn, false);
    }
  }

  function parseAmountFromSpeech(text) {
    if (!text) return '';
    // Keep digits and dot/comma, strip words/currency
    let s = text.replace(/[^0-9.,]/g, '').trim();
    if (!s) return '';
    // If comma used as decimal and no dot, switch to dot
    if (s.indexOf(',') >= 0 && s.indexOf('.') < 0) s = s.replace(',', '.');
    // Remove thousands commas
    s = s.replace(/,(?=\d{3}(\D|$))/g, '');
    const n = parseFloat(s);
    return isFinite(n) ? n.toFixed(2) : '';
  }

  async function handleAmt() {
    if (!amtBtn) return;
    try {
      setHint(hintAmt, 'Listening… say an amount');
      toggleListening(amtBtn, true);
      const text = await recognizeOnce();
      const parsed = parseAmountFromSpeech(text);
      if (parsed) {
        amtInp.value = parsed;
        setHint(hintAmt, `Heard: "${text}" → RM ${parsed}`);
      } else {
        setHint(hintAmt, text ? `Couldn't parse from: "${text}"` : 'No speech captured');
      }
    } catch(e) {
      setHint(hintAmt, `Speech error: ${e}`);
    } finally {
      toggleListening(amtBtn, false);
    }
  }

  async function handleNote() {
    if (!noteBtn) return;
    try {
      setHint(hintNote, 'Listening… speak your note');
      toggleListening(noteBtn, true);
      const text = await recognizeOnce();
      if (text) {
        // Append rather than replace if field has content
        noteInp.value = noteInp.value ? (noteInp.value + ' ' + text) : text;
        setHint(hintNote, `Heard: "${text}"`);
      } else {
        setHint(hintNote, 'No speech captured');
      }
    } catch(e) {
      setHint(hintNote, `Speech error: ${e}`);
    } finally {
      toggleListening(noteBtn, false);
    }
  }

  catBtn && catBtn.addEventListener('click', handleCat);
  amtBtn && amtBtn.addEventListener('click', handleAmt);
  noteBtn && noteBtn.addEventListener('click', handleNote);
})();
</script>

<!-- Voice Entry JS (single button flow) -->
<script>
(() => {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const supported = !!SR;
  const veBtn   = document.getElementById('voiceEntryBtn');
  const startBt = document.getElementById('ve-start');
  const label   = document.getElementById('ve-label');
  const heardEl = document.getElementById('ve-heard');
  const catSel  = document.getElementById('ve-category');
  const amtEl   = document.getElementById('ve-amount');
  const dateEl  = document.getElementById('ve-date');
  const noteEl  = document.getElementById('ve-note');
  const hintEl  = document.getElementById('ve-hint');
  let running = false;

  function setHint(msg){ if (!hintEl) return; hintEl.textContent = msg || ''; }
  function todayYmd(){ var d=new Date(); return d.toISOString().slice(0,10); }
  function ymd(d){ function z(n){return String(n).padStart(2,'0');} return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate()); }

  if (!supported) {
    if (veBtn) veBtn.disabled = true;
    setHint('Speech recognition not supported in this browser.');
    return;
  }

  function recognizeOnce(lang){
    if (!lang) lang = 'en-US';
    return new Promise(function(resolve,reject){
      try{
        var r = new SR();
        r.lang = lang; r.interimResults = false; r.maxAlternatives = 3;
        var out = '';
        r.onresult = function(e){ for (var i=0;i<e.results.length;i++){ var res=e.results[i]; if (res.isFinal) out = res[0].transcript.trim(); } };
        r.onerror  = function(e){ reject(e.error||'speech-error'); };
        r.onend    = function(){ resolve(out); };
        r.start();
      }catch(err){ reject(err); }
    });
  }

  function parseCategory(text){
    if (!text) return null;
    var heard = text.toLowerCase();
    var opts = Array.from(catSel.options).map(function(o){return o.text;});
    for (var i=0;i<opts.length;i++){ var o=opts[i]; if (o.toLowerCase()===heard) return o; }
    for (var j=0;j<opts.length;j++){ var p=opts[j]; if (heard.indexOf(p.toLowerCase())>=0) return p; }
    var pairs = [
      ['Food & Dining', ['food and dining','food dining','food','f & b','fnb','groceries']],
      ['Bills & Utilities', ['bills and utilities','utilities','bills','electric','water','internet']],
      ['Health & Fitness', ['health and fitness','health','fitness','medical']],
      ['Entertainment & Leisure', ['entertainment and leisure','entertainment','leisure','movie','games']],
      ['Travel & Vacation', ['travel and vacation','travel','vacation','trip']]
    ];
    for (var k=0;k<pairs.length;k++){
      var cat=pairs[k][0], alts=pairs[k][1];
      for (var m=0;m<alts.length;m++){ if (heard.indexOf(alts[m])>=0) return cat; }
    }
    return null;
  }

  function parseAmount(text){
    if (!text) return '';
    var match = (text.match(/\d+[\d,]*(?:[\.,]\d+)?/g)||[]);
    var s = match.length ? match[match.length-1] : '';
    if (!s) return '';
    s = s.replace(/[^0-9.,]/g,'');
    if (s.indexOf(',')>=0 && s.indexOf('.')<0) s = s.replace(',', '.');
    s = s.replace(/,(?=\d{3}(\D|$))/g,'');
    var n = parseFloat(s);
    return isFinite(n) ? n.toFixed(2) : '';
  }

  function parseDate(text){
    if (!text) return todayYmd();
    var t = text.toLowerCase();
    if (t.indexOf('today')>=0) return todayYmd();
    if (t.indexOf('yesterday')>=0){ var d=new Date(); d.setDate(d.getDate()-1); return ymd(d); }
    var iso = t.match(/(\d{4}-\d{2}-\d{2})/); if (iso) return iso[1];
    var dmy = t.match(/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/);
    if (dmy){ var dd=parseInt(dmy[1]); var mm=parseInt(dmy[2])-1; var yy=parseInt(dmy[3]); if (dmy[3].length===2) yy+=2000; var dt=new Date(yy,mm,dd); if(!isNaN(dt)) return ymd(dt); }
    var months=['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    var m1 = t.match(new RegExp('(\\\d{1,2})\\s+(' + months.join('|') + ')[a-z]*\\s+(\\\d{4})'));
    if (m1){ var d1=parseInt(m1[1]); var y1=parseInt(m1[3]); var idx=-1; for (var a=0;a<months.length;a++){ if (m1[0].toLowerCase().indexOf(months[a])>=0){ idx=a; break; } } var dt1=new Date(y1,idx,d1); if(!isNaN(dt1)) return ymd(dt1); }
    var m2 = t.match(new RegExp('(' + months.join('|') + ')[a-z]*\\s+(\\\d{1,2}),?\\s+(\\\d{4})'));
    if (m2){ var d2=parseInt(m2[2]); var y2=parseInt(m2[3]); var idx2=-1; for (var b=0;b<months.length;b++){ if (m2[0].toLowerCase().indexOf(months[b])>=0){ idx2=b; break; } } var dt2=new Date(y2,idx2,d2); if(!isNaN(dt2)) return ymd(dt2); }
    return todayYmd();
  }

  function stripTokens(text, tokens){
    var out = text;
    for (var i=0;i<tokens.length;i++){ var t=tokens[i]; if (!t) continue; var re = new RegExp(t.replace(/[-/\\^$*+?.()|[\]{}]/g,'\\$&'), 'ig'); out = out.replace(re,' '); }
    return out.replace(/\s+/g,' ').trim();
  }

  function parseAll(text){
    var amount = parseAmount(text);
    var date   = parseDate(text);
    var cat    = parseCategory(text) || 'Other';
    var note   = stripTokens(text, [amount, date, cat.toLowerCase(), 'today','yesterday','ringgit','rm','dollar','dollars']);
    return { amount: amount, date: date, category: cat, note: note };
  }

  function startOnce(){
    if (running) return; // prevent double-start which can cause 'aborted'
    (async function(){
      try {
        setHint('Listening…');
        if (startBt) { startBt.classList.add('sr-listening'); startBt.disabled = true; }
        if (label) label.textContent = 'Listening…';
        running = true;
        var text = '';
        try {
          text = await recognizeOnce();
        } catch (e1) {
          if ((e1 && String(e1).toLowerCase().includes('aborted'))) {
            setHint('Mic interrupted, retrying…');
            text = await recognizeOnce();
          } else {
            throw e1;
          }
        }
        if (heardEl) heardEl.value = text || '';
        var parsed = parseAll(text||'');
        if (catSel) catSel.value = parsed.category;
        if (amtEl)  amtEl.value  = parsed.amount;
        if (dateEl) dateEl.value = parsed.date;
        if (noteEl) noteEl.value = parsed.note;
        setHint(text ? 'Tap again to re-dictate, or Save.' : 'No speech captured');
      } catch(e){
        setHint('Speech error: ' + e);
      } finally {
        running = false;
        if (startBt) { startBt.classList.remove('sr-listening'); startBt.disabled = false; }
        if (label) label.textContent = 'Start Listening';
      }
    })();
  }

  if (startBt) startBt.addEventListener('click', startOnce);
})();
</script>

<?php layout_footer(); ?>
