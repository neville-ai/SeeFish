<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/csrf.php';

if (!empty($_SESSION['login']) && !empty($_SESSION['user_id'])) {
    redirect('dashboard');
}

$minimumPassword = password_min_length();
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';

$errorMessage = match ($error) {
    'csrf' => 'Sesi keamanan tidak valid. Silakan coba lagi.',
    'invalid' => 'Format username atau password tidak valid.',
    'mismatch' => 'Konfirmasi password tidak cocok dengan password.',
    'taken' => 'Username sudah digunakan, silakan pilih username lain.',
    'db_error' => 'Gagal memproses data ke database. Silakan coba lagi.',
    'system_error' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
    default => ''
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daftar Akun - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
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

  <div class="auth-dark-title">Buat Akun Baru</div>
  <div class="auth-dark-sub">Mulai kelola budidaya ikan Anda secara mandiri</div>

  <?php if ($errorMessage !== ''): ?>
    <div class="auth-alert auth-alert-error">&#x2715; <?php echo e($errorMessage); ?></div>
  <?php endif; ?>

  <form action="process/register_process" method="POST" class="auth-dark-form">

    <div>
      <label class="auth-field-label" for="username">Username <span class="req">*</span></label>
      <div class="auth-input-wrap">
        <i class="fa-solid fa-user auth-input-icon"></i>
        <input type="text" id="username" name="user" placeholder="Huruf, angka" autocomplete="username" maxlength="50" required value="<?php echo isset($_GET['user']) ? e((string) $_GET['user']) : ''; ?>">
      </div>
    </div>

    <div>
      <label class="auth-field-label" for="password">Password <span class="req">*</span></label>
      <div class="auth-input-wrap">
        <i class="fa-solid fa-lock auth-input-icon"></i>
        <input type="password" id="password" name="pass" placeholder="Minimal <?php echo e((string) $minimumPassword); ?> karakter" autocomplete="new-password" minlength="<?php echo e((string) $minimumPassword); ?>" maxlength="128" required>
        <i class="fa-solid fa-eye auth-eye" data-target="password" aria-hidden="true"></i>
      </div>
      <div class="strength-bar-wrap"><div class="strength-bar" id="strengthBar"></div></div>
      <div class="strength-hint" id="strengthHint"></div>
    </div>

    <div>
      <label class="auth-field-label" for="confirm">Konfirmasi Password <span class="req">*</span></label>
      <div class="auth-input-wrap">
        <i class="fa-solid fa-lock auth-input-icon"></i>
        <input type="password" id="confirm" name="confirm" placeholder="Ulangi password" autocomplete="new-password" minlength="<?php echo e((string) $minimumPassword); ?>" maxlength="128" required>
        <i class="fa-solid fa-eye auth-eye" data-target="confirm" aria-hidden="true"></i>
      </div>
      <div class="match-hint" id="matchHint"></div>
    </div>

    <?php csrf_input(); ?>
    <button type="submit" class="auth-submit">Daftar Akun &#x2192;</button>

  </form>

  <div class="auth-terms">
    Dengan mendaftar, Anda memiliki akun pengelola tambak (Admin Kolam) mandiri.
  </div>

  <div class="auth-divider"></div>

  <div class="auth-dark-footer">
    Sudah punya akun? <a href="login">Masuk di sini</a>
  </div>

</div>

<!-- register.js harus dimuat sebelum auth-canvas.js agar AUTH_ORBS tersedia -->
<script src="assets/js/register.js" defer></script>
<script src="assets/js/auth-canvas.js" defer></script>

</body>
</html>
