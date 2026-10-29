<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';

// Statistik landing SeeFish: data fundamentali platform, bukan dari tabel panen legacy.
$panenHariIni = 0;
$komoditasAktif = '-';
$komoditasCount = 0;
$totalPelaku = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SeeFish - Manajemen Budidaya Ikan Air Tawar</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/landing.css">
<link rel="stylesheet" href="assets/css/logo.css">
</head>
<body class="landing-body">

<!-- ANIMATED BACKGROUND CANVAS -->
<canvas id="bgCanvas"></canvas>

<div class="land-wrap">

  <!-- NAVBAR -->
  <nav class="land-nav">
    <div class="land-logo">
      <span class="seefish-logo-mark seefish-logo-mark--nav">
        <img src="assets/SeeFish%20Logo.png" alt="SeeFish Logo" class="seefish-logo-img">
      </span>
      <span class="land-logo-text">SeeFish</span>
    </div>
    <a href="login" class="land-nav-btn">Masuk &#x2192;</a>
  </nav>

  <!-- HERO -->
  <section class="land-hero">

    <div class="land-badge">
      <span class="land-badge-dot"></span>
      Platform Budidaya Ikan Air Tawar
    </div>

    <h1 class="land-title">
      Kelola<br>
      <span class="land-title-accent">Budidaya Ikan</span>
    </h1>

    <p class="land-subtitle-region">Air Tawar &middot; Terintegrasi</p>

    <p class="land-desc">
      Catat, pantau, dan kelola data kolam, siklus budidaya, pakan, kualitas air, serta panen dalam satu tempat.
    </p>

    <div class="land-cta-group">
      <a href="login" class="land-btn-primary">
        &#x1F420; Mulai Sekarang
      </a>
      <a href="register" class="land-btn-secondary">
        Daftar Gratis &#x2192;
      </a>
    </div>

    <!-- STATS -->
    <div class="land-stats">
      <div class="land-stat">
        <div class="land-stat-num">01<span>Platform</span></div>
        <div class="land-stat-label">Terintegrasi</div>
      </div>
      <div class="land-stat">
        <div class="land-stat-num">100<span>%</span></div>
        <div class="land-stat-label">Digital</div>
      </div>
      <div class="land-stat">
        <div class="land-stat-num">24<span>/7</span></div>
        <div class="land-stat-label">Akses Data</div>
      </div>
    </div>

  </section>

  <!-- SCROLL INDICATOR -->
  <div class="land-scroll">
    <div class="land-scroll-line"></div>
    <div class="land-scroll-text">Scroll</div>
  </div>

</div>

<!-- FLOATING INFO CARDS: status fitur (belum ada data produksi) -->
<div class="land-float-cards">
  <div class="land-float-card">
    <div class="land-float-card-label">Siklus Budidaya</div>
    <div class="land-float-card-value">0</div>
    <span class="land-float-card-tag tag-green">Belum tersedia</span>
  </div>
  <div class="land-float-card">
    <div class="land-float-card-label">Estimasi Ikan</div>
    <div class="land-float-card-value">-</div>
    <span class="land-float-card-tag tag-yellow">Menunggu data</span>
  </div>
  <div class="land-float-card">
    <div class="land-float-card-label">Pengguna Terdaftar</div>
    <div class="land-float-card-value"><?php echo e(number_format($totalPelaku)); ?></div>
    <span class="land-float-card-tag tag-blue">Terdaftar</span>
  </div>
</div>

<script src="assets/js/landing.js"></script>

</body>
</html>
