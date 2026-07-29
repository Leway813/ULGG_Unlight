<?php
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = '關於本站 About Me';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機
$activeMenu = "about"; //.php


// =========================
// Donators (sorted by amount, name only)
// =========================
$donators = [];
try {
  $stmt = $pdo->query("
    SELECT name
    FROM donators
    WHERE is_public = 1
    ORDER BY created_at ASC
  ");
  $donators = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $error) {
  error_log(sprintf(
    '[ABOUT DONATORS ERROR] type=%s code=%s',
    get_class($error),
    (string)$error->getCode()
  ));
}






ob_start();  // ⭐ 開始收集本頁 HTML
header('X-Robots-Tag: noindex, follow');
?>

<style>
  /* =========================================================
   Checkpoint – Center Layout (BS3 Friendly)
========================================================= */

  .checkpoint-slider {
    width: 100%;
    display: flex;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  .checkpoint-track {
    display: flex;
    width: 100%;
  }

  .checkpoint-page {
    flex: 0 0 100%;
    max-width: 100%;
    padding: 10px 0;
    display: flex;
    justify-content: center;
  }

  .checkpoint-page h1,
  .checkpoint-page h2,
  .checkpoint-page p {
    text-align: center;
  }

  /* =========================================================
   Arrow Navigation
========================================================= */

  .cp-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20;

    width: 44px;
    height: 44px;
    border-radius: 50%;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #fff;

    font-size: 28px;
    line-height: 42px;
    text-align: center;

    cursor: pointer;
    transition: background .2s ease, border-color .2s ease, opacity .2s ease;
  }

  .cp-arrow:hover {
    background: rgba(34, 197, 94, 0.85);
    border-color: rgba(34, 197, 94, 0.9);
  }

  .cp-arrow-left {
    left: 12px;
  }

  .cp-arrow-right {
    right: 12px;
  }

  .cp-arrow.disabled {
    opacity: 0.25;
    pointer-events: none;
  }

  @media (max-width: 768px) {
    .cp-arrow {
      display: none !important;
    }
  }

  /* =========================================================
   Card Container
========================================================= */

  .cp-card {
    width: 100%;
    max-width: 900px;
    min-height: 83vh;

    display: flex;
    flex-direction: column;
    justify-content: flex-start;

    padding: 32px;

    background:
      radial-gradient(1200px 600px at top center, rgba(34, 197, 94, .06), transparent 60%),
      linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .92));

    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, .08);

    box-shadow:
      0 20px 60px rgba(0, 0, 0, .55),
      inset 0 1px 0 rgba(255, 255, 255, .04);

    position: relative;
    overflow: hidden;
  }

  .cp-card::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .05);
  }

  .cp-card::after {
    content: "UL.GG • DONATORS";
    position: absolute;
    bottom: 12px;
    right: 16px;
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
  }

  /* =========================================================
   Global Layout Helpers
========================================================= */

  /* .content-header {
    display: none;
  } */

  /* .un-main {
    margin-left: 240px;
    padding: 0 24px;
  } */

  .checkpoint-username {
    font-size: 17px;
    font-weight: 600;
    color: #b5b5b5;
    letter-spacing: .04em;
    margin-top: 4px;
  }

  /* =========================================================
   Card Slot (Upload Ready)
========================================================= */

  .card-slot {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 4;
    border-radius: 14px;
    overflow: hidden;

    background: linear-gradient(180deg,
        rgba(255, 255, 255, .06),
        rgba(255, 255, 255, .02));

    border: 1px dashed rgba(255, 255, 255, .18);
    cursor: pointer;
    transition: border-color .25s ease, box-shadow .25s ease;
  }

  .card-slot:hover {
    border-color: rgba(34, 197, 94, .9);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, .35);
  }

  .card-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* opacity: .45; */
  }

  .card-slot .slot-hint {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: 600;
    letter-spacing: .04em;
    color: rgba(255, 255, 255, .75);
    text-shadow: 0 2px 8px rgba(0, 0, 0, .65);
  }

  .card-slot:hover .slot-hint {
    color: #22c55e;
  }

  /* 左上角等級 */
  .card-level {
    position: absolute;
    top: 1px;
    /* ✅ 補回來 */
    left: 6px;
    /* 建議對齊 */
    z-index: 5;
    /* ✅ 關鍵，蓋過 card-name */

    width: 32px;
    height: 32px;
    border-radius: 50%;

    background: rgba(15, 23, 42, .85);
    border: 1px solid rgba(255, 255, 255, .25);

    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }


  /* 上方角色名稱條 */
  /* 上方角色名稱條（背景滿版） */
  .card-name {
    position: absolute;
    top: 0;
    left: -17px;
    right: 0;
    padding: 3px 6px;

    font-size: 12px;
    font-weight: 600;
    letter-spacing: .06em;

    background: linear-gradient(180deg,
        rgba(0, 0, 0, 0.95) 0%,
        rgba(0, 0, 0, 0.82) 45%,
        rgba(0, 0, 0, 0.45) 70%,
        rgba(0, 0, 0, 0.0) 100%);

    pointer-events: none;
    /* 不影響翻卡 */
  }

  /* 文字本體：扣掉 LV Tag 後置中 */
  .card-name-text {
    display: block;

    margin-left: 40px;
    /* LV 32px + 間距 */
    margin-right: 10px;

    text-align: center;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow:
      0 1px 2px rgba(0, 0, 0, 0.95),
      0 2px 6px rgba(0, 0, 0, 0.85);
  }



  @media (max-width: 768px) {
    .card-slot {
      aspect-ratio: 2 / 3;
    }

    .col-xs-6 {
      margin-bottom: 14px;
    }

    .card-level {
      top: 6px;
      left: 2px;
      width: 29px;
      height: 29px;
    }
  }

  /* =========================================
   Card Binder – Toolbar
========================================= */

  .card-binder-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 24px;
  }

  .card-search {
    width: 260px;
    max-width: 90%;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .2);
    background: rgba(15, 23, 42, .85);
    color: #e5e7eb;
    outline: none;
  }

  .card-search::placeholder {
    color: rgba(255, 255, 255, .45);
  }

  /* =========================================
   Character Bookmark Tabs – Responsive
========================================= */

  .char-bookmarks {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(88px, max-content));
    gap: 8px 10px;

    justify-content: center;
    align-content: center;

    max-width: 100%;
    padding: 6px 8px;

    overflow: visible;
    /* ✅ 不允許被切掉 */
  }

  /* Bookmark button */
  .char-bookmark {
    padding: 6px 14px;
    border-radius: 999px;

    font-size: 13px;
    font-weight: 600;
    letter-spacing: .04em;

    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #e5e7eb;

    cursor: pointer;
    white-space: nowrap;
    text-align: center;

    transition: background .15s ease, border-color .15s ease;
  }

  .char-bookmark.active {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
  }

  /* ===============================
   📱 Mobile Optimization
================================ */
  @media (max-width: 768px) {
    .char-bookmarks {
      display: flex;
      /* 🔥 改用 flex */
      flex-wrap: wrap;
      /* 🔥 自動換行 */
      justify-content: center;

      gap: 8px;
      padding: 4px 6px;
    }

    .char-bookmark {
      font-size: 12px;
      padding: 5px 12px;
    }
  }

  /* ===============================
   📱 Extra Small Devices
================================ */
  @media (max-width: 420px) {
    .char-bookmark {
      font-size: 11px;
      padding: 4px 10px;
    }
  }


  /* =========================================
   Card Binder Grid
========================================= */

  .card-binder {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-top: 16px;
  }

  .card-binder-row {
    margin-bottom: 18px;
  }

  .card-binder-label {
    text-align: center;
    font-size: 13px;
    letter-spacing: .12em;
    color: rgba(255, 255, 255, .5);
    margin-bottom: 6px;
  }

  /* Mobile */
  @media (max-width: 768px) {
    .card-binder {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  /* Upload Card Art 不使用進場動畫 */
  .cp-animate {
    opacity: 1 !important;
    transform: none !important;
    transition: none !important;
  }

  /* =========================
   Flip Card Core
========================= */

  .card-slot {
    perspective: 1200px;
  }

  .card-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform .6s cubic-bezier(.4, .2, .2, 1);
  }

  .card-slot.is-flipped .card-inner {
    transform: rotateY(180deg);
  }

  .card-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    border-radius: 14px;
    overflow: hidden;
  }

  .card-front {
    z-index: 2;
  }

  .card-back {
    transform: rotateY(180deg);
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .card-back img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .card-back-placeholder {
    color: rgba(255, 255, 255, .6);
    font-size: 14px;
    letter-spacing: .1em;
  }

  /* =========================
   Back To Index Button
========================= */

  .back-to-index {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 30;

    padding: 6px 14px;
    border-radius: 999px;

    font-size: 13px;
    font-weight: 600;
    letter-spacing: .06em;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .2);
    color: #e5e7eb;

    cursor: pointer;
    user-select: none;

    transition:
      background .2s ease,
      border-color .2s ease,
      color .2s ease,
      transform .15s ease;
  }

  .back-to-index:hover {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
    transform: translateX(-2px);
  }

  .back-to-index:active {
    transform: translateX(-4px);
  }

  /* Mobile */
  @media (max-width: 768px) {
    .back-to-index {
      font-size: 12px;
      padding: 5px 12px;
    }
  }

  .admin-card-tools {
    position: absolute;
    top: 6px;
    right: 6px;
    z-index: 10;
    display: flex;
    gap: 4px;
  }

  .admin-btn {
    padding: 2px 6px;
    font-size: 11px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 700;
    color: #052e16;
    background: rgba(34, 197, 94, .85);
  }

  .admin-btn:hover {
    background: rgba(34, 197, 94, 1);
  }

  .admin-file {
    display: none !important;
  }

  .char-bookmarks.is-empty::after {
    content: "找不到符合的角色";
    color: rgba(255, 255, 255, .5);
    font-size: 13px;
    padding: 12px;
  }

  /* =========================
   Donator Wall
========================= */

  #donatorList .char-bookmark {
    background: linear-gradient(180deg,
        rgba(255, 255, 255, .10),
        rgba(255, 255, 255, .04));
    border: 1px solid rgba(255, 255, 255, .18);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .06),
      0 6px 16px rgba(0, 0, 0, .35);
  }

  #donatorList .char-bookmark:hover {
    background: linear-gradient(180deg,
        rgba(34, 197, 94, .85),
        rgba(34, 197, 94, .65));
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
    transform: translateY(-1px);
  }
