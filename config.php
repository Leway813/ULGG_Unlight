<?php
/* =========================================================
 * 全站初始化（Bootstrap）config.php
 * ========================================================= */

// ⭐ 時區（一定要最前）
date_default_timezone_set('Asia/Taipei');
// ⭐ 記錄本次 HTTP 請求開始時間，供效能統計使用
$GLOBALS['PAGE_START_TIME'] =
    $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

// ⭐ Composer Autoload
$composerAutoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($composerAutoload)) {
    throw new RuntimeException(
        'Composer dependencies are not installed. Run "composer install".'
    );
}



require_once $composerAutoload;

if (!class_exists(Dotenv\Dotenv::class)) {
    throw new RuntimeException(
        'Missing Composer dependency vlucas/phpdotenv. Run "composer install".'
    );
}

// ⭐ 載入 .env
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();


/**
 * Read one environment value from supported PHP environment sources.
 *
 * Empty values are treated as not configured. Callers must explicitly choose
 * whether a setting is required; credential defaults are not permitted.
 */
function appEnv(string $key, ?string $default = null): ?string
{
    $values = [
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key),
    ];

    foreach ($values as $value) {
        if ($value === false || $value === null) {
            continue;
        }

        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function appRequiredEnv(string $key): string
{
    $value = appEnv($key);

    if ($value === null) {
        throw new RuntimeException(
            sprintf('Required environment variable %s is not configured.', $key)
        );
    }

    return $value;
}

// ⭐ Web 頁面才啟動 Session；CLI/Cron 不需要
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '86400');

    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ⭐ 啟動 Session（一定要有）
/* session_start(); */


// ⭐ 讀取 DB 設定
$dbHost = appRequiredEnv('DB_HOST');
$dbUser = appRequiredEnv('DB_USER');
$dbPass = appRequiredEnv('DB_PASSWORD');
$dbName = appRequiredEnv('DB_NAME');
$dbPortText = appEnv('DB_PORT', '3306');

if (
    $dbPortText === null
    || !ctype_digit($dbPortText)
    || (int)$dbPortText < 1
    || (int)$dbPortText > 65535
) {
    throw new RuntimeException(
        'Environment variable DB_PORT must be an integer between 1 and 65535.'
    );
}

$dbPort = (int)$dbPortText;


// ⭐ 建立 DB 連線
try {
    $db = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB 連線失敗：" . $e->getMessage());
}

/* =====================================
 * Remember Me 自動補登入（多裝置）
 * ===================================== */
if (
    empty($_SESSION['user_id']) &&
    !empty($_COOKIE['ulgg_remember'])
) {
    $stmt = $db->prepare("
      SELECT u.id, u.username, u.nickname, u.ack, u.region, u.steamID,
             u.steam_username, u.steam_avatar_full
      FROM user_remember_tokens t
      JOIN game_user u ON u.id = t.user_id
      WHERE t.token = ?
        AND t.expired_at > NOW()
      LIMIT 1
    ");
    $stmt->execute([$_COOKIE['ulgg_remember']]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['username']    = $user['username'];
        $_SESSION['nickname']    = $user['nickname'];
        $_SESSION['ack']         = $user['ack'];
        $_SESSION['permission']  = $user['ack'];

        $_SESSION['steam_id']    = $user['steamID'];
        $_SESSION['steam_name']  = $user['steam_username'];
        $_SESSION['steam_avatar_full'] = $user['steam_avatar_full'];
        $_SESSION['steam_profile'] =
            "https://steamcommunity.com/profiles/{$user['steamID']}";

        $_SESSION['user_region'] = $user['region'];
        $_SESSION['view_server'] = $user['region'];
    }
}




// ⭐ 全站常數
define('IMG_BASE', '/assets/uploads/');
define('APP_ROOT', __DIR__);

// ⭐ Discord Webhook
$discordWebhook = appEnv('DISCORD_WEBHOOK_UL_VERIFY', '');
$vapidPublicKey = appEnv('ULGG_VAPID_PUBLIC_KEY', '');
$vapidPrivateKey = appEnv('ULGG_VAPID_PRIVATE_KEY', '');
$vapidSubject = appEnv('ULGG_VAPID_SUBJECT', '');

define('DISCORD_WEBHOOK_UL_VERIFY', $discordWebhook ?? '');
define('ULGG_VAPID_PUBLIC_KEY', $vapidPublicKey ?? '');
define('ULGG_VAPID_PRIVATE_KEY', $vapidPrivateKey ?? '');
define('ULGG_VAPID_SUBJECT', $vapidSubject ?? '');

function appDiscordWebhookConfigured(): bool
{
    return DISCORD_WEBHOOK_UL_VERIFY !== '';
}

function appVapidConfigured(): bool
{
    return ULGG_VAPID_PUBLIC_KEY !== ''
        && ULGG_VAPID_PRIVATE_KEY !== ''
        && ULGG_VAPID_SUBJECT !== '';
}
