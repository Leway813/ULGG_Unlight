<?php

declare(strict_types=1);

// 先驗證管理員權限，避免未授權請求觸發資料庫連線。
require_once __DIR__ . '/admin/_admin_gate.php';

require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = '系統監控總覽';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = 'admin_dashboard';

date_default_timezone_set('Asia/Taipei');

function adminH(mixed $value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function adminFetchOne(PDO $pdo, string $sql, array $params = []): array
{
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    error_log('[admin_dashboard] ' . $e->getMessage());
    return [];
  }
}

function adminFetchAll(PDO $pdo, string $sql, array $params = []): array
{
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  } catch (Throwable $e) {
    error_log('[admin_dashboard] ' . $e->getMessage());
    return [];
  }
}

function adminNumber(mixed $value): string
{
  return number_format((float)($value ?? 0));
}

function adminMs(mixed $value): string
{
  if ($value === null || $value === '') {
    return '—';
  }

  return number_format((float)$value) . ' ms';
}

function adminPercent(float $value): string
{
  return number_format($value, 1) . '%';
}

function adminTimeAgo(?string $datetime): string
{
  if (!$datetime) {
    return '尚無資料';
  }

  $timestamp = strtotime($datetime);
  if ($timestamp === false) {
    return $datetime;
  }

  $seconds = max(0, time() - $timestamp);

  if ($seconds < 60) {
    return $seconds . ' 秒前';
  }

  if ($seconds < 3600) {
    return floor($seconds / 60) . ' 分鐘前';
  }

  if ($seconds < 86400) {
    return floor($seconds / 3600) . ' 小時前';
  }

  return floor($seconds / 86400) . ' 天前';
}

function adminWatcherClass(string $status): string
{
  return match ($status) {
    'running' => 'is-ok',
    'warning' => 'is-warning',
    'stopped' => 'is-danger',
    default => 'is-muted',
  };
}

function adminWatcherLabel(string $status): string
{
  return match ($status) {
    'running' => '正常',
    'warning' => '警告',
    'stopped' => '停止',
    default => '未知',
  };
}

/* =========================================================
 * 目前系統資源
 * ========================================================= */
$system = adminFetchOne(
  $pdo,
  "
        SELECT *
        FROM admin_system_metrics
        ORDER BY recorded_at DESC
        LIMIT 1
    "
);

$memoryPercent = 0.0;
if ((int)($system['memory_total_mb'] ?? 0) > 0) {
  $memoryPercent =
    ((float)$system['memory_used_mb'] / (float)$system['memory_total_mb']) * 100;
}

$diskPercent = 0.0;
if ((int)($system['disk_total_mb'] ?? 0) > 0) {
  $diskPercent =
    ((float)$system['disk_used_mb'] / (float)$system['disk_total_mb']) * 100;
}

/* =========================================================
 * 今日即時流量：使用原始 visitors，只查今日與近 15 分鐘
 * ========================================================= */
$todayTraffic = adminFetchOne(
  $pdo,
  "
        SELECT
            SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) AS human_pv,
            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) AS bot_pv,
            COUNT(
                DISTINCT CASE
                    WHEN is_bot = 0 THEN NULLIF(ip, '')
                END
            ) AS unique_visitors,
            COUNT(
                DISTINCT CASE
                    WHEN is_bot = 0
                     AND visited_at >= NOW() - INTERVAL 15 MINUTE
                    THEN NULLIF(ip, '')
                END
            ) AS online_15m,
            ROUND(
                AVG(
                    CASE
                        WHEN is_bot = 0 THEN response_ms
                    END
                )
            ) AS avg_response_ms,
            SUM(
                CASE
                    WHEN is_bot = 0
                     AND response_ms >= 1000
                    THEN 1 ELSE 0
                END
            ) AS slow_1000_count,
            SUM(
                CASE
                    WHEN is_bot = 0
                     AND response_ms >= 3000
                    THEN 1 ELSE 0
                END
            ) AS slow_3000_count
        FROM visitors
        WHERE visited_at >= CURDATE()
          AND visited_at < CURDATE() + INTERVAL 1 DAY
    "
);

$totalPv = (int)($todayTraffic['human_pv'] ?? 0) + (int)($todayTraffic['bot_pv'] ?? 0);
$botPercent = $totalPv > 0
  ? ((int)($todayTraffic['bot_pv'] ?? 0) / $totalPv) * 100
  : 0.0;

/* =========================================================
 * 最近 24 小時彙總圖表
 * ========================================================= */
