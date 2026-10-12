<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';
$db = db();
$db->exec("CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if (auth_user()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $db->prepare('SELECT id, email, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]); $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'], 'email'=>$user['email']];
        redirect('index.php');
    }
    $error = 'Email or password is incorrect.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#26715d"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><title>Sign in · Staybook</title><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/icon-192.png"><link rel="stylesheet" href="style.css"><script defer src="pwa.js"></script></head><body><header class="topbar"><a class="brand" href="login.php"><span class="brand-mark">S</span><span>staybook<small>HOTEL DIRECTORY</small></span></a></header><main class="setup-wrap login-wrap"><div class="eyebrow">WELCOME BACK</div><h1>Sign in to Staybook.</h1><p class="lede">Use your account email and password to continue.</p><?php if($error): ?><div class="notice error"><?=h($error)?></div><?php endif; ?><form method="post" class="panel setup-form"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Email address<input type="email" name="email" autocomplete="username" required value="<?=h($_POST['email']??'')?>"></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button button-dark" type="submit">Sign in <span>→</span></button></form><p class="help">Private internal access only.</p></main></body></html>
