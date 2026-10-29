<?php
require_once __DIR__ . '/config/session.php';
$username = $_SESSION['username'] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>404 - Halaman Tidak Ditemukan | SeeFish</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="assets/css/error.css">
<link rel="stylesheet" href="assets/css/logo.css">
</head>
<body>

<div class="error-wrap">

  <div class="error-card">

    <span class="seefish-logo-mark seefish-logo-mark--sm seefish-logo-mark--center">
      <img src="assets/SeeFish%20Logo.png" alt="SeeFish Logo" class="seefish-logo-img">
    </span>

    <div class="error-code">404</div>

    <div class="error-title">Halaman Tidak Ditemukan</div>

    <div class="error-desc">
      Maaf, halaman yang Anda cari tidak tersedia atau telah dipindahkan.
    </div>

    <div class="error-actions">
      <?php if($username): ?>
        <a href="dashboard" class="error-btn primary">
          <i class="fa-solid fa-chart-line"></i> Kembali ke Dashboard
        </a>
      <?php else: ?>
        <a href="index.php" class="error-btn primary">
          <i class="fa-solid fa-house"></i> Kembali ke Beranda
        </a>
      <?php endif; ?>
    </div>

  </div>

</div>

</body>
</html>