</style>

<?php
/* 放入你的 PHP程式    */
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">

        <div class="col-md-8" style="float:none; margin:0 auto;">
          <!-- Title -->
          <div class="text-center">
            <h1><i class="fas fa-info-circle"></i> 關於 UL.GG</h1>
            <p class="text-muted">Unlight Game Guide — 玩家開發的非營利統計平台</p>
            <hr>
          </div>

          <!-- Language Tabs -->
          <ul class="nav nav-tabs" role="tablist">
            <li class="active">
              <a href="#tab-tw" role="tab" data-toggle="tab">
                <i class="fa fa-flag"></i> 繁體中文
              </a>
            </li>
            <li>
              <a href="#tab-jp" role="tab" data-toggle="tab">
                <i class="fa fa-flag-o"></i> 日本語
              </a>
            </li>
            <li>
              <a href="#tab-en" role="tab" data-toggle="tab">
                <i class="fa fa-globe"></i> English
              </a>
            </li>
          </ul>

          <div class="tab-content ul-card">

            <!-- ◆◆◆ 中文 ◆◆◆ -->
            <div class="tab-pane fade in active" id="tab-tw">

              <h3><i class="fas fa-book"></i> 關於 UL.GG（Unlight Game Guide）</h3>
              <p>
                UL.GG 是由玩家自主開發、非營利的 Unlight 對戰統計平台，致力於提供：
              </p>

              <ul>
                <li>對戰紀錄統計與分析</li>
                <li>BP/QP 排名</li>
                <li>角色使用率與組合勝率</li>
                <li>角色資料、事件卡、武器資訊</li>
                <li>歷史資料查詢與數據可視化</li>
              </ul>

              <h4><i class="fa fa-exclamation-circle text-danger"></i> 免責聲明</h4>
              <div class="alert alert-warning">
                本網站為非官方粉絲製作網站。<br>
                與 Unlight 官方營運 / 開發團隊沒有任何合作、隸屬或授權關係。<br>
                所有資料均以非侵入方式取得，不對官方伺服器造成負荷或干擾。
              </div>

              <h4><i class="fa fa-shield"></i> 版權聲明</h4>
              <p>
                Unlight 之角色、美術、故事背景等內容，其著作權皆屬原版權方所有。<br>
                UL.GG 僅提供統計整理與資料索引，不主張任何著作權或修改原作品。
              </p>

              <h4><i class="fa fa-compass"></i> UL.GG 的定位</h4>
              <blockquote>
                UL.GG 是由玩家為玩家而生的資料站，目標是保存 Unlight 的對戰歷史，
                並提供最便利的數據工具，協助玩家探索更多策略與編成。
              </blockquote>
            </div>

            <!-- ◆◆◆ 日文 ◆◆◆ -->
            <div class="tab-pane fade" id="tab-jp">

              <h3><i class="fa fa-book"></i> UL.GG（Unlight Game Guide）について</h3>
              <p>
                UL.GG はプレイヤー自主開発の非営利 Unlight データ解析サイトです。
                以下のサービスを提供しています：
              </p>

              <ul>
                <li>対戦履歴の統計と分析</li>
                <li>BP/QP ランキング</li>
                <li>使用率と組み合わせ勝率</li>
                <li>キャラクター情報・イベントカード・武器データ</li>
                <li>履歴データの検索</li>
              </ul>

              <h4><i class="fa fa-exclamation-circle text-danger"></i> 免責事項</h4>
              <div class="alert alert-warning">
                本サイトは非公式ファンサイトです。<br>
                Unlight 運営・開発チームとは一切関係ありません。<br>
                データは非侵入的な方法で取得しており、ゲームサーバーに負荷を与えません。
              </div>

              <h4><i class="fa fa-shield"></i> 著作権について</h4>
              <p>
                Unlight に関する全ての著作権は原著作権者に帰属します。<br>
                UL.GG は統計と整理のみを行い、著作権を主張しません。
              </p>

              <h4><i class="fa fa-compass"></i> プロジェクトの目的</h4>
              <blockquote>
                UL.GG はプレイヤーのためのデータ保存と分析ツールであり、
                より深い戦略分析と編成研究を可能にします。
              </blockquote>
            </div>

            <!-- ◆◆◆ 英文 ◆◆◆ -->
            <div class="tab-pane fade" id="tab-en">

              <h3><i class="fa fa-book"></i> About UL.GG (Unlight Game Guide)</h3>
              <p>
                UL.GG is a non-profit, community-driven analytics platform for Unlight.
                The platform provides:
              </p>

              <ul>
                <li>Battle history statistics & analysis</li>
                <li>BP/QP leaderboards</li>
                <li>Usage rate & team win rate analytics</li>
                <li>Character, event card, and weapon data</li>
                <li>Historical data search and visualization</li>
              </ul>

              <h4><i class="fa fa-exclamation-circle text-danger"></i> Disclaimer</h4>
              <div class="alert alert-warning">
                UL.GG is an unofficial fan-made website.<br>
                It is not affiliated with the official Unlight developer or operator.<br>
                All data is collected via non-intrusive methods without server interference.
              </div>

              <h4><i class="fa fa-shield"></i> Copyright Notice</h4>
              <p>
                All Unlight characters, artwork, and related assets belong to their respective copyright owners.<br>
                UL.GG claims no ownership and provides data analysis only.
              </p>

              <h4><i class="fa fa-compass"></i> Project Mission</h4>
              <blockquote>
                UL.GG aims to preserve Unlight's matches and provide a powerful data toolset
                for strategic exploration and team-building analysis.
              </blockquote>
            </div>

          </div>
          <p class="text-muted small">
            資料來源包含公開對戰紀錄與玩家自願提供資訊，僅作統計用途。
          </p>
        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
      <div class="row">
        <div class="col-md-12">
          <div class="checkpoint-slider">
            <div class="checkpoint-track">

              <!-- =========================
                  PAGE｜感謝贊助者 Donators
              ========================== -->
              <section class="checkpoint-page" id="page-donators">
                <div class="cp-card">

                  <h2>❤️ 感謝贊助者</h2>
                  <p class="text-muted">
                    感謝支持 UL.GG 的玩家（依贊助時間排序）
                  </p>


                  <!-- Donator Name Grid -->
                  <div id="donatorList" class="char-bookmarks">
                    <?php foreach ($donators as $name): ?>
                      <div class="char-bookmark">
        <?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <p class="text-muted small" style="margin-top:18px; line-height:1.6;">
                    本站所有贊助皆為自願性支持，用於伺服器、網域與維護成本。<br>
                    不提供任何遊戲內優勢、官方身份或實體回饋。
                  </p>

                </div>
              </section>


            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->





<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
