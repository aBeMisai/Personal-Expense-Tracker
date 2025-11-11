<?php
require_once __DIR__.'/../inc/layout.php';
require_login();
$u = current_user();

$q = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$category  = $_GET['category'] ?? '';
$amount_like = $_GET['amount_like'] ?? '';

$w = ["user_id=?"]; $p = [$u['id']];
if ($date_from !== '') { $w[]="date>=?"; $p[]=$date_from; }
if ($date_to   !== '') { $w[]="date<=?"; $p[]=$date_to; }
if ($category  !== '') { $w[]="category=?"; $p[]=$category; }
if ($q !== '')         { $w[]="(note LIKE ? OR category LIKE ? OR type LIKE ?)"; $p[]="%$q%"; $p[]="%$q%"; $p[]="%$q%"; }

$sqlInc = "SELECT date, type AS label, amount, 'Income' AS type FROM income WHERE ...";
$sqlExp = "SELECT date, category AS label, amount, 'Expense' AS type FROM expenses WHERE ".implode(' AND ',$w);

$rows = $pdo->query("$sqlInc UNION ALL $sqlExp ORDER BY date DESC")->fetchAll();
if ($amount_like !== '') {
  $rows = array_values(array_filter($rows, fn($r)=>stripos((string)$r['amount'], $amount_like)!==false));
}

$cats = ['','Food','Transport','Bills','Shopping','Entertainment','Health','Education','Other'];

layout_header('Search'); ?>
<h3 class="mb-3">Search</h3>

<div class="card p-3 mb-3">
  <form class="row g-3">
    <div class="col-6 col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="date_from" value="<?=h($date_from)?>"></div>
    <div class="col-6 col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="date_to" value="<?=h($date_to)?>"></div>
    <div class="col-6 col-md-3">
      <label class="form-label">Category (expenses)</label>
      <select class="form-select" name="category">
        <?php foreach ($cats as $c): ?>
          <option <?= $category===$c?'selected':'' ?>><?=h($c)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3"><label class="form-label">Keyword</label><input class="form-control" name="q" value="<?=h($q)?>" placeholder="note/category/source"></div>
    <div class="col-6 col-md-3"><label class="form-label">Amount contains</label><input class="form-control" name="amount_like" value="<?=h($amount_like)?>" placeholder="e.g., 12.5"></div>
    <div class="col-12 col-md-3 d-grid"><label class="form-label">&nbsp;</label><button class="btn btn-secondary">Apply</button></div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Date</th><th>Type</th><th>Label</th><th class="text-end">Amount (RM)</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-secondary">No matches.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?=h($r['date'])?></td>
            <td><?=h($r['type'])?></td>
            <td><?=h($r['label'])?></td>
            <td class="text-end"><?=number_format((float)$r['amount'],2)?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer(); ?>
