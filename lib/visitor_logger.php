<?php
// lib/visitor_logger.php

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
  http_response_code(403);
  exit('Forbidden');
}

if (!defined('APP_ROOT')) {
  http_response_code(403);
  exit;
}

if (php_sapi_name() === 'cli') return;

$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if (preg_match('#\.(css|js|png|jpg|jpeg|gif|svg|ico|webp|woff|woff2|ttf|map)$#i', $requestUri)) return;
if (preg_match('#^/api/#i', $requestUri)) return;

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$logUri = $requestUri;

if (!empty($_GET)) {
  $dedupeParams = $_GET;
  unset($dedupeParams['t'], $dedupeParams['_'], $dedupeParams['cache']);
  $pathOnly = strtok($requestUri, '?');
  $queryForLog = http_build_query($dedupeParams, '', '&', PHP_QUERY_RFC3986);
  $logUri = $queryForLog !== '' ? $pathOnly . '?' . $queryForLog : $pathOnly;
}
$logKey = 'logged_' . md5($logUri);
if (!empty($_SESSION[$logKey])) return;
//$_SESSION[$logKey] = true;

function ulggGetClientIp(): string
{
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
  }

  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($parts[0]);
  }

  if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    return trim($_SERVER['HTTP_X_REAL_IP']);
  }

  return $_SERVER['REMOTE_ADDR'] ?? '';
}

function ulggIpStartsWith(string $ip, array $prefixes): bool
{
  foreach ($prefixes as $prefix) {
    if (strpos($ip, $prefix) === 0) return true;
  }

  return false;
}

function ulggIsHighRiskPage(string $page): bool
{
  return in_array($page, [
    '/pages/fight.php',
    '/pages/fight_detail.php',
    '/pages/team_analysis.php',
    '/pages/ranking_team.php',
    '/pages/analysis_character_card.php',
    '/pages/character.php',
    '/pages/character_analysis.php',
    '/pages/skill_by_tag.php',
    '/pages/strength_rank.php',
  ], true);
}

function ulggDetectBotType(string $ua, string $ip = '', string $page = ''): ?string
{
  $uaLower = strtolower(trim($ua));

  if ($uaLower === '') {
    return 'EmptyUserAgent';
  }

  $botMap = [
    'claudebot' => 'ClaudeBot',
    'anthropic-ai' => 'AnthropicAI',
    'gptbot' => 'GPTBot',
    'chatgpt-user' => 'ChatGPTUser',
    'openai' => 'OpenAI',
    'perplexitybot' => 'PerplexityBot',
    'bytespider' => 'ByteSpider',
    'amazonbot' => 'AmazonBot',
    'applebot' => 'AppleBot',
    'serankingbacklinksbot' => 'SERankingBacklinksBot',
    'googlebot' => 'Googlebot',
    'adsbot-google' => 'AdsBotGoogle',
    'google-inspectiontool' => 'GoogleInspectionTool',
    'bingbot' => 'Bingbot',
    'slurp' => 'YahooSlurp',
    'duckduckbot' => 'DuckDuckBot',
    'baiduspider' => 'BaiduSpider',
    'yandexbot' => 'YandexBot',
    'ahrefsbot' => 'AhrefsBot',
    'semrushbot' => 'SemrushBot',
    'mj12bot' => 'MJ12Bot',
    'dotbot' => 'DotBot',
    'petalbot' => 'PetalBot',
    'ccbot' => 'CCBot',
    'facebookexternalhit' => 'Facebook',
    'meta-externalagent' => 'MetaExternalAgent',
    'discordbot' => 'DiscordBot',
    'line-poker' => 'LinePreview',
    'headlesschrome' => 'HeadlessChrome',
    'python-requests' => 'PythonRequests',
    'python/' => 'Python',
    'aiohttp' => 'PythonAiohttp',
    'curl' => 'Curl',
    'wget' => 'Wget',
    'go-http-client' => 'GoHttpClient',
    'okhttp' => 'OkHttp',
    'java/' => 'JavaClient',
    'axios' => 'Axios',
    'node-fetch' => 'NodeFetch',
    'scrapy' => 'Scrapy',
    'playwright' => 'Playwright',
    'puppeteer' => 'Puppeteer',
    'crawler' => 'GenericCrawler',
    'spider' => 'GenericSpider',
    'bot' => 'GenericBot',
  ];

  foreach ($botMap as $needle => $type) {
    if (strpos($uaLower, $needle) !== false) {
      return $type;
    }
  }

  if (ulggIpStartsWith($ip, ['66.249.'])) {
    return 'Googlebot';
  }

  if (ulggIpStartsWith($ip, ['57.141.6.'])) {
    return 'MetaExternalAgent';
  }

  if (ulggIpStartsWith($ip, ['216.73.216.', '216.73.217.'])) {
    return 'SuspiciousCrawler';
  }

  if (ulggIpStartsWith($ip, [
    '112.90.',
    '120.241.',
    '120.233.',
    '14.29.',
    '14.116.',
    '183.204.',
    '111.6.',
    '111.9.',
    '119.188.',
    '223.83.',
    '223.114.',
    '117.168.',
    '120.244.',
    '183.247.',
    '218.204.',
    '111.33.',
    '120.192.',
    '182.117.',
  ])) {
    if (preg_match('/Chrome\/142\.0\.0\.0/i', $ua)) {
      return 'SuspiciousDistributedChrome';
    }
  }

  /* if (ulggIsHighRiskPage($page)) {
    if (preg_match('/Chrome\/(13[0-9]|14[0-9]|150)\.0\.0\.0/i', $ua)) {
      if (
        strpos($uaLower, 'windows nt 10.0; win64; x64') !== false ||
        strpos($uaLower, 'macintosh; intel mac os x 10_15_7') !== false
      ) {
        return 'SuspiciousDistributedChrome';
      }
    }
  } */

  return null;
}

