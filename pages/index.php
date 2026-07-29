<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/get_steam_unlight_news.php';

$pdo = $db;

$canRefreshSteamNews =
  (int)($_SESSION['permission'] ?? 0) >= 2;

if ($canRefreshSteamNews && isset($_GET['debug_steam'])) {
  $steamNews = getUnlightSteamNews(3);
} else {
  $steamNews = ulggCacheRemember('index_steam_news_v2', 21600, function () {
    return getUnlightSteamNews(3);
  });
}

$seoTitle = '首頁情報 | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = 'index';
$pageTitleFull = '首頁情報 | UL.GG 戰績網'; //桌機
$pageTitleText = '首頁情報'; //手機

ob_start();
function ulggSearchTrendUrl(string $keyword, ?string $page = null): string
{
  $keyword = trim($keyword);

  if ($page === '/pages/bp_player.php') {
    return '/pages/bp_player.php?' . http_build_query([
      'server' => 'ALL',
      'name' => $keyword,
    ], '', '&', PHP_QUERY_RFC3986);
  }

  if ($page === '/pages/qp_player.php') {
    return '/pages/qp_player.php?' . http_build_query([
      'server' => 'ALL',
      'name' => $keyword,
    ], '', '&', PHP_QUERY_RFC3986);
  }

  return '/pages/fight.php?' . http_build_query([
    'player_name' => $keyword,
    'server' => 'ALL',
  ], '', '&', PHP_QUERY_RFC3986);
}

function ulggSteamNewsUrl(mixed $value): ?string
{
  $url = trim((string)$value);
  if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
    return null;
  }

  $parts = parse_url($url);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower((string)($parts['host'] ?? ''));
  $allowedHosts = [
    'steamcommunity.com',
    'store.steampowered.com',
  ];

  $hostAllowed = false;
  foreach ($allowedHosts as $allowedHost) {
    if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
      $hostAllowed = true;
      break;
    }
  }

  return $scheme === 'https' && $hostAllowed ? $url : null;
}

function ulggNoticeUrl(mixed $value): ?string
{
  $url = trim((string)$value);
  if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
    return null;
  }

  if (
    str_starts_with($url, '/')
    && !str_starts_with($url, '//')
    && !str_contains($url, '..')
  ) {
    return $url;
  }

  return filter_var($url, FILTER_VALIDATE_URL) !== false
    && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
      ? $url
      : null;
}

function ulggCacheRemember(string $key, int $ttl, callable $callback)
{
  $cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'ulgg-cache';

  if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    return $callback();
  }

  $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
  $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';

  if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    $json = @file_get_contents($cacheFile);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (is_array($data)) {
      return $data;
    }
  }

  $data = $callback();
  $json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  if (is_string($json)) {
    @file_put_contents($cacheFile, $json, LOCK_EX);
  }

  return $data;
}

function classifySteamCategory($title)
{
  $t = strtolower($title);

  if (str_contains($t, 'known issue') || str_contains($t, 'issue')) {
    return 'ISSUE'; // 已知問題
  }

  if (str_contains($t, 'upcoming')) {
    return 'UPCOMING'; // 維修預告
  }

  if (str_contains($t, 'maintenance')) {
    return 'MAINT_NOTICE'; // 官方維修公告
  }

  // 未來如果有活動型 Steam 公告可以使用
  if (str_contains($t, 'campaign') || str_contains($t, 'event')) {
    return 'EVENT';
  }

  return 'MAINT_NOTICE';
}

?>

