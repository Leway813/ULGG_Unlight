<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pageTitleText = '登入 Login';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網';
$pageTitleFull = $pageTitleText . ' | UL.GG';
$activeMenu = '';

ob_start();
?>

<div class="login-panel">
  <?php if (empty($_SESSION['steam_id'])): ?>
    <h2>使用 Steam 登入 UL.GG</h2>

    <a class="btn-steam-login" href="/api/auth/steam_start.php">
      <i class="fab fa-steam"></i> 使用 Steam 登入
    </a>
  <?php else: ?>
    <div class="login-info">
      <p><b>Steam 已登入成功：</b></p>
      <p>
        <b>Steam ID：</b>
        <?= htmlspecialchars((string)$_SESSION['steam_id'], ENT_QUOTES, 'UTF-8') ?>
      </p>

      <?php if (!empty($_SESSION['username'])): ?>
        <p>
          <b>UL.GG 使用者：</b>
          <?= htmlspecialchars((string)$_SESSION['username'], ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p style="margin-top:10px;color:#9fdaff;">
          系統將在 <b>2 秒後</b> 自動跳轉至首頁...
        </p>
        <a href="/pages/index.php"
          class="btn-primary"
          style="color:#e0e0e0;">
          若未跳轉，點此進入 UL.GG
        </a>

        <script>
          setTimeout(function() {
            window.location.href = "/pages/index.php";
          }, 2000);
        </script>
      <?php else: ?>
        <p style="color:#ff6b6b;">此 Steam 帳號尚未建立 UL.GG 使用者資料</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  .login-panel {
    max-width: 460px;
    margin: 50px auto;
    background: rgba(255, 255, 255, 0.08);
    padding: 32px;
    border-radius: 12px;
    text-align: center;
    color: #e0e0e0;
  }

  .btn-steam-login {
    display: inline-block;
    background: #171a21;
    color: white;
    padding: 12px 24px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 18px;
  }

  .btn-steam-login:hover {
    background: #2a475e;
  }

  .login-info {
    margin-top: 20px;
    font-size: 14px;
    background: rgba(0, 0, 0, 0.2);
    padding: 12px;
    border-radius: 6px;
  }
</style>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