function ulggDetectDevice(string $ua): string
{
  $uaLower = strtolower($ua);

  if (strpos($uaLower, 'ipad') !== false || strpos($uaLower, 'tablet') !== false) {
    return 'tablet';
  }

  if (strpos($uaLower, 'mobile') !== false || strpos($uaLower, 'android') !== false || strpos($uaLower, 'iphone') !== false) {
    return 'mobile';
  }

  return 'desktop';
}

function ulggDetectBrowser(string $ua): string
{
  $uaLower = strtolower($ua);

  if (strpos($uaLower, 'edg/') !== false || strpos($uaLower, 'edga/') !== false) return 'Edge';
  if (strpos($uaLower, 'line/') !== false) return 'LINE';
  if (strpos($uaLower, 'fbav') !== false) return 'Facebook';
  if (strpos($uaLower, 'chrome/') !== false) return 'Chrome';
  if (strpos($uaLower, 'firefox/') !== false) return 'Firefox';
  if (strpos($uaLower, 'safari/') !== false && strpos($uaLower, 'chrome/') === false) return 'Safari';

  return 'Other';
}

function ulggDetectOs(string $ua): string
{
  $uaLower = strtolower($ua);

  if (strpos($uaLower, 'windows') !== false) return 'Windows';
  if (strpos($uaLower, 'android') !== false) return 'Android';
  if (strpos($uaLower, 'iphone') !== false || strpos($uaLower, 'ipad') !== false || strpos($uaLower, 'ios') !== false) return 'iOS';
  if (strpos($uaLower, 'mac os') !== false || strpos($uaLower, 'macintosh') !== false) return 'macOS';
  if (strpos($uaLower, 'linux') !== false) return 'Linux';

  return 'Other';
}

$ip = ulggGetClientIp();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? null;
$sessionId = session_id() ?: null;
$page = strtok($requestUri, '?');
$requestQuery = $_SERVER['QUERY_STRING'] ?? null;
$visitedAt = date('Y-m-d H:i:s');