<style>
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
  /* UNLIGHT 哥德黑色主題 – Player Home */
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */

  body {
    background: #0b0b0d;
    color: #e5e5e5;
    font-family: "Cinzel", "Noto Sans TC", serif;
  }

  .hero {
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.07);
    background: #141414;
    border-radius: 10px;
    padding: 18px 18px 20px;
    margin-bottom: 20px;
    border: 1px solid #1f1f1f;
  }

  .hero-title {
    font-size: 44px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #e8d19e;
    /* 金色 */
    text-shadow: 0 0 12px rgba(255, 215, 150, 0.35);
  }

  .hero-sub {
    margin-top: 10px;
    font-size: 18px;
    opacity: 0.85;
  }

  .hero-buttons {
    margin-top: 30px;
  }

  .btn-gothic {
    padding: 12px 25px;
    margin: 8px;
    border: 1px solid #e8d19e;
    color: #e8d19e;
    text-decoration: none;
    background: transparent;
    transition: 0.25s;
    display: inline-block;
    letter-spacing: 1px;
    width: 120px;
  }

  .btn-gothic:hover {
    background: #e8d19e;
    color: #000;
  }

  .search-box {
    width: 100%;
    max-width: 480px;
    margin: 35px auto;
    text-align: center;
  }

  .search-box input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    background: #16161a;
    color: #fff;
    border-radius: 4px;
  }

  .section-title {
    text-align: left;
    font-size: 22px;
    margin: 15px;
    color: #e8d19e;
    border-left: 4px solid #e8d19e;
    padding-left: 10px;
  }

  .grid-3 {
    display: grid;
    gap: 20px;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  }

  .card-gothic {
    background: #131316;
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    position: relative;
    color: #dcdcdc;
    transition: 0.25s;
  }

  .card-gothic:hover {
    border-color: #e8d19e;
    box-shadow: 0 0 12px rgba(255, 255, 200, 0.15);
  }

  .deck-title {
    font-size: 18px;
  }

  .deck-rate {
    margin-top: 8px;
    font-size: 26px;
    color: #e8d19e;
    font-weight: 600;
  }

  .deck-link {
    display: inline-block;
    margin-top: 10px;
    color: #e8d19e;
    text-decoration: underline;
  }

  .quick-link {
    padding: 18px;
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #e8d19e;
    background: #17171a;
    text-decoration: none;
    transition: 0.25s;
  }

  .quick-link:hover {
    border-color: #e8d19e;
    background: #202024;
  }

  .live-box {
    margin-top: 30px;
    background: #19191c;
    padding: 20px;
    border: 1px solid rgba(255, 255, 255, 0.1);
  }

  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
  /* UNLIGHT 布告欄公告板風格 */
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */

  .bulletin-board {
      background: #151417;
    background-size: cover;
    border: 2px solid #3e3428;
    box-shadow: 0 0 18px rgba(0, 0, 0, 0.6);
    padding: 5px;
    margin-top: 35px;
    border-radius: 6px;
    position: relative;
  }

  /* 左上與右上裝飾金屬釘子 */
  .bulletin-board::before,
  .bulletin-board::after {
    content: "";
    position: absolute;
    width: 18px;
    height: 18px;
    background: radial-gradient(circle, #d7c8a5 0%, #9a8765 100%);
    border-radius: 50%;
    top: 10px;
  }

  .bulletin-board::before {
    left: 10px;
  }

  .bulletin-board::after {
    right: 10px;
  }

  /* 公告紙張 */
  .notice {
    background: #f2e5c7;
    border-left: 6px solid #b88b4a;
    padding: 14px 18px;
    margin-bottom: 18px;
    color: #3a2f25;
    font-family: "Noto Serif TC", serif;
    position: relative;
    box-shadow: 3px 3px 10px rgba(0, 0, 0, 0.25);
  }

  /* 紙張上面的小釘子 */
  .notice::before {
    content: "";
    position: absolute;
    width: 14px;
    height: 14px;
    background: radial-gradient(circle, #e0d2b1 0%, #8c7a5b 100%);
    border-radius: 50%;
    top: -7px;
    left: 12px;
  }

  .notice-title {
    font-size: 19px;
    font-weight: 700;
    margin: 6px;
    color: #2e2418;
  }

  .notice-desc {
    opacity: 0.75;
    margin-top: 4px;
    white-space: pre-line;
    word-break: break-word;
  }

  .notice-link {
    display: inline-block;
    margin-top: 6px;
    color: #6b4a1e;
    font-weight: 600;
    text-decoration: underline;
  }

  /* 標籤 */
  .notice-tag {
    padding: 2px 7px;
    font-size: 12px;
    color: white;
    border-radius: 4px;
    margin-left: 8px;
  }

  .tag-hot {
    background: #b33a3a;
  }

  .tag-new {
    background: #3a7cb3;
  }

  .notice-tag-steam {
    background: #000;
    color: #ffeb3b;
    /* 明亮黃色 */
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    margin-left: 6px;
  }

  /* Base style */
  .notice-tag-steam {
    padding: 2px 8px;
    font-size: 12px;
    font-weight: bold;
    border-radius: 4px;
    margin-left: 6px;
    letter-spacing: 1px;
  }

  /* 系統維修公告（金色）*/
  .tag-steam-maint {
    background: #c9a86a;
    color: #000;
    box-shadow: 0 0 6px rgba(201, 168, 106, 0.4);
  }

  /* 維修預告（橘色）*/
  .tag-steam-upcoming {
    background: #e6a34c;
    color: #000;
    box-shadow: 0 0 6px rgba(230, 163, 76, 0.4);
  }

  /* 已知問題（紅色）*/
  .tag-steam-issue {
    background: #b33a3a;
    color: #fff;
    box-shadow: 0 0 6px rgba(179, 58, 58, 0.4);
  }

  /* 活動（藍色）*/
  .tag-steam-event {
    background: #3a7cb3;
    color: #fff;
    box-shadow: 0 0 6px rgba(58, 124, 179, 0.4);
  }

  .bulletin-board-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
  }

  /* 手機自動變一欄 */
  @media (max-width: 768px) {
    .bulletin-board-2col {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 768px) {
    .grid-3 {
      grid-template-columns: 1fr;
    }
  }

  .search-box input {
    padding-left: 40px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 6px;
  }

  .search-box::before {
    content: "🔍";
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.7;
  }

  .deck-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 3px 0;
    font-size: 14px;
    color: #ddd;
  }

  .deck-left {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .deck-rank {
    font-weight: bold;
    color: #ffd86b;
    /* 金色 */
  }

  .deck-right {
    font-weight: bold;
    color: #8cd4ff;
    /* 數值亮藍 */
    min-width: 70px;
    /* 讓右側文字對齊 */
    text-align: right;
  }

  .keyword-link:hover {
    background: rgba(120, 120, 255, 0.15);
  }

  .char-link:hover {
    background: rgba(255, 180, 80, 0.15);
  }

  .activity-trend-section {
    margin: 18px 15px;
  }

  .activity-trend-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(320px, 1fr));
    gap: 14px;
  }

  .activity-trend-card {
    background: #131316;
    border: 1px solid rgba(255, 255, 255, 0.12);
    padding: 14px 16px;
    min-height: 260px;
  }

  .activity-trend-card:hover {
    border-color: #e8d19e;
    box-shadow: 0 0 12px rgba(255, 255, 200, 0.12);
  }

  .activity-trend-head {
    margin-bottom: 10px;
  }

  .activity-trend-title {
    color: #f2deb2;
    font-size: 17px;
    font-weight: 700;
  }

  .activity-trend-subtitle {
    color: #9ca0c8;
    font-size: 13px;
    margin-top: 3px;
  }

  .activity-trend-card canvas {
    width: 100%;
    height: 190px !important;
  }

  @media (max-width: 900px) {
    .activity-trend-grid {
      grid-template-columns: 1fr;
    }

    .activity-trend-card {
      min-height: 240px;
    }
  }

  .activity-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }

  .activity-summary-card {
    background: #131316;
    border: 1px solid rgba(255, 255, 255, 0.12);
    padding: 12px 14px;
  }

  .activity-summary-label {
    color: #9ca0c8;
    font-size: 13px;
    margin-bottom: 4px;
  }

  .activity-summary-value {
    color: #f2deb2;
    font-size: 22px;
    font-weight: 800;
  }

  @media (max-width: 900px) {
    .activity-summary-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  .home-section {
    margin: 18px 15px 24px;
  }

  .home-section-head {
    margin-bottom: 12px;
  }

  .section-title {
    margin: 0;
    padding-left: 10px;
    border-left: 4px solid #e8d19e;
    color: #f2deb2;
    font-size: 22px;
    font-weight: 800;
    line-height: 1.2;
  }

  .home-section-subtitle {
    margin-top: 6px;
    color: #9ca0c8;
    font-size: 13px;
    line-height: 1.5;
  }

  .home-board-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(280px, 420px));
    gap: 14px;
    align-items: start;
  }

  .home-board-card {
    position: relative;
    overflow: hidden;
    background:
      radial-gradient(circle at top right, rgba(232, 209, 158, 0.08), transparent 34%),
      linear-gradient(180deg, #17171c 0%, #101014 100%);
    border: 1px solid rgba(232, 209, 158, 0.18);
    border-radius: 14px;
    padding: 14px 16px 12px;
    color: #ddd;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.22);
    transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
  }

  .home-board-card:hover {
    transform: translateY(-2px);
    border-color: rgba(232, 209, 158, 0.48);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32), 0 0 16px rgba(232, 209, 158, 0.08);
  }

  .home-board-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 10px;
  }

  .deck-title {
    color: #f2deb2;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: 0.02em;
  }

  .deck-subtitle {
    margin-top: 3px;
    color: #8f93bb;
    font-size: 12px;
    line-height: 1.4;
  }

  .board-badge {
    flex: 0 0 auto;
    padding: 4px 8px;
    border: 1px solid rgba(232, 209, 158, 0.35);
    border-radius: 999px;
    color: #e8d19e;
    background: rgba(232, 209, 158, 0.08);
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
  }

  .deck-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    min-height: 30px;
    padding: 5px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.055);
    font-size: 14px;
  }

  .deck-row:last-of-type {
    border-bottom: 0;
  }

  .deck-left {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
  }

  .deck-no {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    border-radius: 7px;
    background: rgba(255, 255, 255, 0.06);
    color: #bfc3e6;
    font-size: 12px;
    font-weight: 800;
  }

  .deck-rank {
    flex: 0 0 auto;
    font-weight: 900;
    font-size: 13px;
  }

  .deck-rank.up {
    color: #ffd86b;
    text-shadow: 0 0 10px rgba(255, 216, 107, 0.15);
  }

  .deck-name {
    min-width: 0;
    overflow: hidden;
    color: #f0f0f5;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-weight: 650;
  }

  .deck-right {
    color: #8cd4ff;
    font-size: 13px;
    font-weight: 800;
    text-align: right;
    white-space: nowrap;
  }

  .deck-empty {
    padding: 14px 0 10px;
    color: #9ca0c8;
    font-size: 14px;
  }

  .deck-link {
    display: inline-flex;
    align-items: center;
    margin-top: 10px;
    color: #e8d19e;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
  }

  .deck-link:hover {
    text-decoration: underline;
  }

  .deck-chip {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    min-width: 0;
    padding: 3px 8px;
    border: 1px solid rgba(140, 212, 255, 0.22);
    border-radius: 999px;
    background: rgba(140, 212, 255, 0.08);
    color: #dfeeff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .deck-chip:hover {
    border-color: rgba(232, 209, 158, 0.45);
    color: #f2deb2;
    background: rgba(232, 209, 158, 0.08);
  }

  @media (max-width: 1100px) {
    .home-board-grid {
      grid-template-columns: repeat(2, minmax(260px, 1fr));
    }
  }

  @media (max-width: 760px) {
    .home-section {
      margin: 16px 10px 22px;
    }

    .home-board-grid {
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .home-board-card {
      border-radius: 12px;
      padding: 13px 13px 11px;
    }

    .section-title {
      font-size: 20px;
    }

    .deck-row {
      gap: 8px;
      min-height: 32px;
      font-size: 13px;
    }

    .deck-right {
      font-size: 12px;
    }

    .deck-chip {
      font-size: 12px;
    }
  }

  @media (max-width: 430px) {
    .home-board-card-head {
      flex-direction: column;
      gap: 6px;
    }

    .board-badge {
      align-self: flex-start;
    }

    .deck-row {
      grid-template-columns: 1fr;
      gap: 3px;
      padding: 7px 0;
    }

    .deck-right {
      text-align: left;
      padding-left: 29px;
    }
  }

  .deck-right-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    line-height: 1.25;
  }

  .deck-delta {
    color: #ffd86b;
    font-size: 12px;
    font-weight: 900;
    text-shadow: 0 0 10px rgba(255, 216, 107, 0.14);
  }

  @media (max-width: 430px) {
    .deck-right-stack {
      align-items: flex-start;
    }
  }

  .notice-title {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
  }

  .notice-date {
    color: #9ca0c8;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }

  .notice {
    padding: 12px 0;
  }

  .notice+.notice {
    border-top: 1px solid rgba(255, 255, 255, 0.08);
  }

  .notice-desc {
    margin-top: 6px;
    line-height: 1.6;
  }

  .strong-deck-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 14px;
  }

  .strong-deck-card {
    display: block;
    position: relative;
    overflow: hidden;
    min-height: 190px;
    padding: 14px 14px 12px;
    border-radius: 14px;
    border: 1px solid rgba(232, 209, 158, 0.18);
    background:
      radial-gradient(circle at top right, rgba(232, 209, 158, 0.10), transparent 36%),
      linear-gradient(180deg, #17171c 0%, #101014 100%);
    color: #e5e7eb;
    text-decoration: none;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.22);
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
  }

  .strong-deck-card:hover {
    transform: translateY(-2px);
  }

  .strong-deck-card.tier-s:hover {
    box-shadow:
      0 16px 38px rgba(0, 0, 0, .38),
      0 0 24px rgba(255, 216, 107, .20);
  }

  .strong-deck-card.tier-a:hover {
    box-shadow:
      0 16px 36px rgba(0, 0, 0, .34),
      0 0 22px rgba(140, 212, 255, .16);
  }

  .strong-deck-card.tier-b:hover {
    box-shadow:
      0 14px 34px rgba(0, 0, 0, .32),
      0 0 18px rgba(180, 160, 255, .12);
  }

  .strong-deck-card.tier-c:hover {
    box-shadow:
      0 12px 28px rgba(0, 0, 0, .28);
  }

  .strong-deck-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 3px;
    background: rgba(255, 255, 255, .08);
  }

  .strong-deck-card.tier-s::before {
    background: linear-gradient(to bottom, #fff4bd, #ffd86b, #9b6b19);
  }

  .strong-deck-card.tier-a::before {
    background: linear-gradient(to bottom, #dff4ff, #8cd4ff, #316b9b);
  }

  .strong-deck-card.tier-b::before {
    background: linear-gradient(to bottom, #ded6ff, #a99cff, #5f548f);
  }

  .strong-deck-card.tier-c::before {
    background: rgba(255, 255, 255, .12);
  }

  .strong-deck-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
  }

  .strong-deck-zone {
    color: #aeb6e8;
    font-size: 12px;
    font-weight: 800;
  }

  .tier-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
  }

  .tier-s {
    color: #fff4bd;
    background: rgba(255, 216, 107, .18);
    border: 1px solid rgba(255, 216, 107, .48);
  }

  .tier-a {
    color: #bde7ff;
    background: rgba(140, 212, 255, .14);
    border: 1px solid rgba(140, 212, 255, .42);
  }

  .tier-b {
    color: #d8d5ef;
    background: rgba(180, 180, 220, .12);
    border: 1px solid rgba(180, 180, 220, .28);
  }

  .tier-c {
    color: #b6b6c9;
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .12);
  }

  .strong-deck-chars {
    position: relative;
    width: 190px;
    height: 82px;
    margin: 8px auto 12px;
  }

  .strong-char-card {
    position: absolute;
    top: 0;
    width: 82px;
    height: 82px;
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, .12);
    background: #0b0b0f;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .28);
  }

  .strong-char-card.c0 {
    left: 0;
    z-index: 3;
  }

  .strong-char-card.c1 {
    left: 54px;
    z-index: 2;
  }

  .strong-char-card.c2 {
    left: 108px;
    z-index: 1;
  }

  .strong-char-card img {
    width: 100%;
    height: 125%;
    object-fit: cover;
    object-position: top center;
  }

  .strong-deck-name {
    min-height: 38px;
    color: #f4f4fa;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.35;
  }

  .strong-deck-meta {
    margin-top: 7px;
    color: #9ca0c8;
    font-size: 12px;
    font-weight: 700;
  }


  .strong-deck-score {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-top: 9px;
    padding: 7px 9px;
    border-radius: 10px;
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(255, 255, 255, .06);
    color: #dfeeff;
    font-size: 12px;
    font-weight: 800;
  }

  .strong-deck-score strong {
    color: #f2deb2;
    font-size: 13px;
  }

  .rate-help {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    cursor: help;
    border-bottom: 1px dotted rgba(232, 209, 158, .55);
  }

  .help-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: rgba(232, 209, 158, .14);
    border: 1px solid rgba(232, 209, 158, .42);
    color: #f2deb2;
    font-size: 10px;
    font-weight: 900;
    line-height: 1;
  }

  .rate-help::after {
    content: attr(data-tooltip);
    position: absolute;
    right: 0;
    bottom: calc(100% + 8px);
    z-index: 20;
    width: min(280px, 78vw);
    padding: 9px 10px;
    border-radius: 10px;
    background: #020617;
    border: 1px solid rgba(232, 209, 158, .35);
    color: #e5e7eb;
    box-shadow: 0 12px 28px rgba(0, 0, 0, .42);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
    white-space: normal;
    text-align: left;
    opacity: 0;
    pointer-events: none;
    transform: translateY(4px);
    transition: opacity .15s ease, transform .15s ease;
  }

  .rate-help:hover::after,
  .rate-help:focus::after {
    opacity: 1;
    transform: translateY(0);
  }

  .strong-deck-meta-line {
    margin-top: 6px;
    color: #9ca0c8;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
  }

  .strong-deck-confidence {
    display: inline-flex;
    align-items: center;
    margin-top: 7px;
    padding: 3px 7px;
    border-radius: 999px;
    background: rgba(140, 212, 255, .08);
    border: 1px solid rgba(140, 212, 255, .22);
    color: #bde7ff;
    font-size: 11px;
    font-weight: 900;
  }

  .strong-deck-empty {
    grid-column: 1 / -1;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 12px;
    background: #131316;
    color: #9ca0c8;
  }

  .home-more-link {
    display: inline-flex;
    margin-top: 12px;
    color: #e8d19e;
    font-size: 18px;
    font-weight: 800;
    text-decoration: none;
  }

  .home-more-link:hover {
    text-decoration: underline;
  }

  @media (max-width: 1180px) {
    .strong-deck-grid {
      grid-template-columns: repeat(2, minmax(220px, 1fr));
    }
  }

  @media (max-width: 640px) {
    .strong-deck-grid {
      grid-template-columns: 1fr;
    }

    .strong-deck-card {
      min-height: 176px;
    }

    .strong-deck-chars {
      width: 174px;
      height: 74px;
      margin: 8px auto 12px;
    }

    .strong-char-card {
      width: 74px;
      height: 74px;
    }

    .strong-char-card.c1 {
      left: 50px;
    }

    .strong-char-card.c2 {
      left: 100px;
    }
  }

  .strong-deck-card.tier-s {
    border-color: rgba(255, 216, 107, 0.58);
    background:
      radial-gradient(circle at top right, rgba(255, 216, 107, 0.22), transparent 38%),
      radial-gradient(circle at bottom left, rgba(232, 209, 158, 0.10), transparent 42%),
      linear-gradient(180deg, #1e1a12 0%, #111014 100%);
    box-shadow:
      0 12px 32px rgba(0, 0, 0, .32),
      0 0 18px rgba(255, 216, 107, .12);
  }

  .strong-deck-card.tier-a {
    border-color: rgba(140, 212, 255, 0.46);
    background:
      radial-gradient(circle at top right, rgba(140, 212, 255, 0.18), transparent 38%),
      linear-gradient(180deg, #151b22 0%, #101014 100%);
    box-shadow:
      0 12px 30px rgba(0, 0, 0, .28),
      0 0 14px rgba(140, 212, 255, .08);
  }

  .strong-deck-card.tier-b {
    border-color: rgba(180, 160, 255, 0.34);
    background:
      radial-gradient(circle at top right, rgba(180, 160, 255, 0.12), transparent 38%),
      linear-gradient(180deg, #171622 0%, #101014 100%);
  }

  .strong-deck-card.tier-c {
    opacity: .92;
    border-color: rgba(255, 255, 255, .12);
    background:
      radial-gradient(circle at top right, rgba(255, 255, 255, 0.06), transparent 36%),
      linear-gradient(180deg, #151519 0%, #101014 100%);
  }



  .deck-row-link {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: inherit;
  }

  .deck-row-link:hover {
    text-decoration: none;
    color: inherit;
    background: rgba(96, 165, 250, .08);
  }

  .deck-row-link .deck-left {
    min-width: 0;
  }

  .deck-row-link .deck-right {
    justify-self: end;
  }

  @media (max-width: 430px) {
    .home-board-card-head {
      flex-direction: column;
      gap: 6px;
    }

    .board-badge {
      align-self: flex-start;
    }

    .deck-row {
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 8px;
      padding: 7px 0;
    }

    .deck-row-link {
      grid-template-columns: minmax(0, 1fr) auto;
    }

    .deck-right {
      text-align: right;
      padding-left: 0;
    }

    .deck-right-stack {
      align-items: flex-end;
    }

    .deck-name,
    .deck-chip {
      max-width: 150px;
    }
  }
</style>

<?php
$today       = date("Y-m-d");
// ------------------------------------------------------
// 7. 今日熱門搜尋字 / 角色
// ------------------------------------------------------
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

$hotData = ulggCacheRemember('index_hot_data_' . date('Ymd'), 300, function () use ($db, $todayStart, $tomorrowStart) {
  try {
  $sqlSearch = "
    SELECT
      search_term,
      COUNT(*) AS cnt,
      SUBSTRING_INDEX(
        GROUP_CONCAT(page ORDER BY visited_at DESC SEPARATOR ','),
        ',',
        1
      ) AS latest_page
    FROM visitors
    WHERE visited_at >= :today_start
      AND visited_at < :tomorrow_start
      AND is_bot = 0
      AND search_term IS NOT NULL
      AND search_term <> ''
      AND page IN (
        '/pages/fight.php',
        '/pages/bp_player.php',
        '/pages/qp_player.php'
      )
      AND search_term NOT IN ('???', 'null', 'undefined')
    GROUP BY search_term
    HAVING cnt >= 2
      AND NOT (
        cnt >= 5
        AND COUNT(DISTINCT ip) >= cnt * 0.8
        AND COUNT(DISTINCT session_id) >= cnt * 0.8
      )
    ORDER BY cnt DESC, MAX(visited_at) DESC
    LIMIT 5
  ";
  $stmtSearch = $db->prepare($sqlSearch);
  $stmtSearch->execute([
    ':today_start' => $todayStart,
    ':tomorrow_start' => $tomorrowStart
  ]);
  $topSearch = $stmtSearch->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $sqlChar = "
  SELECT
    u.id AS char_id,
    u.name AS character_name,
    u.level,
    COUNT(*) AS cnt,
    MAX(v.visited_at) AS last_view_at
  FROM visitors v
  JOIN unlight u
    ON u.id = v.character_id
  WHERE v.visited_at >= :today_start
    AND v.visited_at < :tomorrow_start
    AND v.is_bot = 0
    AND v.character_id IS NOT NULL
  GROUP BY u.id, u.name, u.level
  ORDER BY cnt DESC, last_view_at DESC
  LIMIT 5
";
  $stmtChar = $db->prepare($sqlChar);
  $stmtChar->execute([
    ':today_start' => $todayStart,
    ':tomorrow_start' => $tomorrowStart
  ]);
  $topChar = $stmtChar->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return [
    'topSearch' => $topSearch,
    'topChar' => $topChar
  ];
  } catch (Throwable $error) {
    error_log(sprintf(
      '[INDEX HOT DATA ERROR] type=%s code=%s',
      get_class($error),
      (string)$error->getCode()
    ));

    return [
      'topSearch' => [],
      'topChar' => [],
    ];
  }
});

$topSearch = $hotData['topSearch'] ?? [];
$topChar = $hotData['topChar'] ?? [];



// 撈最新 3 則有效公告
/* $sql = "
  SELECT cat, title, `desc`, link
  FROM ulgg_notices
  WHERE is_active = 1
  ORDER BY start_at DESC, id DESC
  LIMIT 3
";

$stmt = $pdo->query($sql); */

$ulggNotices = [];

try {
  $sql_notices = "
    SELECT
      id,
      cat,
      title,
      `desc`,
      link,
      start_at,
      end_at,
      created_at
    FROM ulgg_notices
    WHERE is_active = 1
      AND (start_at IS NULL OR start_at <= NOW())
      AND (end_at IS NULL OR end_at >= NOW())
      AND COALESCE(start_at, created_at) >= DATE_SUB(NOW(), INTERVAL 2 MONTH)
    ORDER BY COALESCE(start_at, created_at) DESC, id DESC
    LIMIT 10
  ";

  $stmt_notices = $db->prepare($sql_notices);
  $stmt_notices->execute();
  $ulggNotices = $stmt_notices->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log(sprintf(
    '[INDEX ULGG NOTICES ERROR] type=%s code=%s',
    get_class($e),
    (string)$e->getCode()
  ));
  $ulggNotices = [];
}


// tag 對應 class（你原本就有的話可省略）
$tagClassMap = [
  'SYSTEM_NOTICE' => 'tag-system',
  'MAINT_NOTICE'  => 'tag-maint',
  'EVENT_NOTICE'  => 'tag-event',
  'RANK_NOTICE'   => 'tag-rank',
];

$trendCache = ulggCacheRemember('index_activity_trend_v3', 3600, function () use ($db) {
  $trendLabels = [];
  $visitData = [];
  $uniqueVisitorData = [];
  $battleData = [];

  $startDate = date('Y-m-d', strtotime('-29 days'));
  $endDate = date('Y-m-d');

  $dateMap = [];
  for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dateMap[$d] = [
      'label' => date('m/d', strtotime($d)),
      'visits' => 0,
      'unique_visitors' => 0,
      'battles' => 0
    ];
  }

  try {
    $sqlVisits = "
      SELECT
        DATE(v.visited_at) AS visit_date,
        COUNT(*) AS page_views,
        COUNT(DISTINCT NULLIF(v.session_id, '')) AS active_sessions
      FROM visitors v
      LEFT JOIN (
        SELECT ip
        FROM visitors
        WHERE visited_at >= :start_date_ip
          AND visited_at < DATE_ADD(:end_date_ip, INTERVAL 1 DAY)
          AND is_bot = 0
        GROUP BY ip
        HAVING COUNT(*) > 300
      ) noisy_ip
        ON v.ip = noisy_ip.ip
      LEFT JOIN (
        SELECT user_agent
        FROM visitors
        WHERE visited_at >= :start_date_ua
          AND visited_at < DATE_ADD(:end_date_ua, INTERVAL 1 DAY)
          AND is_bot = 0
          AND user_agent IS NOT NULL
          AND user_agent <> ''
        GROUP BY user_agent
        HAVING COUNT(*) > 1000
      ) noisy_ua
        ON v.user_agent = noisy_ua.user_agent
      WHERE v.visited_at >= :start_date
        AND v.visited_at < DATE_ADD(:end_date, INTERVAL 1 DAY)
        AND v.is_bot = 0
        AND noisy_ip.ip IS NULL
        AND noisy_ua.user_agent IS NULL
        AND v.page IS NOT NULL
        AND v.page <> ''
        AND LOWER(v.user_agent) NOT LIKE '%bot%'
        AND LOWER(v.user_agent) NOT LIKE '%crawler%'
        AND LOWER(v.user_agent) NOT LIKE '%spider%'
        AND LOWER(v.user_agent) NOT LIKE '%headless%'
        AND LOWER(v.user_agent) NOT LIKE '%python%'
        AND LOWER(v.user_agent) NOT LIKE '%curl%'
        AND LOWER(v.user_agent) NOT LIKE '%wget%'
        AND v.ip NOT LIKE '216.73.216.%'
        AND v.ip NOT LIKE '216.73.217.%'
        AND v.ip NOT LIKE '66.249.%'
      GROUP BY DATE(v.visited_at)
      ORDER BY visit_date ASC
    ";

    $stmtVisits = $db->prepare($sqlVisits);
    $stmtVisits->execute([
      ':start_date_ip' => $startDate,
      ':end_date_ip' => $endDate,
      ':start_date_ua' => $startDate,
      ':end_date_ua' => $endDate,
      ':start_date' => $startDate,
      ':end_date' => $endDate
    ]);

    foreach ($stmtVisits->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $d = $row['visit_date'];
      if (isset($dateMap[$d])) {
        $dateMap[$d]['visits'] = (int)$row['page_views'];
        $dateMap[$d]['unique_visitors'] = (int)$row['active_sessions'];
      }
    }

    $sqlBattles = "
      SELECT
        DATE(update_time) AS battle_date,
        COUNT(DISTINCT room_id) AS battle_count
      FROM arena_unlight
      WHERE update_time >= :start_date
        AND update_time < DATE_ADD(:end_date, INTERVAL 1 DAY)
        AND room_id IS NOT NULL
        AND room_id <> ''
      GROUP BY DATE(update_time)
      ORDER BY battle_date ASC
    ";

    $stmtBattles = $db->prepare($sqlBattles);
    $stmtBattles->execute([
      ':start_date' => $startDate,
      ':end_date' => $endDate
    ]);

    foreach ($stmtBattles->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $d = $row['battle_date'];
      if (isset($dateMap[$d])) {
        $dateMap[$d]['battles'] = (int)$row['battle_count'];
      }
    }

    foreach ($dateMap as $v) {
      $trendLabels[] = $v['label'];
      $visitData[] = $v['visits'];
      $uniqueVisitorData[] = $v['unique_visitors'];
      $battleData[] = $v['battles'];
    }
  } catch (Throwable $e) {
    error_log(sprintf(
      '[INDEX ACTIVITY TREND ERROR] type=%s code=%s',
      get_class($e),
      (string)$e->getCode()
    ));
  }

  return [
    'trendLabels' => $trendLabels,
    'visitData' => $visitData,
    'uniqueVisitorData' => $uniqueVisitorData,
    'battleData' => $battleData
  ];
});

$trendLabels = $trendCache['trendLabels'] ?? [];
$visitData = $trendCache['visitData'] ?? [];
$uniqueVisitorData = $trendCache['uniqueVisitorData'] ?? [];
$battleData = $trendCache['battleData'] ?? [];

$totalVisits30 = array_sum($visitData);
$totalUnique30 = array_sum($uniqueVisitorData);
$totalBattles30 = array_sum($battleData);

$last7Visits = array_sum(array_slice($visitData, -7));
$prev7Visits = array_sum(array_slice($visitData, -14, 7));

$last7Battles = array_sum(array_slice($battleData, -7));
$prev7Battles = array_sum(array_slice($battleData, -14, 7));

$visitDiffRate = $prev7Visits > 0 ? (($last7Visits - $prev7Visits) / $prev7Visits * 100) : null;
$battleDiffRate = $prev7Battles > 0 ? (($last7Battles - $prev7Battles) / $prev7Battles * 100) : null;


// ============================
// 卡 2：BP 躍升前 5 名（Steam TW）
// 比較最新 ts 與前一次 ts
// rise = old_rank - new_rank，越大代表進步越多
// ============================
$riseData = ulggCacheRemember('index_rise_top5_v1', 600, function () use ($db) {
  $bpRiseTop5 = [];
  $qpRiseTop5 = [];

  try {
    $sql_bp_rise_top5 = "
      SELECT
        cur.rank_num AS new_rank,
        prev.rank_num AS old_rank,
        (prev.rank_num - cur.rank_num) AS rise,
        cur.name,
        cur.bp AS new_bp,
        prev.bp AS old_bp,
        (cur.bp - prev.bp) AS bp_gain,
        cur.win_ranked,
        cur.lose_ranked
      FROM ranking_bp_TW cur
      INNER JOIN ranking_bp_TW prev
        ON cur.name = prev.name
      JOIN (
        SELECT ts
        FROM ranking_bp_TW
        GROUP BY ts
        HAVING COUNT(*) >= 50
        ORDER BY ts DESC
        LIMIT 1
      ) latest ON cur.ts = latest.ts
      JOIN (
        SELECT ts
        FROM ranking_bp_TW
        WHERE ts < (
          SELECT ts
          FROM ranking_bp_TW
          GROUP BY ts
          HAVING COUNT(*) >= 50
          ORDER BY ts DESC
          LIMIT 1
        )
        GROUP BY ts
        HAVING COUNT(*) >= 50
        ORDER BY ts DESC
        LIMIT 1
      ) prev_ts ON prev.ts = prev_ts.ts
      WHERE cur.rank_num < prev.rank_num
      ORDER BY rise DESC, bp_gain DESC, cur.rank_num ASC
      LIMIT 5
    ";
    $stmt = $db->prepare($sql_bp_rise_top5);
    $stmt->execute();
    $bpRiseTop5 = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log(sprintf(
      '[INDEX BP RISE TOP5 ERROR] type=%s code=%s',
      get_class($e),
      (string)$e->getCode()
    ));
  }

  try {
    $sql_qp_rise_top5 = "
      SELECT
        cur.rank_num AS new_rank,
        prev.rank_num AS old_rank,
        (prev.rank_num - cur.rank_num) AS rise,
        cur.name,
        cur.level,
        cur.qp AS new_qp,
        prev.qp AS old_qp,
        (cur.qp - prev.qp) AS qp_gain
      FROM ranking_qp_TW cur
      INNER JOIN ranking_qp_TW prev
        ON cur.name = prev.name
      WHERE cur.ts = (SELECT MAX(ts) FROM ranking_qp_TW)
        AND prev.ts = (
          SELECT MAX(ts)
          FROM ranking_qp_TW
          WHERE ts < (SELECT MAX(ts) FROM ranking_qp_TW)
        )
        AND cur.rank_num < prev.rank_num
      ORDER BY rise DESC, qp_gain DESC, cur.rank_num ASC
      LIMIT 5
    ";
    $stmt = $db->prepare($sql_qp_rise_top5);
    $stmt->execute();
    $qpRiseTop5 = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log(sprintf(
      '[INDEX QP RISE TOP5 ERROR] type=%s code=%s',
      get_class($e),
      (string)$e->getCode()
    ));
  }

  return [
    'bpRiseTop5' => $bpRiseTop5,
    'qpRiseTop5' => $qpRiseTop5,
  ];
});

$bpRiseTop5 = $riseData['bpRiseTop5'] ?? [];
$qpRiseTop5 = $riseData['qpRiseTop5'] ?? [];


if (!function_exists('homeStrongDeckTierByMetaScore')) {
  function homeStrongDeckTierByMetaScore(float $score, string $confidence): array
  {
    if ($confidence === '觀察中') {
      return ['觀察', 'tier-c'];
    }

    if ($score >= 85) return ['S', 'tier-s'];
    if ($score >= 72) return ['A', 'tier-a'];
    if ($score >= 58) return ['B', 'tier-b'];
    return ['C', 'tier-c'];
  }
}

if (!function_exists('homeStrongDeckCostTitle')) {
  function homeStrongDeckCostTitle(string $costRange): string
  {
    return match ($costRange) {
      '50-59' => '50–59 COST',
      '60-69' => '60–69 COST',
      '70-80' => '70–80 COST',
      '90+'   => '90+ COST',
      default => $costRange,
    };
  }
}

if (!function_exists('homeStrongDeckFetchChars')) {
  function homeStrongDeckFetchChars(PDO $db, array $ids): array
  {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
      SELECT id, ico, name, level, cost
      FROM unlight
      WHERE id IN ($placeholders)
    ");
    $stmt->execute($ids);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $map[(int)$row['id']] = $row;
    }

    $chars = [];
    foreach ($ids as $id) {
      $chars[] = $map[$id] ?? [
        'id' => $id,
        'ico' => '',
        'name' => '#' . $id,
        'level' => '',
        'cost' => null,
      ];
    }

    return $chars;
  }
}

$strongDeckSnapshotDays = 14;

$strongDeckSnapshots = ulggCacheRemember('index_strong_deck_snapshots_meta_v1_' . $strongDeckSnapshotDays, 3600, function () use ($db, $strongDeckSnapshotDays) {
  $costOrder = ['50-59', '60-69', '70-80', '90+'];
  $snapshots = [];

  try {
    $sql = "
      WITH deck_stat AS (
        SELECT
          CASE
            WHEN a.cost BETWEEN 50 AND 59 THEN '50-59'
            WHEN a.cost BETWEEN 60 AND 69 THEN '60-69'
            WHEN a.cost BETWEEN 70 AND 80 THEN '70-80'
            WHEN a.cost >= 90 THEN '90+'
          END AS cost_range,
          a.team_key,
          CAST(SUBSTRING_INDEX(GROUP_CONCAT(a.leader_id ORDER BY a.update_time DESC), ',', 1) AS UNSIGNED) AS leader_id,
          CAST(SUBSTRING_INDEX(GROUP_CONCAT(a.back1_id ORDER BY a.update_time DESC), ',', 1) AS UNSIGNED) AS back1_id,
          CAST(SUBSTRING_INDEX(GROUP_CONCAT(a.back2_id ORDER BY a.update_time DESC), ',', 1) AS UNSIGNED) AS back2_id,
          ROUND(AVG(a.cost), 1) AS avg_cost,
          COUNT(*) AS matches,
          SUM(a.is_win = 1) AS wins,
          SUM(a.is_win = 0) AS losses,
          ROUND(SUM(a.is_win = 1) / COUNT(*) * 100, 1) AS raw_win_rate,
          ROUND(AVG(a.player_bp), 1) AS avg_player_bp,
          ROUND(AVG(o.player_bp), 1) AS avg_opponent_bp,
          ROUND(AVG(CASE
            WHEN a.player_bp IS NOT NULL AND o.player_bp IS NOT NULL THEN (a.player_bp + o.player_bp) / 2
            WHEN a.player_bp IS NOT NULL THEN a.player_bp
            WHEN o.player_bp IS NOT NULL THEN o.player_bp
            ELSE NULL
          END), 0) AS avg_match_bp,
          MIN(a.update_time) AS first_seen,
          MAX(a.update_time) AS last_seen
        FROM arena_player_match_result a
        JOIN arena_player_match_result o
          ON o.match_id = a.match_id
         AND o.side <> a.side
        WHERE a.update_time >= NOW() - INTERVAL {$strongDeckSnapshotDays} DAY
          AND a.cost IS NOT NULL
          AND a.cost >= 50
          AND a.is_win IS NOT NULL
        GROUP BY cost_range, a.team_key
        HAVING cost_range IS NOT NULL
      ),
      scored_base AS (
        SELECT
          *,
          ROUND((wins + 4) / (matches + 8) * 100, 1) AS adjusted_win_rate
        FROM deck_stat
        WHERE matches >= 3
          AND raw_win_rate >= 50
      ),
      scored AS (
        SELECT
          *,
          GREATEST(0, LEAST(100, (adjusted_win_rate - 50) / 20 * 100)) AS win_score,
          GREATEST(0, LEAST(100, matches / 10 * 100)) AS sample_score,
          GREATEST(0, LEAST(100, LOG(matches + 1) / LOG(31) * 100)) AS usage_score,
          GREATEST(0, LEAST(100, (COALESCE(avg_match_bp, 1450) - 1450) / 250 * 100)) AS bp_score
        FROM scored_base
      )
      SELECT
        cost_range,
        team_key,
        leader_id,
        back1_id,
        back2_id,
        avg_cost,
        matches,
        wins,
        losses,
        raw_win_rate,
        adjusted_win_rate,
        avg_player_bp,
        avg_opponent_bp,
        avg_match_bp,
        ROUND(
          win_score * 0.50 +
          sample_score * 0.20 +
          usage_score * 0.15 +
          bp_score * 0.15
        , 1) AS meta_score,
        CASE
          WHEN matches >= 10 THEN '高資料'
          WHEN matches >= 5 THEN '一般'
          ELSE '觀察中'
        END AS confidence_label,
        first_seen,
        last_seen
      FROM scored
      ORDER BY
        CASE cost_range
          WHEN '50-59' THEN 1
          WHEN '60-69' THEN 2
          WHEN '70-80' THEN 3
          WHEN '90+' THEN 4
          ELSE 9
        END,
        CASE WHEN matches >= 5 THEN 0 ELSE 1 END,
        meta_score DESC,
        matches DESC,
        adjusted_win_rate DESC
    ";

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($costOrder as $costRange) {
      foreach ($rows as $row) {
        if (($row['cost_range'] ?? '') !== $costRange) {
          continue;
        }

        $ids = [
          (int)$row['leader_id'],
          (int)$row['back1_id'],
          (int)$row['back2_id'],
        ];

        $row['ids'] = $ids;
        $row['chars'] = homeStrongDeckFetchChars($db, $ids);
        $row['cost_title'] = homeStrongDeckCostTitle($costRange);
        $snapshots[] = $row;
        break;
      }
    }
  } catch (Throwable $e) {
    error_log(sprintf(
      '[INDEX STRONG DECK META SNAPSHOT ERROR] type=%s code=%s',
      get_class($e),
      (string)$e->getCode()
    ));
    return [];
  }

  return $snapshots;
});

?>
<div class="hero">
  <section class="home-section">
    <div class="home-section-head">
      <h2 class="section-title">🏆 強勢牌組快照</h2>
      <div class="home-section-subtitle">
        近期依 COST 區間挑選新版強度分最高的牌組；正式榜優先，樣本不足時顯示觀察。
      </div>
    </div>

    <div class="strong-deck-grid">
      <?php if (empty($strongDeckSnapshots)): ?>
        <div class="strong-deck-empty">
          目前樣本不足，暫無可顯示的強勢牌組。
        </div>
      <?php else: ?>
        <?php foreach ($strongDeckSnapshots as $team): ?>
          <?php
          [$tierText, $tierClass] = homeStrongDeckTierByMetaScore(
            (float)$team['meta_score'],
            (string)$team['confidence_label']
          );

          $chars = $team['chars'] ?? [];
          $ids = $team['ids'] ?? [];
          $url = '#';

          if (count($ids) >= 3) {
            $url = '/pages/team_analysis.php?id1=' . (int)$ids[0]
              . '&id2=' . (int)$ids[1]
              . '&id3=' . (int)$ids[2];
          }
          ?>

          <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" class="strong-deck-card <?= htmlspecialchars($tierClass, ENT_QUOTES) ?>">
            <div class="strong-deck-top">
              <span class="strong-deck-zone">
                <?= htmlspecialchars($team['cost_title'] ?? homeStrongDeckCostTitle((string)$team['cost_range']), ENT_QUOTES) ?>
              </span>
              <span class="tier-badge <?= htmlspecialchars($tierClass, ENT_QUOTES) ?>">
                <?= htmlspecialchars($tierText, ENT_QUOTES) ?>
              </span>
            </div>

            <div class="strong-deck-chars">
              <?php foreach ($chars as $i => $ch): ?>
                <?php
                $icoFile = !empty($ch['ico'])
                  ? IMG_BASE . $ch['ico']
                  : '/assets/favicon/android-chrome-192x192.png';
                ?>
                <div class="strong-char-card c<?= (int)$i ?>">
                  <img src="<?= htmlspecialchars($icoFile, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($ch['name'] ?? '', ENT_QUOTES) ?>">
                </div>
              <?php endforeach; ?>
            </div>

            <div class="strong-deck-name">
              <?= htmlspecialchars(implode(' / ', array_map(
                fn($c) => trim(($c['level'] ?? $c['lv'] ?? '') . ' ' . ($c['name'] ?? '')),
                $chars
              )), ENT_QUOTES) ?>
            </div>

            <div class="strong-deck-score">
              <span>綜合分 <strong><?= number_format((float)$team['meta_score'], 1) ?></strong></span>
              <span>勝率 <strong><?= number_format((float)$team['adjusted_win_rate'], 1) ?>%</strong></span>
            </div>

            <div class="strong-deck-meta-line">
              角色淨C <?= htmlspecialchars(
                      (string)(number_format($team['cost_sum'] ?? $team['avg_cost'] ,0)?? '-'),
                      ENT_QUOTES
                    ) ?>
              · 平均 BP <?= htmlspecialchars(
                        (string)($team['avg_match_bp'] ?? '-'),
                        ENT_QUOTES
                      ) ?>
            </div>


            <span class="strong-deck-confidence">
              <?= htmlspecialchars((string)$team['confidence_label'], ENT_QUOTES) ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <a href="/pages/strength_rank.php" class="home-more-link">查看完整強度排行 →</a>
  </section>
</div>

<!-- Hero -->
<div class="hero">




  <!-- System Announcements -->
  <h2 class="section-title">📢 系統公告 / 活動</h2>




  <div class="bulletin-board-2col"><!-- class="bulletin-board-2col" -->

    <!-- 左欄：Steam 官方公告 -->
    <div class="bulletin-col-left">

      <div class="bulletin-board">

        <?php
        $tagClassMap = [
          'MAINT_NOTICE' => 'tag-steam-maint',
          'UPCOMING'     => 'tag-steam-upcoming',
          'ISSUE'        => 'tag-steam-issue',
          'EVENT'        => 'tag-steam-event'
        ];

        foreach ($steamNews as $news):
          $cat = classifySteamCategory((string)($news['title'] ?? ''));
          $tagClass = $tagClassMap[$cat] ?? 'tag-steam-maint';
          $titleZh = localRewriteTitle((string)($news['title'] ?? ''));
          $newsSummary = !empty($news['summary'])
            ? (string)$news['summary']
            : '（摘要取得失敗）';
          $newsUrl = ulggSteamNewsUrl($news['url'] ?? null);
        ?>
          <div class="notice">
            <div class="notice-title">
              <span class="notice-tag-steam <?= htmlspecialchars($tagClass, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($titleZh, ENT_QUOTES, 'UTF-8') ?>
              </span>


            </div>

            <div class="notice-desc">
              <?= nl2br(htmlspecialchars($newsSummary, ENT_QUOTES, 'UTF-8')) ?>
            </div>

            <?php if ($newsUrl !== null): ?>
              <a href="<?= htmlspecialchars($newsUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="notice-link"
                target="_blank"
                rel="noopener noreferrer">
                查看完整公告 →
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

      </div>

    </div>



    <!-- 右欄：UL.GG 自家公告 -->
    <div class="bulletin-col-right">
      <div class="bulletin-board">

        <?php if (empty($ulggNotices)): ?>
          <div class="notice">
            <div class="notice-title">
              <span class="notice-tag-steam tag-steam-maint">
                目前沒有近期公告
              </span>
            </div>
            <div class="notice-desc">
              近 2 個月內暫無新的公告內容。
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($ulggNotices as $n): ?>
            <?php
            $class = $tagClassMap[$n["cat"]] ?? 'tag-steam-maint';
            $noticeDateRaw = $n['start_at'] ?: $n['created_at'];
            $noticeDate = $noticeDateRaw ? date('Y/m/d', strtotime($noticeDateRaw)) : '';
            $noticeLink = ulggNoticeUrl($n['link'] ?? null);
            ?>
            <div class="notice">
              <div class="notice-title">


                <span class="notice-tag-steam <?= htmlspecialchars($class, ENT_QUOTES) ?>">
                  <?= htmlspecialchars($n["title"], ENT_QUOTES) ?>
                </span>
              </div>

              <div class="notice-desc">
                <?= htmlspecialchars($n["desc"], ENT_QUOTES) ?>
              </div>

              <?php if ($noticeLink !== null): ?>
                <a href="<?= htmlspecialchars($noticeLink, ENT_QUOTES, 'UTF-8') ?>" class="notice-link" target="_blank" rel="noopener noreferrer">
                  詳細內容 →
                </a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>


  </div>


  <!-- 保持按鈕列不變 -->
  <div class="hero-buttons">
    <a href="bp_rank.php" class="btn-gothic">BP 排行</a>
    <a href="qp_rank.php" class="btn-gothic">QP 排行</a>
    <a href="ranking_team.php" class="btn-gothic">對戰組合</a>
    <a href="calculator.php" class="btn-gothic">計算C</a>
  </div>



</div>




<div class="hero">
  <!-- ============================
  今日看板：BP / QP 躍升排行
============================ -->
  <section class="home-section">
    <div class="home-section-head">
      <h2 class="section-title">🔥 今日看板</h2>
      <div class="home-section-subtitle">依最新排名快照與前一次快照比較，顯示今日變動較大的玩家。</div>
    </div>

    <div class="home-board-grid">
      <div class="home-board-card">
        <div class="home-board-card-head">
          <div>
            <div class="deck-title">BP 躍升排行</div>
            <div class="deck-subtitle">今日排名進步最多的玩家</div>
          </div>
          <span class="board-badge">BP</span>
        </div>

        <?php if (empty($bpRiseTop5)): ?>
          <div class="deck-empty">尚無排名變化</div>
        <?php else: ?>
          <?php foreach ($bpRiseTop5 as $idx => $row): ?>
            <?php
            $bpPlayerUrl = '/pages/bp_player.php?server=TW&name=' . urlencode($row['name']);
            ?>
            <a class="deck-row deck-row-link" href="<?= htmlspecialchars($bpPlayerUrl, ENT_QUOTES) ?>">
              <div class="deck-left">
                <span class="deck-no"><?= $idx + 1 ?></span>
                <span class="deck-rank up">▲<?= (int)$row['rise'] ?></span>
                <span class="deck-name"><?= htmlspecialchars($row['name'], ENT_QUOTES) ?></span>
              </div>
              <div class="deck-right deck-right-stack">
                <span>#<?= (int)$row['old_rank'] ?> → #<?= (int)$row['new_rank'] ?></span>
                <span class="deck-delta">
                  BP <?= ((int)$row['bp_gain'] >= 0 ? '+' : '') . number_format((int)$row['bp_gain']) ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <a href="/pages/bp_rank.php" class="deck-link">查看完整 BP →</a>
      </div>

      <div class="home-board-card">
        <div class="home-board-card-head">
          <div>
            <div class="deck-title">QP 躍升排行</div>
            <div class="deck-subtitle">今日排名進步最多的玩家</div>
          </div>
          <span class="board-badge">QP</span>
        </div>

        <?php if (empty($qpRiseTop5)): ?>
          <div class="deck-empty">尚無排名變化</div>
        <?php else: ?>
          <?php foreach ($qpRiseTop5 as $idx => $row): ?>
            <?php
            $qpPlayerUrl = '/pages/qp_player.php?server=TW&name=' . urlencode($row['name']);
            ?>
            <a class="deck-row deck-row-link" href="<?= htmlspecialchars($qpPlayerUrl, ENT_QUOTES) ?>">
              <div class="deck-left">
                <span class="deck-no"><?= $idx + 1 ?></span>
                <span class="deck-rank up">▲<?= (int)$row['rise'] ?></span>
                <span class="deck-name"><?= htmlspecialchars($row['name'], ENT_QUOTES) ?></span>
              </div>
              <div class="deck-right deck-right-stack">
                <span>#<?= (int)$row['old_rank'] ?> → #<?= (int)$row['new_rank'] ?></span>
                <span class="deck-delta">
                  QP <?= ((int)$row['qp_gain'] >= 0 ? '+' : '') . number_format((int)$row['qp_gain']) ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <a href="/pages/qp_rank.php" class="deck-link">查看完整 QP →</a>
      </div>
    </div>
  </section>

</div>
<div class="hero">

  <!-- ============================
  今日熱門搜尋 / 熱門角色
============================ -->
  <section class="home-section">
    <div class="home-section-head">
      <h2 class="section-title">📊 今日熱門搜尋</h2>
      <div class="home-section-subtitle">統計今日使用者搜尋與角色瀏覽熱度。</div>
    </div>

    <div class="home-board-grid">
      <div class="home-board-card">
        <div class="home-board-card-head">
          <div>
            <div class="deck-title">🔍 今日熱門查詢趨勢</div>
            <div class="deck-subtitle">依今日站內查詢次數統計，僅顯示彙整結果</div>
          </div>
          <span class="board-badge">Search</span>
        </div>

        <?php if (empty($topSearch)): ?>
          <div class="deck-empty">今日無搜尋紀錄</div>
        <?php else: ?>
          <?php foreach ($topSearch as $idx => $s): ?>
            <?php
            $keyword = trim((string)$s['search_term']);
            $latestPage = $s['latest_page'] ?? null;
            $url = ulggSearchTrendUrl($keyword, $latestPage);
            ?>
            <a class="deck-row deck-row-link" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
              <div class="deck-left">
                <span class="deck-no"><?= $idx + 1 ?></span>
                <span class="deck-chip keyword-link">
                  <?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>
                </span>
              </div>
              <div class="deck-right"><?= (int)$s['cnt'] ?> 次</div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="home-board-card">
        <div class="home-board-card-head">
          <div>
            <div class="deck-title">🎴 熱門角色</div>
            <div class="deck-subtitle">今日被查看最多的角色</div>
          </div>
          <span class="board-badge">Character</span>
        </div>

        <?php if (empty($topChar)): ?>
          <div class="deck-empty">今日尚無角色瀏覽</div>
        <?php else: ?>
          <?php foreach ($topChar as $idx => $c): ?>
            <?php
            $charId = (int)$c['char_id'];
            $charName = $c['character_name'];
            $level = $c['level'];
            $url = '/pages/analysis_character_card.php?char_id=' . $charId;
            ?>
            <div class="deck-row">
              <div class="deck-left">
                <span class="deck-no"><?= $idx + 1 ?></span>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="deck-chip char-link">
                  <?= htmlspecialchars($level, ENT_QUOTES) ?><?= htmlspecialchars($charName, ENT_QUOTES) ?>
                </a>
              </div>
              <div class="deck-right"><?= (int)$c['cnt'] ?> 次</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>

<!-- <div class="hero">
  <div class="activity-summary-grid">
    <div class="activity-summary-card">
      <div class="activity-summary-label">近 30 日瀏覽</div>
      <div class="activity-summary-value"><?= number_format($totalVisits30) ?></div>
    </div>
    <div class="activity-summary-card">
      <div class="activity-summary-label">近 30 日活躍訪客</div>
      <div class="activity-summary-value"><?= number_format($totalUnique30) ?></div>
    </div>
    <div class="activity-summary-card">
      <div class="activity-summary-label">近 30 日對戰</div>
      <div class="activity-summary-value"><?= number_format($totalBattles30) ?></div>
    </div>
    <div class="activity-summary-card">
      <div class="activity-summary-label">本週對戰較上週</div>
      <div class="activity-summary-value">
        <?= $battleDiffRate === null ? '—' : (($battleDiffRate >= 0 ? '+' : '') . number_format($battleDiffRate, 1) . '%') ?>
      </div>
    </div>
  </div>
  <section class="activity-trend-section">
    <h2 class="section-title">📈 活躍趨勢</h2>

    <div class="activity-trend-grid">
      <div class="activity-trend-card">
        <div class="activity-trend-head">
          <div>
            <div class="activity-trend-title">網站關注趨勢</div>
            <div class="activity-trend-subtitle">近 30 日瀏覽次數與粗略訪客</div>
          </div>
        </div>
        <canvas id="visitTrendChart"></canvas>
      </div>

      <div class="activity-trend-card">
        <div class="activity-trend-head">
          <div>
            <div class="activity-trend-title">每日對戰場次</div>
            <div class="activity-trend-subtitle">近 30 日戰績資料統計</div>
          </div>
        </div>
        <canvas id="battleTrendChart"></canvas>
      </div>
    </div>
  </section>
</div> -->


<!-- Go To Top Button -->
<button id="backToTop" onclick="scrollToTop()">▲</button>
<!-- Go To Bottom Button -->
<button id="goToBottom" onclick="scrollToBottom()">▼</button>

<script>
  // 監聽滾動事件，決定是否顯示按鈕
  window.onscroll = function() {
    let topButton = document.getElementById("backToTop");
    let bottomButton = document.getElementById("goToBottom");
    let scrollTop = document.documentElement.scrollTop;
    let scrollHeight = document.documentElement.scrollHeight;
    let clientHeight = document.documentElement.clientHeight;

    if (scrollTop > 200) {
      topButton.style.display = "flex"; // 顯示回到頂部按鈕
    } else {
      topButton.style.display = "none"; // 隱藏按鈕
    }

    if (scrollTop + clientHeight < scrollHeight - 200) {
      bottomButton.style.display = "flex"; // 顯示滾到底部按鈕
    } else {
      bottomButton.style.display = "none"; // 隱藏按鈕
    }
  };

  // 點擊按鈕回到頂部
  function scrollToTop() {
    window.scrollTo({
      top: 0,
      behavior: "smooth" // 平滑滾動效果
    });
  }

  // 點擊按鈕滾到底部
  function scrollToBottom() {
    window.scrollTo({
      top: document.documentElement.scrollHeight,
      behavior: "smooth" // 平滑滾動效果
    });
  }
</script>
<!-- <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->
<!-- <script>
  const trendLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
  const visitData = <?= json_encode($visitData, JSON_UNESCAPED_UNICODE) ?>;
  const uniqueVisitorData = <?= json_encode($uniqueVisitorData, JSON_UNESCAPED_UNICODE) ?>;
  const battleData = <?= json_encode($battleData, JSON_UNESCAPED_UNICODE) ?>;

  const commonTrendOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        labels: {
          color: '#d8d5ef',
          boxWidth: 10,
          font: {
            size: 12
          }
        }
      },
      tooltip: {
        mode: 'index',
        intersect: false
      }
    },
    scales: {
      x: {
        ticks: {
          color: '#9ca0c8',
          maxRotation: 0,
          autoSkip: true,
          maxTicksLimit: 8
        },
        grid: {
          color: 'rgba(255,255,255,0.05)'
        }
      },
      y: {
        beginAtZero: true,
        ticks: {
          color: '#9ca0c8',
          precision: 0
        },
        grid: {
          color: 'rgba(255,255,255,0.06)'
        }
      }
    }
  };

  const visitCanvas = document.getElementById('visitTrendChart');
  if (visitCanvas) {
    new Chart(visitCanvas, {
      type: 'line',
      data: {
        labels: trendLabels,
        datasets: [{
            label: '瀏覽次數',
            data: visitData,
            borderColor: '#e8d19e',
            backgroundColor: 'rgba(232,209,158,0.12)',
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: 2
          },
          {
            label: '活躍訪客',
            data: uniqueVisitorData,
            borderColor: '#8cd4ff',
            backgroundColor: 'rgba(140,212,255,0.08)',
            borderWidth: 2,
            tension: 0.35,
            fill: false,
            pointRadius: 2
          }
        ]
      },
      options: commonTrendOptions
    });
  }

  const battleCanvas = document.getElementById('battleTrendChart');
  if (battleCanvas) {
    new Chart(battleCanvas, {
      type: 'bar',
      data: {
        labels: trendLabels,
        datasets: [{
          label: '對戰場次',
          data: battleData,
          backgroundColor: 'rgba(140,212,255,0.45)',
          borderColor: '#8cd4ff',
          borderWidth: 1
        }]
      },
      options: commonTrendOptions
    });
  }
</script> -->

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
