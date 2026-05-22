<?php
/*
 * time_machine/index.php — landing page.
 *
 * Renders a two-card chooser ("Slideshow" / "Manage Library") for anyone
 * who hits the bare app root. Direct bookmarks to code/index.html (slideshow)
 * and code/manage.php (management) still work and skip this page.
 *
 * Pulls credit constants from code/var.php so the footer link matches the
 * rest of the app.
 */
include_once __DIR__ . '/code/var.php';

$creditLabel = defined('APP_CREDIT_LABEL') ? APP_CREDIT_LABEL : '';
$creditUrl   = defined('APP_CREDIT_URL')   ? APP_CREDIT_URL   : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Time Machine</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
      background: linear-gradient(135deg, #f5f7fa 0%, #e8edf3 100%);
      color: #333;
      display: flex; flex-direction: column;
      min-height: 100vh;
    }
    .tm-wrap {
      flex: 1;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 32px 20px;
    }
    .tm-head {
      text-align: center;
      margin-bottom: 36px;
    }
    .tm-title {
      font-size: 28px; font-weight: 600;
      color: #1a3550; letter-spacing: 0.4px;
      margin: 0 0 6px;
    }
    .tm-subtitle {
      font-size: 13px; color: #6c7a8a;
      margin: 0;
    }
    .tm-cards {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 280px));
      gap: 20px;
      width: 100%;
      max-width: 620px;
    }
    .tm-card {
      background: #fff;
      border: 1px solid #dde3ec;
      border-radius: 12px;
      padding: 28px 22px 24px;
      text-align: center;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 1px 2px rgba(20, 40, 60, 0.04);
      transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
      display: flex; flex-direction: column;
      align-items: center; gap: 8px;
    }
    .tm-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(20, 40, 60, 0.08);
      border-color: #b3c4d9;
    }
    .tm-card .tm-icon {
      width: 60px; height: 60px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 26px;
      margin-bottom: 8px;
    }
    .tm-card-slideshow .tm-icon { background: #e3f2fd; color: #0157b3; }
    .tm-card-manage    .tm-icon { background: #e8f5e9; color: #2e7d32; }
    .tm-card-name {
      font-size: 17px; font-weight: 600; color: #1a3550;
    }
    .tm-card-desc {
      font-size: 12px; color: #6c7a8a; line-height: 1.5;
      margin: 2px 0 0;
    }
    .tm-foot {
      text-align: center;
      padding: 14px 10px 22px;
      font-size: 11px; color: #98a2b3;
    }
    .tm-foot a { color: #6c7a8a; text-decoration: none; }
    .tm-foot a:hover { text-decoration: underline; }

    @media (max-width: 520px) {
      .tm-cards { grid-template-columns: 1fr; max-width: 360px; }
      .tm-title { font-size: 24px; }
    }
  </style>
</head>
<body>
  <div class="tm-wrap">
    <div class="tm-head">
      <h1 class="tm-title">Time Machine</h1>
      <p class="tm-subtitle">Self-hosted slideshow &amp; photo curation</p>
    </div>
    <div class="tm-cards">
      <a class="tm-card tm-card-slideshow" href="code/index.html">
        <div class="tm-icon"><i class="fa fa-tv"></i></div>
        <div class="tm-card-name">Slideshow</div>
        <p class="tm-card-desc">Full-screen photo loop for a TV or kiosk display.</p>
      </a>
      <a class="tm-card tm-card-manage" href="code/manage.php">
        <div class="tm-icon"><i class="fa fa-cogs"></i></div>
        <div class="tm-card-name">Manage Library</div>
        <p class="tm-card-desc">Browse, curate, star, crop, organize from your phone or laptop.</p>
      </a>
    </div>
  </div>
  <div class="tm-foot">
    <?php if ($creditLabel !== ''): ?>
      <a href="<?php echo htmlspecialchars($creditUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
        <?php echo htmlspecialchars($creditLabel, ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php endif; ?>
  </div>
</body>
</html>
