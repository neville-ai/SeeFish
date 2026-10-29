<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/csrf.php';

$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/auth.css">
<link rel="stylesheet" href="assets/css/logo.css">
</head>
<body class="auth-dark">

<canvas id="bgCanvas"></canvas>

<div class="auth-dark-box">

  <a href="index.php" class="auth-back">&#x2190; Kembali ke Beranda</a>

  <div class="auth-logo-row">
    <span class="seefish-logo-mark seefish-logo-mark--auth">
      <img src="assets/SeeFish%20Logo.png" alt="SeeFish Logo" class="seefish-logo-img">
    </span>
    <span class="auth-logo-text">SeeFish</span>
  </div>

  <div class="auth-dark-title">Selamat Datang</div>
  <div class="auth-dark-sub">Masuk ke akun Anda untuk melanjutkan</div>

  <?php if ($error === 'timeout'): ?>
    <div class="auth-alert auth-alert-error">&#x23F1; Session habis, silakan login kembali</div>
  <?php elseif ($error === 'csrf'): ?>
    <div class="auth-alert auth-alert-error">&#x2715; Sesi keamanan tidak valid. Silakan coba lagi.</div>
  <?php elseif ($error !== ''): ?>
    <div class="auth-alert auth-alert-error">&#x2715; Username atau password salah</div>
  <?php endif; ?>

  <?php if (isset($_GET['success'])): ?>
    <div class="auth-alert auth-alert-success">&#x2713; Registrasi berhasil! Silakan login</div>
  <?php endif; ?>

  <form action="process/login_process" method="POST" class="auth-dark-form">

    <div>
      <label class="auth-field-label" for="login_user">Username</label>
      <div class="auth-input-wrap">
        <i class="fa-solid fa-user auth-input-icon"></i>
        <input type="text" id="login_user" name="user" placeholder="Masukkan username" autocomplete="username" maxlength="50" required>
      </div>
    </div>

    <div>
      <label class="auth-field-label" for="password">Password</label>
      <div class="auth-input-wrap">
        <i class="fa-solid fa-lock auth-input-icon"></i>
        <input type="password" id="password" name="pass" placeholder="Masukkan password" autocomplete="current-password" maxlength="128" required>
        <i class="fa-solid fa-eye auth-eye" aria-hidden="true"></i>
      </div>
    </div>

    <?php csrf_input(); ?>
    <button type="submit" class="auth-submit">Masuk &#x2192;</button>

  </form>

  <div class="auth-divider"></div>

  <div class="auth-dark-footer">
    Belum punya akun? <a href="register">Daftar Gratis</a>
  </div>

</div>

<!-- login.js harus dimuat sebelum auth-canvas.js agar AUTH_ORBS tersedia -->
<script src="assets/js/login.js" defer></script>
<script src="assets/js/auth-canvas.js" defer></script>

</body>
</html>