$hourlyRows = adminFetchAll(
  $pdo,
  "
        SELECT
            stat_hour,
            SUM(human_pv) AS human_pv,
            SUM(bot_pv) AS bot_pv,
            SUM(unique_visitors) AS unique_visitors,
            ROUND(
                SUM(COALESCE(avg_response_ms, 0) * human_pv)
                / NULLIF(SUM(human_pv), 0)
            ) AS avg_response_ms,
            SUM(slow_1000_count) AS slow_1000_count,
            SUM(slow_3000_count) AS slow_3000_count
        FROM admin_traffic_hourly
        WHERE stat_hour >= NOW() - INTERVAL 24 HOUR
        GROUP BY stat_hour
        ORDER BY stat_hour
    "
);

$chartHours = [];
$chartHumanPv = [];
$chartVisitors = [];
$chartResponse = [];
$chartSlow1000 = [];

foreach ($hourlyRows as $row) {
  $chartHours[] = date('H:i', strtotime((string)$row['stat_hour']));
  $chartHumanPv[] = (int)($row['human_pv'] ?? 0);
  $chartVisitors[] = (int)($row['unique_visitors'] ?? 0);
  $chartResponse[] = $row['avg_response_ms'] !== null
    ? (int)$row['avg_response_ms']
    : null;
  $chartSlow1000[] = (int)($row['slow_1000_count'] ?? 0);
}

/* =========================================================
 * Watcher
 * ========================================================= */
$watchers = adminFetchAll(
  $pdo,
  "
        SELECT
            watcher_key,
            watcher_name,
            region,
            status,
            pid,
            last_heartbeat_at,
            last_data_at,
            processed_count,
            error_count,
            last_error,
            updated_at
        FROM admin_watcher_heartbeat
        ORDER BY
            FIELD(region, 'TW', 'JP', 'CR'),
            watcher_name
    "
);

$watcherRunning = 0;
$watcherWarning = 0;
$watcherStopped = 0;

foreach ($watchers as $watcher) {
  match ((string)$watcher['status']) {
    'running' => $watcherRunning++,
    'warning' => $watcherWarning++,
    'stopped' => $watcherStopped++,
    default => null,
  };
}

/* =========================================================
 * 最新錯誤
 * ========================================================= */
$errorSummary = adminFetchOne(
  $pdo,
  "
        SELECT
            COUNT(*) AS error_types,
            COALESCE(SUM(occurrence_count), 0) AS occurrence_count
        FROM admin_error_summary
        WHERE resolved_at IS NULL
          AND last_seen_at >= CURDATE()
    "
);

$latestErrors = adminFetchAll(
  $pdo,
  "
        SELECT
            id,
            error_level,
            message,
            file_path,
            line_no,
            page,
            first_seen_at,
            last_seen_at,
            occurrence_count
        FROM admin_error_summary
        WHERE resolved_at IS NULL
        ORDER BY last_seen_at DESC, occurrence_count DESC
        LIMIT 8
    "
);

/* =========================================================
 * 熱門／最慢頁面
 * ========================================================= */
$popularPages = adminFetchAll(
  $pdo,
  "
        SELECT
            page,
            SUM(human_pv) AS human_pv,
            SUM(bot_pv) AS bot_pv,
            ROUND(
                SUM(COALESCE(avg_response_ms, 0) * human_pv)
                / NULLIF(SUM(human_pv), 0)
            ) AS avg_response_ms,
            SUM(slow_1000_count) AS slow_count
        FROM admin_traffic_hourly
        WHERE stat_hour >= NOW() - INTERVAL 24 HOUR
        GROUP BY page
        ORDER BY human_pv DESC
        LIMIT 8
    "
);

$slowPages = adminFetchAll(
  $pdo,
  "
        SELECT
            page,
            SUM(human_pv) AS human_pv,
            ROUND(
                SUM(COALESCE(avg_response_ms, 0) * human_pv)
                / NULLIF(SUM(human_pv), 0)
            ) AS avg_response_ms,
            MAX(max_response_ms) AS max_response_ms,
            SUM(slow_1000_count) AS slow_1000_count,
            SUM(slow_3000_count) AS slow_3000_count
        FROM admin_traffic_hourly
        WHERE stat_hour >= NOW() - INTERVAL 24 HOUR
          AND human_pv > 0
        GROUP BY page
        HAVING avg_response_ms IS NOT NULL
        ORDER BY avg_response_ms DESC
        LIMIT 8
    "
);

/* =========================================================
 * 警告中心
 * ========================================================= */
$alerts = [];

