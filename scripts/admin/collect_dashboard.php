<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/../../config.php';

/**
 * 使用方式：
 *
 * php collect_dashboard.php metrics
 * php collect_dashboard.php traffic
 * php collect_dashboard.php watcher
 * php collect_dashboard.php errors
 * php collect_dashboard.php all
 */

$task = $argv[1] ?? 'all';

$allowedTasks = [
  'metrics',
  'traffic',
  'watcher',
  'errors',
  'all',
];

if (!in_array($task, $allowedTasks, true)) {
  fwrite(STDERR, "Unknown task: {$task}\n");
  exit(1);
}

function adminLog(string $message): void
{
  echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function adminReadFirstLine(string $path): ?string
{
  if (!is_file($path) || !is_readable($path)) {
    return null;
  }

  $handle = fopen($path, 'rb');

  if ($handle === false) {
    return null;
  }

  $line = fgets($handle);
  fclose($handle);

  return $line !== false ? trim($line) : null;
}

function adminRunCommand(string $command): string
{
  $output = shell_exec($command . ' 2>/dev/null');

  return is_string($output) ? trim($output) : '';
}

/**
 * 系統資源
 */
function collectSystemMetrics(PDO $db): void
{
  $load = sys_getloadavg();

  $load1  = isset($load[0]) ? round((float)$load[0], 2) : null;
  $load5  = isset($load[1]) ? round((float)$load[1], 2) : null;
  $load15 = isset($load[2]) ? round((float)$load[2], 2) : null;

  $memoryTotalKb = 0;
  $memoryAvailableKb = 0;
  $swapTotalKb = 0;
  $swapFreeKb = 0;

  $memInfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES);

  if (is_array($memInfo)) {
    foreach ($memInfo as $line) {
      if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
        $memoryTotalKb = (int)$m[1];
      } elseif (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) {
        $memoryAvailableKb = (int)$m[1];
      } elseif (preg_match('/^SwapTotal:\s+(\d+)/', $line, $m)) {
        $swapTotalKb = (int)$m[1];
      } elseif (preg_match('/^SwapFree:\s+(\d+)/', $line, $m)) {
        $swapFreeKb = (int)$m[1];
      }
    }
  }

  $memoryTotalMb = (int)round($memoryTotalKb / 1024);
  $memoryUsedMb = (int)round(
    max(0, $memoryTotalKb - $memoryAvailableKb) / 1024
  );
  $swapUsedMb = (int)round(
    max(0, $swapTotalKb - $swapFreeKb) / 1024
  );

  $diskTotalBytes = @disk_total_space('/');
  $diskFreeBytes = @disk_free_space('/');

  $diskTotalMb = is_numeric($diskTotalBytes)
    ? (int)round((float)$diskTotalBytes / 1024 / 1024)
    : null;

  $diskUsedMb = (
    is_numeric($diskTotalBytes) &&
    is_numeric($diskFreeBytes)
  )
    ? (int)round(
      ((float)$diskTotalBytes - (float)$diskFreeBytes)
        / 1024
        / 1024
    )
    : null;

  $mysqlConnections = null;

  try {
    $stmt = $db->query("SHOW STATUS LIKE 'Threads_connected'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $mysqlConnections = (int)($row['Value'] ?? 0);
    }
  } catch (Throwable $e) {
    adminLog('MySQL connections 取得失敗：' . $e->getMessage());
  }

  $sql = "
        INSERT INTO admin_system_metrics
        (
            recorded_at,
            load_1,
            load_5,
            load_15,
            memory_used_mb,
            memory_total_mb,
            swap_used_mb,
            disk_used_mb,
            disk_total_mb,
            mysql_connections
        )
        VALUES
        (
            NOW(),
            :load_1,
            :load_5,
            :load_15,
            :memory_used_mb,
            :memory_total_mb,
            :swap_used_mb,
            :disk_used_mb,
            :disk_total_mb,
            :mysql_connections
        )
    ";

  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':load_1' => $load1,
    ':load_5' => $load5,
    ':load_15' => $load15,
    ':memory_used_mb' => $memoryUsedMb,
    ':memory_total_mb' => $memoryTotalMb,
    ':swap_used_mb' => $swapUsedMb,
    ':disk_used_mb' => $diskUsedMb,
    ':disk_total_mb' => $diskTotalMb,
    ':mysql_connections' => $mysqlConnections,
  ]);

  adminLog('系統資源採集完成');
}

/**
 * 每小時流量彙總
 *
 * 每次重新計算上一個完整小時，使用 REPLACE 模式，
 * 不會因 Cron 重跑而重複累加。
 */
