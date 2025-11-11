<?php
// inc/auth.php
declare(strict_types=1);
session_start();
require_once __DIR__.'/db.php';

function current_user(): ?array {
  if (!isset($_SESSION['uid'])) return null;
  static $cache = null;
  if ($cache) return $cache;
  $st = $GLOBALS['pdo']->prepare("SELECT id,name,email FROM users WHERE id=?");
  $st->execute([$_SESSION['uid']]);
  return $cache = $st->fetch() ?: null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: /expense-simple/public/login.php');
    exit;
  }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function today(): string { return (new DateTime())->format('Y-m-d'); }
