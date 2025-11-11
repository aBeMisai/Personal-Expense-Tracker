<?php
require_once __DIR__.'/../inc/auth.php';
if (current_user()) {
  header('Location: /expense-simple/public/dashboard.php');
} else {
  header('Location: /expense-simple/public/login.php');
}