function collectHourlyTraffic(PDO $db): void
{
  $end = new DateTimeImmutable('now');
  $end = $end->setTime(
    (int)$end->format('H'),
    0,
    0
  );

  $start = $end->modify('-1 hour');

  $startText = $start->format('Y-m-d H:i:s');
  $endText = $end->format('Y-m-d H:i:s');

  $sql = "
        INSERT INTO admin_traffic_hourly
        (
            stat_hour,
            page,
            human_pv,
            bot_pv,
            unique_visitors,
            sessions,
            avg_response_ms,
            max_response_ms,
            slow_1000_count,
            slow_3000_count
        )
        SELECT
            :stat_hour,
            COALESCE(NULLIF(page, ''), '(unknown)') AS page,
            SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END),
            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END),
            COUNT(
                DISTINCT CASE
                    WHEN is_bot = 0 THEN NULLIF(ip, '')
                END
            ),
            COUNT(
                DISTINCT CASE
                    WHEN is_bot = 0 THEN NULLIF(session_id, '')
                END
            ),
            ROUND(
                AVG(
                    CASE
                        WHEN is_bot = 0 THEN response_ms
                    END
                )
            ),
            MAX(
                CASE
                    WHEN is_bot = 0 THEN response_ms
                END
            ),
            SUM(
                CASE
                    WHEN is_bot = 0
                     AND response_ms >= 1000
                    THEN 1 ELSE 0
                END
            ),
            SUM(
                CASE
                    WHEN is_bot = 0
                     AND response_ms >= 3000
                    THEN 1 ELSE 0
                END
            )
        FROM visitors
        WHERE visited_at >= :start_time
          AND visited_at < :end_time
        GROUP BY COALESCE(NULLIF(page, ''), '(unknown)')
        ON DUPLICATE KEY UPDATE
            human_pv = VALUES(human_pv),
            bot_pv = VALUES(bot_pv),
            unique_visitors = VALUES(unique_visitors),
            sessions = VALUES(sessions),
            avg_response_ms = VALUES(avg_response_ms),
            max_response_ms = VALUES(max_response_ms),
            slow_1000_count = VALUES(slow_1000_count),
            slow_3000_count = VALUES(slow_3000_count)
    ";

  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':stat_hour' => $startText,
    ':start_time' => $startText,
    ':end_time' => $endText,
  ]);

  adminLog(
    "流量彙總完成：{$startText} ～ {$endText}"
  );
}

/**
 * Watcher 狀態
 */