if ($watcherStopped > 0) {
  $alerts[] = [
    'level' => 'danger',
    'title' => 'Watcher 已停止',
    'text' => $watcherStopped . ' 個資料來源目前為停止狀態。',
  ];
}

if ($watcherWarning > 0) {
  $alerts[] = [
    'level' => 'warning',
    'title' => 'Watcher 延遲',
    'text' => $watcherWarning . ' 個資料來源目前為警告狀態。',
  ];
}

if ((float)($todayTraffic['avg_response_ms'] ?? 0) >= 1000) {
  $alerts[] = [
    'level' => 'warning',
    'title' => '網站回應偏慢',
    'text' => '今日真人請求平均回應時間已超過 1 秒。',
  ];
}

if ((int)($todayTraffic['slow_3000_count'] ?? 0) > 0) {
  $alerts[] = [
    'level' => 'warning',
    'title' => '偵測到超慢請求',
    'text' => '今日已有 ' . adminNumber($todayTraffic['slow_3000_count']) . ' 次請求超過 3 秒。',
  ];
}

if ($diskPercent >= 85) {
  $alerts[] = [
    'level' => 'danger',
    'title' => '磁碟空間不足',
    'text' => '目前磁碟使用率為 ' . adminPercent($diskPercent) . '。',
  ];
} elseif ($diskPercent >= 75) {
  $alerts[] = [
    'level' => 'warning',
    'title' => '磁碟使用率偏高',
    'text' => '目前磁碟使用率為 ' . adminPercent($diskPercent) . '。',
  ];
}

if ((int)($errorSummary['error_types'] ?? 0) > 0) {
  $alerts[] = [
    'level' => 'warning',
    'title' => '今日仍有 PHP 錯誤',
    'text' => '目前共有 ' . adminNumber($errorSummary['error_types']) . ' 種未解決錯誤。',
  ];
}

if (!$alerts) {
  $alerts[] = [
    'level' => 'ok',
    'title' => '系統目前正常',
    'text' => '尚未偵測到需要立即處理的異常。',
  ];
}

ob_start();
?>