if ($requestQuery !== null && mb_strlen($requestQuery) > 1000) {
  $requestQuery = mb_substr($requestQuery, 0, 1000);
}

$botType = ulggDetectBotType($userAgent, $ip, $page);
$isBot = $botType ? 1 : 0;
$device = ulggDetectDevice($userAgent);
$browser = ulggDetectBrowser($userAgent);
$os = ulggDetectOs($userAgent);
$visitorType = isset($_SESSION['steam_id']) ? 'member' : 'guest';

$responseMs = isset($GLOBALS['PAGE_START_TIME'])
  ? max(
    0,
    (int)round(
      (microtime(true) - (float)$GLOBALS['PAGE_START_TIME']) * 1000
    )
  )
  : null;

$searchTerm = null;

if (!empty($_GET['player_search'])) {
  $searchTerm = trim((string)$_GET['player_search']);
} elseif (!empty($_GET['player_name'])) {
  $searchTerm = trim((string)$_GET['player_name']);
} elseif (!empty($_GET['name'])) {
  $searchTerm = trim((string)$_GET['name']);
} elseif (!empty($_GET['q'])) {
  $searchTerm = trim((string)$_GET['q']);
}

$character = $_GET['character'] ?? $_GET['char'] ?? $_GET['char_base'] ?? null;
$character = is_string($character) ? trim($character) : null;

$characterId = null;

if (!empty($_GET['char_id'])) {
  $rawCharId = trim((string)$_GET['char_id']);

  if (preg_match('/^\d+$/', $rawCharId)) {
    $characterId = (int)$rawCharId;
  }
}

if ($characterId === null && $character !== null && preg_match('/^(\d+)-/u', $character, $m)) {
  $characterId = (int)$m[1];
}
if ($characterId !== null) {
  try {
    $stmtCharLog = $db->prepare("
      SELECT CONCAT(level, name)
      FROM unlight
      WHERE id = :id
      LIMIT 1
    ");
    $stmtCharLog->execute([':id' => $characterId]);
    $resolvedCharacter = $stmtCharLog->fetchColumn();

    if ($resolvedCharacter !== false && $resolvedCharacter !== null) {
      $character = (string)$resolvedCharacter;
    } else {
      $characterId = null;
    }
  } catch (Throwable $e) {
    error_log('[visitor_logger character resolve] ' . $e->getMessage());
  }
}

if ($searchTerm !== null && mb_strlen($searchTerm) > 255) {
  $searchTerm = mb_substr($searchTerm, 0, 255);
}

if ($character !== null && mb_strlen($character) > 255) {
  $character = mb_substr($character, 0, 255);
}

$sql = "
  INSERT INTO visitors
  (
    ip,
    user_agent,
    is_bot,
    bot_type,
    page,
    request_query,
    search_term,
    character_name,
    character_id,
    visited_at,
    referrer,
    session_id,
    visitor_type,
    device,
    browser,
    os,
    response_ms
  )
  VALUES
  (
    :ip,
    :ua,
    :is_bot,
    :bot_type,
    :page,
    :request_query,
    :search_term,
    :character,
    :character_id,
    :visited_at,
    :referrer,
    :session_id,
    :visitor_type,
    :device,
    :browser,
    :os,
    :response_ms
  )
";

try {
  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':ip' => $ip,
    ':ua' => $userAgent,
    ':is_bot' => $isBot,
    ':bot_type' => $botType,
    ':page' => $page,
    ':request_query' => $requestQuery,
    ':search_term' => $searchTerm,
    ':character' => $character,
    ':character_id' => $characterId,
    ':visited_at' => $visitedAt,
    ':referrer' => $referrer,
    ':session_id' => $sessionId,
    ':visitor_type' => $visitorType,
    ':device' => $device,
    ':browser' => $browser,
    ':os' => $os,
    ':response_ms' => $responseMs,
  ]);
  $_SESSION[$logKey] = true;
} catch (Throwable $e) {
  error_log('[visitor_logger] ' . $e->getMessage());
}