function collectWatcherHeartbeat(PDO $db): void
{
  $serviceState = adminRunCommand(
    '/usr/bin/systemctl is-active unlight_watcher'
  );

  $pidText = adminRunCommand(
    '/usr/bin/systemctl show unlight_watcher --property=MainPID --value'
  );

  $pid = ctype_digit($pidText)
    ? (int)$pidText
    : null;

  $watcherStatus = $serviceState === 'active'
    ? 'running'
    : 'stopped';

  $basePath = '/var/www/html/unlight/watcher';

  $watchers = [
    [
      'key' => 'channel1_tw',
      'name' => 'TW Channel 1',
      'region' => 'TW',
      'file' => "{$basePath}/channel1_room_TW.json",
    ],
    [
      'key' => 'channel2_tw',
      'name' => 'TW Channel 2',
      'region' => 'TW',
      'file' => "{$basePath}/channel2_room_TW.json",
    ],
    [
      'key' => 'channel1_jp',
      'name' => 'JP Channel 1',
      'region' => 'JP',
      'file' => "{$basePath}/channel1_room_JP.json",
    ],
    [
      'key' => 'channel2_jp',
      'name' => 'JP Channel 2',
      'region' => 'JP',
      'file' => "{$basePath}/channel2_room_JP.json",
    ],
    [
      'key' => 'channel3_cr',
      'name' => 'Cross Channel 3',
      'region' => 'CR',
      'file' => "{$basePath}/channel3_room_CR.json",
    ],
    [
      'key' => 'channel4_cr',
      'name' => 'Cross Channel 4',
      'region' => 'CR',
      'file' => "{$basePath}/channel4_room_CR.json",
    ],
    [
      'key' => 'ranking_bp_tw',
      'name' => 'TW BP Ranking',
      'region' => 'TW',
      'file' => "{$basePath}/ranking_bp_TW.json",
    ],
    [
      'key' => 'ranking_qp_tw',
      'name' => 'TW QP Ranking',
      'region' => 'TW',
      'file' => "{$basePath}/ranking_qp_TW.json",
    ],
    [
      'key' => 'ranking_bp_jp',
      'name' => 'JP BP Ranking',
      'region' => 'JP',
      'file' => "{$basePath}/ranking_bp_JP.json",
    ],
    [
      'key' => 'ranking_qp_jp',
      'name' => 'JP QP Ranking',
      'region' => 'JP',
      'file' => "{$basePath}/ranking_qp_JP.json",
    ],
  ];

  $sql = "
        INSERT INTO admin_watcher_heartbeat
        (
            watcher_key,
            watcher_name,
            region,
            status,
            pid,
            last_heartbeat_at,
            last_message_at,
            last_data_at,
            processed_count,
            error_count,
            last_error
        )
        VALUES
        (
            :watcher_key,
            :watcher_name,
            :region,
            :status,
            :pid,
            NOW(),
            :last_message_at,
            :last_data_at,
            :processed_count,
            :error_count,
            :last_error
        )
        ON DUPLICATE KEY UPDATE
            watcher_name = VALUES(watcher_name),
            region = VALUES(region),
            status = VALUES(status),
            pid = VALUES(pid),
            last_heartbeat_at = VALUES(last_heartbeat_at),
            last_message_at = VALUES(last_message_at),
            last_data_at = VALUES(last_data_at),
            processed_count = VALUES(processed_count),
            error_count = VALUES(error_count),
            last_error = VALUES(last_error)
    ";

  $stmt = $db->prepare($sql);

  foreach ($watchers as $watcher) {
    $file = $watcher['file'];

    $lastDataAt = null;
    $processedCount = 0;
    $errorCount = 0;
    $lastError = null;
    $status = $watcherStatus;

    if (!is_file($file)) {
      $status = 'stopped';
      $errorCount = 1;
      $lastError = '找不到 JSON 檔案';
    } else {
      $mtime = filemtime($file);

      if ($mtime !== false) {
        $lastDataAt = date('Y-m-d H:i:s', $mtime);

        $age = time() - $mtime;

        /*
                 * Ranking 約 30 秒更新。
                 * 房間檔案只有資料變動時才寫入，因此不宜一律以 3 分鐘判死。
                 */
        if (str_starts_with($watcher['key'], 'ranking_')) {
          if ($age > 300) {
            $status = 'stopped';
            $lastError = '排行資料超過 5 分鐘未更新';
          } elseif ($age > 120) {
            $status = 'warning';
            $lastError = '排行資料超過 2 分鐘未更新';
          }
        }
      }

      $size = filesize($file);

      if ($size === false || $size === 0) {
        $status = 'warning';
        $errorCount = 1;
        $lastError = 'JSON 檔案為空';
      } else {
        $isRanking = str_starts_with($watcher['key'], 'ranking_');

        if ($isRanking) {
          // 排行 JSON 較小，可以正常解析
          $raw = file_get_contents($file);
          $json = $raw !== false ? json_decode($raw, true) : null;

          if (!is_array($json)) {
            $status = 'warning';
            $errorCount = 1;
            $lastError = '排行 JSON 格式無法解析';
          } else {
            $processedCount = count($json);
          }
        } else {
          /*
         * 房間 JSON 可能超過 100MB，不用 file_get_contents()。
         * 使用 grep 串流計算 room_id，不會把整份 JSON 載入 PHP 記憶體。
         */
          $command = sprintf(
            "/usr/bin/grep -c '\"room_id\"' %s",
            escapeshellarg($file)
          );

          $countOutput = shell_exec($command . ' 2>/dev/null');

          if (
            $countOutput !== null &&
            preg_match('/^\d+$/', trim($countOutput))
          ) {
            $processedCount = (int)trim($countOutput);
          } else {
            // 無法計數時仍保留服務狀態，不直接判定 Watcher 異常
            $processedCount = 0;
          }

          /*
         * 大型 JSON 不在每分鐘完整驗證，
         * 避免造成 CPU、磁碟與記憶體額外負擔。
         */
        }
      }
    }

    $stmt->execute([
      ':watcher_key' => $watcher['key'],
      ':watcher_name' => $watcher['name'],
      ':region' => $watcher['region'],
      ':status' => $status,
      ':pid' => $pid,
      ':last_message_at' => null,
      ':last_data_at' => $lastDataAt,
      ':processed_count' => $processedCount,
      ':error_count' => $errorCount,
      ':last_error' => $lastError,
    ]);
  }

  adminLog('Watcher 狀態採集完成');
}

/**
 * PHP Error Log 彙總
 *
 * 為避免每次掃完整個 1～3MB Log，
 * 只讀最後 2MB，再彙總當日錯誤。
 */