<style>
  .ul-admin-dashboard {
    --ad-bg: rgba(12, 13, 21, .72);
    --ad-panel: linear-gradient(180deg, rgba(26, 28, 42, .96), rgba(15, 17, 27, .96));
    --ad-panel-soft: rgba(28, 31, 47, .72);
    --ad-border: rgba(164, 174, 255, .16);
    --ad-border-strong: rgba(164, 174, 255, .30);
    --ad-text: #edf0fb;
    --ad-text-soft: #aeb7ca;
    --ad-text-muted: #778196;
    --ad-purple: #8f7cff;
    --ad-blue: #72a7ff;
    --ad-green: #58d68d;
    --ad-yellow: #f2c66d;
    --ad-red: #ff727d;

    padding: 14px 0 36px;
    color: var(--ad-text);
  }

  .ul-admin-dashboard * {
    box-sizing: border-box;
  }

  .admin-page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }

  .admin-page-title {
    margin: 0;
    color: #f4f1ff;
    font-size: 27px;
    font-weight: 800;
    letter-spacing: .03em;
    text-shadow:
      0 0 7px rgba(133, 117, 255, .55),
      0 0 20px rgba(92, 122, 255, .18);
  }

  .admin-page-subtitle {
    margin-top: 5px;
    color: var(--ad-text-soft);
    font-size: 13px;
  }

  .admin-updated-at {
    flex: 0 0 auto;
    padding: 8px 12px;
    border: 1px solid var(--ad-border);
    border-radius: 9px;
    color: #c8d0e3;
    background:
      linear-gradient(180deg, rgba(43, 47, 67, .88), rgba(25, 27, 41, .88));
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .04),
      0 8px 22px rgba(0, 0, 0, .18);
    font-size: 12px;
  }

  .admin-card-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 11px;
    margin-bottom: 14px;
  }

  .admin-stat-card,
  .admin-panel {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--ad-border);
    background: var(--ad-panel);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .025),
      0 10px 28px rgba(0, 0, 0, .26);
  }

  .admin-stat-card {
    min-height: 112px;
    padding: 15px;
    border-radius: 12px;
    transition:
      transform .18s ease,
      border-color .18s ease,
      box-shadow .18s ease;
  }

  .admin-stat-card:hover {
    transform: translateY(-2px);
    border-color: var(--ad-border-strong);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, .04),
      0 13px 32px rgba(0, 0, 0, .34),
      0 0 18px rgba(119, 106, 255, .08);
  }

  .admin-stat-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 3px;
    background: linear-gradient(180deg, var(--ad-purple), var(--ad-blue));
    opacity: .75;
  }

  .admin-stat-card::after {
    content: "";
    position: absolute;
    top: -28px;
    right: -24px;
    width: 92px;
    height: 92px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(132, 112, 255, .18), transparent 68%);
    pointer-events: none;
  }

  .admin-stat-label {
    color: #b7bfd2;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .03em;
  }

  .admin-stat-value {
    margin-top: 8px;
    color: #f6f7ff;
    font-size: 25px;
    line-height: 1.1;
    font-weight: 850;
    text-shadow: 0 0 14px rgba(113, 154, 255, .12);
  }

  .admin-stat-note {
    margin-top: 8px;
    color: var(--ad-text-muted);
    font-size: 12px;
  }

  .admin-layout-2 {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(340px, .85fr);
    gap: 14px;
    margin-bottom: 14px;
  }

  .admin-layout-equal {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 14px;
  }

  .admin-panel {
    min-width: 0;
    border-radius: 13px;
  }

  .admin-panel::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
      radial-gradient(circle at 85% 0%, rgba(126, 110, 255, .07), transparent 30%);
  }

  .admin-panel-head {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 51px;
    padding: 13px 16px;
    border-bottom: 1px solid rgba(164, 174, 255, .11);
    background: rgba(255, 255, 255, .012);
  }

  .admin-panel-title {
    margin: 0;
    color: #f0f2fb;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: .02em;
  }

  .admin-panel-note {
    color: var(--ad-text-muted);
    font-size: 12px;
  }

  .admin-panel-body {
    position: relative;
    z-index: 1;
    padding: 14px 16px;
  }

  .admin-chart {
    height: 310px;
  }

  .admin-mini-chart {
    height: 245px;
  }

  .admin-table-wrap {
    position: relative;
    z-index: 1;
    width: 100%;
    overflow-x: auto;
  }

  .admin-table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
    color: #dce1ef;
    font-size: 13px;
  }

  .admin-table th {
    padding: 10px 11px;
    border-bottom: 1px solid rgba(164, 174, 255, .14);
    background: rgba(255, 255, 255, .025);
    color: #939db2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .04em;
    text-align: left;
    white-space: nowrap;
  }

  .admin-table td {
    padding: 10px 11px;
    border-bottom: 1px solid rgba(255, 255, 255, .055);
    color: #d6dbea;
    vertical-align: middle;
  }

  .admin-table tbody tr {
    transition: background .15s ease;
  }

  .admin-table tbody tr:hover {
    background: rgba(123, 111, 255, .055);
  }

  .admin-table tr:last-child td {
    border-bottom: 0;
  }

  .admin-table .is-num {
    color: #eef1fb;
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }

  .admin-page-path {
    max-width: 300px;
    overflow: hidden;
    color: #cdd4e5;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .admin-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 9px;
    border: 1px solid currentColor;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
  }

  .admin-status::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 8px currentColor;
  }

  .admin-status.is-ok {
    color: var(--ad-green);
    background: rgba(52, 196, 116, .09);
    border-color: rgba(88, 214, 141, .28);
  }

  .admin-status.is-warning {
    color: var(--ad-yellow);
    background: rgba(236, 181, 74, .09);
    border-color: rgba(242, 198, 109, .28);
  }

  .admin-status.is-danger {
    color: var(--ad-red);
    background: rgba(255, 90, 103, .09);
    border-color: rgba(255, 114, 125, .30);
  }

  .admin-status.is-muted {
    color: #8b94a8;
    background: rgba(255, 255, 255, .04);
    border-color: rgba(255, 255, 255, .10);
  }

  .admin-region {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    padding: 3px 7px;
    border: 1px solid rgba(128, 153, 255, .20);
    border-radius: 6px;
    color: #b8c6ff;
    background: rgba(96, 117, 214, .11);
    font-size: 10px;
    font-weight: 850;
    letter-spacing: .04em;
  }

  .admin-progress-row {
    margin-bottom: 18px;
  }

  .admin-progress-row:last-child {
    margin-bottom: 0;
  }

  .admin-progress-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 7px;
    font-size: 13px;
  }

  .admin-progress-name {
    color: #aab3c6;
    font-weight: 700;
  }

  .admin-progress-value {
    color: #edf0f8;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
  }

  .admin-progress-track {
    height: 8px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .035);
    border-radius: 999px;
    background: rgba(255, 255, 255, .065);
  }

  .admin-progress-bar {
    height: 100%;
    min-width: 2px;
    border-radius: inherit;
    background: linear-gradient(90deg, #6a78e9, #70a8ff);
    box-shadow: 0 0 10px rgba(103, 150, 255, .28);
  }

  .admin-progress-bar.is-warning {
    background: linear-gradient(90deg, #b78032, #edc266);
    box-shadow: 0 0 10px rgba(237, 194, 102, .22);
  }

  .admin-progress-bar.is-danger {
    background: linear-gradient(90deg, #b94153, #ef6672);
    box-shadow: 0 0 11px rgba(239, 102, 114, .30);
  }

  .admin-alert-list {
    display: grid;
    gap: 9px;
  }

  .admin-alert {
    padding: 11px 12px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-left-width: 3px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .025);
  }

  .admin-alert.is-ok {
    border-left-color: var(--ad-green);
    background: rgba(56, 194, 118, .055);
  }

  .admin-alert.is-warning {
    border-left-color: var(--ad-yellow);
    background: rgba(221, 162, 53, .055);
  }

  .admin-alert.is-danger {
    border-left-color: var(--ad-red);
    background: rgba(238, 78, 92, .06);
  }

  .admin-alert-title {
    color: #e9edf7;
    font-size: 13px;
    font-weight: 800;
  }

  .admin-alert-text {
    margin-top: 4px;
    color: #969fb2;
    font-size: 12px;
    line-height: 1.55;
  }

  .admin-error-level {
    font-weight: 850;
  }

  .admin-error-level.is-warning {
    color: var(--ad-yellow);
  }

  .admin-error-level.is-notice {
    color: #82b4ff;
  }

  .admin-error-level.is-fatal,
  .admin-error-level.is-error {
    color: var(--ad-red);
  }

  .admin-error-message {
    max-width: 500px;
    color: #d6dbea;
    word-break: break-word;
  }

  .admin-empty {
    padding: 28px 16px;
    color: #737d90 !important;
    text-align: center;
  }

  /* Highcharts 深色介面修正 */
  .ul-admin-dashboard .highcharts-background {
    fill: transparent;
  }

  .ul-admin-dashboard .highcharts-axis-line,
  .ul-admin-dashboard .highcharts-tick,
  .ul-admin-dashboard .highcharts-grid-line {
    stroke: rgba(173, 184, 220, .11);
  }

  .ul-admin-dashboard .highcharts-axis-labels text,
  .ul-admin-dashboard .highcharts-legend-item text,
  .ul-admin-dashboard .highcharts-axis-title {
    fill: #8994aa !important;
    color: #8994aa !important;
  }

  .ul-admin-dashboard .highcharts-tooltip-box {
    fill: rgba(18, 20, 31, .97);
    stroke: rgba(148, 157, 255, .28);
  }

  .ul-admin-dashboard .highcharts-tooltip text {
    fill: #edf0fa !important;
  }

  @media (max-width: 1199px) {
    .admin-card-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  @media (max-width: 900px) {

    .admin-layout-2,
    .admin-layout-equal {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767px) {
    .ul-admin-dashboard {
      padding: 8px 0 24px;
    }

    .admin-page-head {
      display: block;
    }

    .admin-updated-at {
      display: inline-block;
      margin-top: 10px;
    }

    .admin-page-title {
      font-size: 22px;
    }

    .admin-card-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 9px;
    }

    .admin-stat-card {
      min-height: 104px;
      padding: 13px;
    }

    .admin-stat-value {
      font-size: 21px;
    }

    .admin-panel-head,
    .admin-panel-body {
      padding-left: 12px;
      padding-right: 12px;
    }

    .admin-chart {
      height: 265px;
    }

    .admin-mini-chart {
      height: 235px;
    }
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container ul-admin-dashboard">

      <div class="admin-page-head">
        <div>
          <div class="admin-page-subtitle">
            流量、效能、Watcher、系統資源與 PHP 錯誤集中監控
          </div>
        </div>

        <div class="admin-updated-at">
          系統資料：
          <?= adminH($system['recorded_at'] ?? '尚無資料') ?>
          （<?= adminH(adminTimeAgo($system['recorded_at'] ?? null)) ?>）
        </div>
      </div>

      <div class="admin-card-grid">
        <div class="admin-stat-card">
          <div class="admin-stat-label">今日真人 PV</div>
          <div class="admin-stat-value"><?= adminNumber($todayTraffic['human_pv'] ?? 0) ?></div>
          <div class="admin-stat-note">Bot 占比 <?= adminPercent($botPercent) ?></div>
        </div>

        <div class="admin-stat-card">
          <div class="admin-stat-label">今日訪客</div>
          <div class="admin-stat-value"><?= adminNumber($todayTraffic['unique_visitors'] ?? 0) ?></div>
          <div class="admin-stat-note">依真人 IP 去重</div>
        </div>

        <div class="admin-stat-card">
          <div class="admin-stat-label">近 15 分鐘在線</div>
          <div class="admin-stat-value"><?= adminNumber($todayTraffic['online_15m'] ?? 0) ?></div>
          <div class="admin-stat-note">最近活動真人訪客</div>
        </div>

        <div class="admin-stat-card">
          <div class="admin-stat-label">平均回應時間</div>
          <div class="admin-stat-value"><?= adminH(adminMs($todayTraffic['avg_response_ms'] ?? null)) ?></div>
          <div class="admin-stat-note">今日真人請求</div>
        </div>

        <div class="admin-stat-card">
          <div class="admin-stat-label">慢請求 (> 1 s)</div>
          <div class="admin-stat-value"><?= adminNumber($todayTraffic['slow_1000_count'] ?? 0) ?></div>
          <div class="admin-stat-note">
            超過 3 秒 <?= adminNumber($todayTraffic['slow_3000_count'] ?? 0) ?> 次
          </div>
        </div>

        <div class="admin-stat-card">
          <div class="admin-stat-label">Watcher 狀態</div>
          <div class="admin-stat-value">
            <?= adminNumber($watcherRunning) ?>/<?= adminNumber(count($watchers)) ?>
          </div>
          <div class="admin-stat-note">
            警告 <?= adminNumber($watcherWarning) ?>・停止 <?= adminNumber($watcherStopped) ?>
          </div>
        </div>
      </div>

      <div class="admin-layout-2">
        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">最近 24 小時流量</h2>
            <span class="admin-panel-note">真人 PV／訪客</span>
          </div>
          <div class="admin-panel-body">
            <div id="adminTrafficChart" class="admin-chart"></div>
          </div>
        </div>

        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">系統資源</h2>
            <span class="admin-panel-note">
              <?= adminH(adminTimeAgo($system['recorded_at'] ?? null)) ?>
            </span>
          </div>

          <div class="admin-panel-body">
            <div class="admin-progress-row">
              <div class="admin-progress-head">
                <span class="admin-progress-name">記憶體</span>
                <span class="admin-progress-value">
                  <?= adminNumber($system['memory_used_mb'] ?? 0) ?>
                  /
                  <?= adminNumber($system['memory_total_mb'] ?? 0) ?> MB
                  ・<?= adminPercent($memoryPercent) ?>
                </span>
              </div>
              <div class="admin-progress-track">
                <div
                  class="admin-progress-bar <?= $memoryPercent >= 85 ? 'is-danger' : ($memoryPercent >= 70 ? 'is-warning' : '') ?>"
                  style="width:<?= min(100, max(0, $memoryPercent)) ?>%;">
                </div>
              </div>
            </div>

            <div class="admin-progress-row">
              <div class="admin-progress-head">
                <span class="admin-progress-name">磁碟</span>
                <span class="admin-progress-value">
                  <?= number_format(((float)($system['disk_used_mb'] ?? 0)) / 1024, 1) ?>
                  /
                  <?= number_format(((float)($system['disk_total_mb'] ?? 0)) / 1024, 1) ?> GB
                  ・<?= adminPercent($diskPercent) ?>
                </span>
              </div>
              <div class="admin-progress-track">
                <div
                  class="admin-progress-bar <?= $diskPercent >= 85 ? 'is-danger' : ($diskPercent >= 75 ? 'is-warning' : '') ?>"
                  style="width:<?= min(100, max(0, $diskPercent)) ?>%;">
                </div>
              </div>
            </div>

            <table class="admin-table">
              <tbody>
                <tr>
                  <td>Load Average</td>
                  <td class="is-num">
                    <?= adminH($system['load_1'] ?? '—') ?> /
                    <?= adminH($system['load_5'] ?? '—') ?> /
                    <?= adminH($system['load_15'] ?? '—') ?>
                  </td>
                </tr>
                <tr>
                  <td>Swap 使用量</td>
                  <td class="is-num"><?= adminNumber($system['swap_used_mb'] ?? 0) ?> MB</td>
                </tr>
                <tr>
                  <td>MySQL 連線</td>
                  <td class="is-num"><?= adminNumber($system['mysql_connections'] ?? 0) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="admin-layout-2">
        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">Watcher／資料來源</h2>
            <span class="admin-panel-note">每分鐘更新</span>
          </div>

          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>區域</th>
                  <th>來源</th>
                  <th>狀態</th>
                  <th>資料時間</th>
                  <th class="is-num">筆數</th>
                  <th>心跳</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$watchers): ?>
                  <tr>
                    <td colspan="6" class="admin-empty">尚無 Watcher 資料</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($watchers as $watcher): ?>
                    <tr>
                      <td>
                        <span class="admin-region"><?= adminH($watcher['region'] ?? '—') ?></span>
                      </td>
                      <td>
                        <strong><?= adminH($watcher['watcher_name']) ?></strong>
                        <?php if (!empty($watcher['last_error'])): ?>
                          <div class="admin-panel-note"><?= adminH($watcher['last_error']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="admin-status <?= adminWatcherClass((string)$watcher['status']) ?>">
                          <?= adminWatcherLabel((string)$watcher['status']) ?>
                        </span>
                      </td>
                      <td>
                        <?= adminH(adminTimeAgo($watcher['last_data_at'] ?? null)) ?>
                      </td>
                      <td class="is-num">
                        <?php if (str_starts_with((string)$watcher['watcher_key'], 'ranking_')): ?>
                          —
                        <?php else: ?>
                          <?= adminNumber($watcher['processed_count'] ?? 0) ?>
                        <?php endif; ?>
                      </td>
                      <td><?= adminH(adminTimeAgo($watcher['last_heartbeat_at'] ?? null)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">警告中心</h2>
            <span class="admin-panel-note"><?= count($alerts) ?> 項</span>
          </div>
          <div class="admin-panel-body">
            <div class="admin-alert-list">
              <?php foreach ($alerts as $alert): ?>
                <div class="admin-alert is-<?= adminH($alert['level']) ?>">
                  <div class="admin-alert-title"><?= adminH($alert['title']) ?></div>
                  <div class="admin-alert-text"><?= adminH($alert['text']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-layout-equal">
        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">熱門頁面</h2>
            <span class="admin-panel-note">最近 24 小時</span>
          </div>

          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>頁面</th>
                  <th class="is-num">真人 PV</th>
                  <th class="is-num">Bot</th>
                  <th class="is-num">平均</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$popularPages): ?>
                  <tr>
                    <td colspan="4" class="admin-empty">尚無彙總資料</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($popularPages as $page): ?>
                    <tr>
                      <td>
                        <div class="admin-page-path" title="<?= adminH($page['page']) ?>">
                          <?= adminH($page['page']) ?>
                        </div>
                      </td>
                      <td class="is-num"><?= adminNumber($page['human_pv']) ?></td>
                      <td class="is-num"><?= adminNumber($page['bot_pv']) ?></td>
                      <td class="is-num"><?= adminH(adminMs($page['avg_response_ms'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">最慢頁面</h2>
            <span class="admin-panel-note">最近 24 小時</span>
          </div>

          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>頁面</th>
                  <th class="is-num">平均</th>
                  <th class="is-num">最慢</th>
                  <th class="is-num">&gt; 3 秒</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$slowPages): ?>
                  <tr>
                    <td colspan="4" class="admin-empty">尚無效能資料</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($slowPages as $page): ?>
                    <tr>
                      <td>
                        <div class="admin-page-path" title="<?= adminH($page['page']) ?>">
                          <?= adminH($page['page']) ?>
                        </div>
                      </td>
                      <td class="is-num"><?= adminH(adminMs($page['avg_response_ms'])) ?></td>
                      <td class="is-num"><?= adminH(adminMs($page['max_response_ms'])) ?></td>
                      <td class="is-num"><?= adminNumber($page['slow_3000_count']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="admin-layout-2">


        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">最新 PHP 錯誤</h2>
            <span class="admin-panel-note">
              今日 <?= adminNumber($errorSummary['error_types'] ?? 0) ?> 種
            </span>
          </div>

          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>等級</th>
                  <th>錯誤</th>
                  <th class="is-num">次數</th>
                  <th>最後發生</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$latestErrors): ?>
                  <tr>
                    <td colspan="4" class="admin-empty">目前沒有錯誤資料</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($latestErrors as $error): ?>
                    <?php $levelClass = strtolower((string)$error['error_level']); ?>
                    <tr>
                      <td>
                        <span class="admin-error-level is-<?= adminH($levelClass) ?>">
                          <?= adminH($error['error_level']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="admin-error-message">
                          <?= adminH($error['message']) ?>
                        </div>
                        <div class="admin-panel-note">
                          <?= adminH($error['page'] ?? basename((string)$error['file_path'])) ?>
                          <?php if (!empty($error['line_no'])): ?>
                            :<?= adminNumber($error['line_no']) ?>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="is-num"><?= adminNumber($error['occurrence_count']) ?></td>
                      <td><?= adminH(adminTimeAgo($error['last_seen_at'] ?? null)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="admin-panel">
          <div class="admin-panel-head">
            <h2 class="admin-panel-title">最近 24 小時效能</h2>
            <span class="admin-panel-note">平均回應時間／慢請求</span>
          </div>
          <div class="admin-panel-body">
            <div id="adminPerformanceChart" class="admin-mini-chart"></div>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<script src="/highcharts/highcharts.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof Highcharts === 'undefined') {
      return;
    }

    const hours = <?= json_encode($chartHours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const humanPv = <?= json_encode($chartHumanPv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const visitors = <?= json_encode($chartVisitors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const responseMs = <?= json_encode($chartResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const slow1000 = <?= json_encode($chartSlow1000, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    Highcharts.chart('adminTrafficChart', {
      chart: {
        type: 'spline',
        backgroundColor: 'transparent',
        style: {
          fontFamily: 'Inter, "Noto Sans TC", "Microsoft JhengHei", sans-serif'
        }
      },
      colors: ['#8f7cff', '#72a7ff'],
      plotOptions: {
        series: {
          lineWidth: 2,
          marker: {
            enabled: true,
            radius: 3
          }
        }
      },
      title: {
        text: null
      },
      credits: {
        enabled: false
      },
      exporting: {
        enabled: false
      },
      xAxis: {
        categories: hours,
        tickInterval: Math.max(1, Math.ceil(hours.length / 8)),
        lineColor: 'rgba(173,184,220,.14)',
        tickColor: 'rgba(173,184,220,.14)',
        labels: {
          style: {
            color: '#8994aa'
          }
        }
      },
      yAxis: {
        min: 0,
        title: {
          text: null
        },
        allowDecimals: false,
        gridLineColor: 'rgba(173,184,220,.10)',
        labels: {
          style: {
            color: '#8994aa'
          }
        }
      },
      tooltip: {
        shared: true,
        backgroundColor: 'rgba(18,20,31,.97)',
        borderColor: 'rgba(148,157,255,.28)',
        style: {
          color: '#edf0fa'
        }
      },
      legend: {
        align: 'center',
        verticalAlign: 'bottom',
        itemStyle: {
          color: '#aeb7ca',
          fontWeight: '600'
        },
        itemHoverStyle: {
          color: '#ffffff'
        }
      },
      series: [{
          name: '真人 PV',
          data: humanPv
        },
        {
          name: '訪客',
          data: visitors
        }
      ]
    });

    Highcharts.chart('adminPerformanceChart', {
      chart: {
        backgroundColor: 'transparent',
        style: {
          fontFamily: 'Inter, "Noto Sans TC", "Microsoft JhengHei", sans-serif'
        }
      },
      colors: ['#72a7ff', '#8f7cff'],
      plotOptions: {
        spline: {
          lineWidth: 2,
          marker: {
            enabled: true,
            radius: 3
          }
        },
        column: {
          borderWidth: 0,
          borderRadius: 3
        }
      },
      title: {
        text: null
      },
      credits: {
        enabled: false
      },
      exporting: {
        enabled: false
      },
      xAxis: {
        categories: hours,
        tickInterval: Math.max(1, Math.ceil(hours.length / 8)),
        lineColor: 'rgba(173,184,220,.14)',
        tickColor: 'rgba(173,184,220,.14)',
        labels: {
          style: {
            color: '#8994aa'
          }
        }
      },
      yAxis: [{
          min: 0,
          title: {
            text: 'ms',
            style: {
              color: '#8994aa'
            }
          },
          gridLineColor: 'rgba(173,184,220,.10)',
          labels: {
            style: {
              color: '#8994aa'
            }
          }
        },
        {
          min: 0,
          opposite: true,
          allowDecimals: false,
          title: {
            text: '次數',
            style: {
              color: '#8994aa'
            }
          },
          gridLineWidth: 0,
          labels: {
            style: {
              color: '#8994aa'
            }
          }
        }
      ],
      tooltip: {
        shared: true,
        backgroundColor: 'rgba(18,20,31,.97)',
        borderColor: 'rgba(148,157,255,.28)',
        style: {
          color: '#edf0fa'
        }
      },
      series: [{
          type: 'spline',
          name: '平均回應',
          data: responseMs,
          tooltip: {
            valueSuffix: ' ms'
          }
        },
        {
          type: 'column',
          name: '超過 1 秒',
          data: slow1000,
          yAxis: 1
        }
      ]
    });
  });
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