function collectPhpErrors(PDO $db): void
{
  $logPath = '/var/log/php-fpm/www-error.log';

  if (!is_file($logPath) || !is_readable($logPath)) {
    adminLog('無法讀取 PHP Error Log');
    return;
  }

  $maxBytes = 2 * 1024 * 1024;
  $size = filesize($logPath);

  if ($size === false) {
    return;
  }

  $handle = fopen($logPath, 'rb');

  if ($handle === false) {
    return;
  }

  if ($size > $maxBytes) {
    fseek($handle, -$maxBytes, SEEK_END);
    fgets($handle);
  }

  $rows = [];

  while (($line = fgets($handle)) !== false) {
    $line = trim($line);

    if ($line === '') {
      continue;
    }

    /*
         * 範例：
         * [20-Jul-2026 12:20:52 Asia/Taipei]
         * PHP Warning: Undefined array key ...
         * in /path/file.php on line 123
         */
    if (!preg_match(
      '/^\[([^\]]+)\]\s+PHP\s+([^:]+):\s+(.+?)' .
        '(?:\s+in\s+(.+?)\s+on\s+line\s+(\d+))?$/',
      $line,
      $m
    )) {
      continue;
    }

    try {
      $date = new DateTimeImmutable($m[1]);
    } catch (Throwable $e) {
      continue;
    }

    if ($date->format('Y-m-d') !== date('Y-m-d')) {
      continue;
    }

    $level = trim($m[2]);
    $message = trim($m[3]);
    $filePath = isset($m[4]) ? trim($m[4]) : null;
    $lineNo = isset($m[5]) ? (int)$m[5] : null;

    $hashSource = implode('|', [
      $level,
      $message,
      $filePath ?? '',
      (string)($lineNo ?? 0),
    ]);

    $hash = hash('sha256', $hashSource);

    if (!isset($rows[$hash])) {
      $rows[$hash] = [
        'hash' => $hash,
        'level' => $level,
        'message' => $message,
        'file_path' => $filePath,
        'line_no' => $lineNo,
        'first_seen_at' => $date->format('Y-m-d H:i:s'),
        'last_seen_at' => $date->format('Y-m-d H:i:s'),
        'count' => 0,
      ];
    }

    $rows[$hash]['count']++;

    if (
      $date->format('Y-m-d H:i:s')
      < $rows[$hash]['first_seen_at']
    ) {
      $rows[$hash]['first_seen_at']
        = $date->format('Y-m-d H:i:s');
    }

    if (
      $date->format('Y-m-d H:i:s')
      > $rows[$hash]['last_seen_at']
    ) {
      $rows[$hash]['last_seen_at']
        = $date->format('Y-m-d H:i:s');
    }
  }

  fclose($handle);

  $sql = "
        INSERT INTO admin_error_summary
        (
            error_hash,
            error_level,
            message,
            file_path,
            line_no,
            page,
            first_seen_at,
            last_seen_at,
            occurrence_count,
            resolved_at
        )
        VALUES
        (
            :error_hash,
            :error_level,
            :message,
            :file_path,
            :line_no,
            :page,
            :first_seen_at,
            :last_seen_at,
            :occurrence_count,
            NULL
        )
        ON DUPLICATE KEY UPDATE
            error_level = VALUES(error_level),
            message = VALUES(message),
            file_path = VALUES(file_path),
            line_no = VALUES(line_no),
            page = VALUES(page),
            first_seen_at = LEAST(
                first_seen_at,
                VALUES(first_seen_at)
            ),
            last_seen_at = GREATEST(
                last_seen_at,
                VALUES(last_seen_at)
            ),
            occurrence_count = VALUES(occurrence_count),
            resolved_at = NULL
    ";

  $stmt = $db->prepare($sql);

  foreach ($rows as $row) {
    $page = null;

    if (!empty($row['file_path'])) {
      $page = basename($row['file_path']);
    }

    $stmt->execute([
      ':error_hash' => $row['hash'],
      ':error_level' => $row['level'],
      ':message' => $row['message'],
      ':file_path' => $row['file_path'],
      ':line_no' => $row['line_no'],
      ':page' => $page,
      ':first_seen_at' => $row['first_seen_at'],
      ':last_seen_at' => $row['last_seen_at'],
      ':occurrence_count' => $row['count'],
    ]);
  }

  adminLog(
    'PHP 錯誤彙總完成，共 ' . count($rows) . ' 種'
  );
}

try {
  if ($task === 'metrics' || $task === 'all') {
    collectSystemMetrics($db);
  }

  if ($task === 'traffic' || $task === 'all') {
    collectHourlyTraffic($db);
  }

  if ($task === 'watcher' || $task === 'all') {
    collectWatcherHeartbeat($db);
  }

  if ($task === 'errors' || $task === 'all') {
    collectPhpErrors($db);
  }
} catch (Throwable $e) {
  fwrite(
    STDERR,
    '[' . date('Y-m-d H:i:s') . '] 採集失敗：'
      . $e->getMessage()
      . PHP_EOL
  );

  exit(1);
}
