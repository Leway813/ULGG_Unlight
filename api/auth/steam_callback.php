<?php

declare(strict_types=1);

const STEAM_AUTH_BASE_URL = 'https://ulgg.online';

set_exception_handler(static function (Throwable $error): void {
  error_log(sprintf(
    '[STEAM AUTH ERROR] type=%s code=%s',
    get_class($error),
    (string)$error->getCode()
  ));

  if (!headers_sent()) {
    http_response_code(500);
  }

  exit('Steam 登入暫時無法完成，請稍後再試');
});

if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

header('Cache-Control: no-store');

/**
 * 向 Steam 驗證 OpenID 2.0 回傳簽章。
 */
function verifySteamOpenIdCallback(array $query): bool
{
  $fieldMap = [
    'openid_ns'             => 'openid.ns',
    'openid_mode'           => 'openid.mode',
    'openid_op_endpoint'    => 'openid.op_endpoint',
    'openid_claimed_id'     => 'openid.claimed_id',
    'openid_identity'       => 'openid.identity',
    'openid_return_to'      => 'openid.return_to',
    'openid_response_nonce' => 'openid.response_nonce',
    'openid_assoc_handle'   => 'openid.assoc_handle',
    'openid_signed'         => 'openid.signed',
    'openid_sig'            => 'openid.sig',
  ];

  $postFields = [];

  foreach ($fieldMap as $phpKey => $openidKey) {
    if (isset($query[$phpKey])) {
      $postFields[$openidKey] = (string)$query[$phpKey];
    }
  }

  // 驗證時必須改為 check_authentication
  $postFields['openid.mode'] = 'check_authentication';

  $requiredFields = [
    'openid.ns',
    'openid.op_endpoint',
    'openid.claimed_id',
    'openid.identity',
    'openid.return_to',
    'openid.response_nonce',
    'openid.assoc_handle',
    'openid.signed',
    'openid.sig',
  ];

  foreach ($requiredFields as $field) {
    if (!isset($postFields[$field]) || $postFields[$field] === '') {
      return false;
    }
  }

  $ch = curl_init('https://steamcommunity.com/openid/login');

  if ($ch === false) {
    return false;
  }

  curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => http_build_query(
      $postFields,
      '',
      '&',
      PHP_QUERY_RFC3986
    ),
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_HEADER          => false,
    CURLOPT_CONNECTTIMEOUT  => 5,
    CURLOPT_TIMEOUT         => 10,
    CURLOPT_FOLLOWLOCATION  => false,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
    CURLOPT_HTTPHEADER      => [
      'Content-Type: application/x-www-form-urlencoded',
      'User-Agent: UL.GG Steam OpenID Validator',
    ],
  ]);

  $response = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);

  curl_close($ch);

  if (
    $response === false
    || $httpCode !== 200
  ) {
    error_log(sprintf(
      '[STEAM OPENID VERIFY ERROR] http=%d curl=%s',
      $httpCode,
      $curlError
    ));

    return false;
  }

  $result = [];

  foreach (preg_split('/\r\n|\r|\n/', trim((string)$response)) as $line) {
    if (!str_contains($line, ':')) {
      continue;
    }

    [$key, $value] = explode(':', $line, 2);
    $result[trim($key)] = trim($value);
  }

  return ($result['is_valid'] ?? '') === 'true';
}

/* ============================
 * 驗證 Steam OpenID 回傳
 * ============================ */

$state = trim((string)($_GET['state'] ?? ''));
$expectedState = (string)($_SESSION['steam_auth_state'] ?? '');
$authStartedAt = (int)($_SESSION['steam_auth_started_at'] ?? 0);

if (
  !preg_match('/^[a-f0-9]{64}$/', $state)
  || !preg_match('/^[a-f0-9]{64}$/', $expectedState)
  || !hash_equals($expectedState, $state)
) {
  http_response_code(400);
  exit('Steam 登入驗證失敗');
}

unset(
  $_SESSION['steam_auth_state'],
  $_SESSION['steam_auth_started_at']
);

$authAge = time() - $authStartedAt;

if ($authStartedAt <= 0 || $authAge > 600 || $authAge < -60) {
  http_response_code(400);
  exit('Steam 登入驗證已逾時，請重新登入');
}

$claimedId = trim((string)($_GET['openid_claimed_id'] ?? ''));
$identity = trim((string)($_GET['openid_identity'] ?? ''));
$mode = trim((string)($_GET['openid_mode'] ?? ''));
if ($mode === 'cancel') {
  header('Location: /pages/index.php', true, 303);
  exit;
}
$namespace = trim((string)($_GET['openid_ns'] ?? ''));
$opEndpoint = trim((string)($_GET['openid_op_endpoint'] ?? ''));
$returnTo = trim((string)($_GET['openid_return_to'] ?? ''));
$responseNonce = trim((string)($_GET['openid_response_nonce'] ?? ''));

$expectedReturnTo = STEAM_AUTH_BASE_URL
  . '/api/auth/steam_callback.php?state='
  . rawurlencode($state);

$signedFields = array_filter(
  array_map(
    'trim',
    explode(',', (string)($_GET['openid_signed'] ?? ''))
  ),
  static fn(string $field): bool => $field !== ''
);

$requiredSignedFields = [
  'op_endpoint',
  'claimed_id',
  'identity',
  'return_to',
  'response_nonce',
];

/*
 * 第一層：固定格式檢查
 */
$isBasicCallbackValid =
  $mode === 'id_res'
  && $namespace === 'http://specs.openid.net/auth/2.0'
  && $opEndpoint === 'https://steamcommunity.com/openid/login'
  && $returnTo === $expectedReturnTo
  && $claimedId === $identity
  && array_diff($requiredSignedFields, $signedFields) === []
  && preg_match(
    '#^https://steamcommunity\.com/openid/id/(\d{17})$#',
    $claimedId,
    $matches
  );

if (!$isBasicCallbackValid) {
  error_log(sprintf(
    '[STEAM CALLBACK REJECTED] ip=%s mode=%s claimed_length=%d',
    $_SERVER['REMOTE_ADDR'] ?? '',
    $mode,
    strlen($claimedId)
  ));

  http_response_code(400);
  exit('Steam 登入驗證失敗');
}

/*
 * 第二層：nonce 時間檢查
 *
 * Steam nonce 開頭格式：
 * 2026-07-23T08:11:28Z...
 */
$nonceTimestampText = substr($responseNonce, 0, 20);
$nonceTimestamp = strtotime($nonceTimestampText);

if ($nonceTimestamp === false) {
  error_log(sprintf(
    '[STEAM NONCE INVALID] ip=%s nonce_length=%d',
    $_SERVER['REMOTE_ADDR'] ?? '',
    strlen($responseNonce)
  ));

  http_response_code(400);
  exit('Steam 登入驗證失敗');
}

$nonceAge = time() - $nonceTimestamp;

// 超過 10 分鐘，或時間比伺服器快超過 60 秒
if ($nonceAge > 600 || $nonceAge < -60) {
  error_log(sprintf(
    '[STEAM NONCE EXPIRED] ip=%s age=%d',
    $_SERVER['REMOTE_ADDR'] ?? '',
    $nonceAge
  ));

  http_response_code(400);
  exit('Steam 登入驗證已逾時，請重新登入');
}

/*
 * 第三層：向 Steam 驗證簽章
 */
if (!verifySteamOpenIdCallback($_GET)) {
  error_log(sprintf(
    '[STEAM SIGNATURE INVALID] ip=%s steam_id=%s',
    $_SERVER['REMOTE_ADDR'] ?? '',
    $matches[1] ?? ''
  ));

  http_response_code(403);
  exit('Steam 登入驗證失敗');
}

$steamID = $matches[1];

/*
 * 已通過 Steam 驗證才載入 DB bootstrap。
 * config.php 目前會直接輸出 PDO 連線錯誤，因此在此入口收斂為通用訊息。
 */
$bootstrapComplete = false;
ob_start(static function (string $output) use (&$bootstrapComplete): string {
  if ($bootstrapComplete) {
    return $output;
  }

  http_response_code(500);
  return 'Steam 登入暫時無法完成，請稍後再試';
});
require_once __DIR__ . '/../../config.php';
$bootstrapComplete = true;
ob_end_clean();

/*
 * 第四層：阻擋同一個 nonce 重播
 */
try {
  // 順手清除舊紀錄
  $db->exec("
    DELETE FROM steam_openid_nonce
    WHERE created_at < NOW() - INTERVAL 1 DAY
  ");

  $nonceStmt = $db->prepare("
    INSERT INTO steam_openid_nonce
      (nonce, steam_id, created_at)
    VALUES
      (?, ?, NOW())
  ");

  $nonceStmt->execute([
    $responseNonce,
    $steamID,
  ]);
} catch (PDOException $e) {
  if ((string)$e->getCode() === '23000') {
    error_log(sprintf(
      '[STEAM NONCE REPLAY] ip=%s steam_id=%s',
      $_SERVER['REMOTE_ADDR'] ?? '',
      $steamID
    ));

    http_response_code(403);
    exit('此 Steam 登入驗證已使用，請重新登入');
  }

  throw $e;
}

$stmt = $db->prepare("
  SELECT
    id,
    username,
    nickname,
    ack,
    apply,
    region
  FROM game_user
  WHERE steamID = ?
  LIMIT 1
");

$stmt->execute([$steamID]);
$row = $stmt->fetch();

if ($row) {
  session_regenerate_id(true);

  $_SESSION['steam_id']   = $steamID;
  $_SESSION['user_id']    = (int)$row['id'];
  $_SESSION['username']   = (string)$row['username'];
  $_SESSION['nickname']   = $row['nickname'];
  $_SESSION['ack']        = (int)$row['ack'];
  $_SESSION['permission'] = (int)$row['ack'];
  $_SESSION['apply']      = (int)($row['apply'] ?? 0);

  $_SESSION['user_region'] =
    !empty($row['region'])
    ? (string)$row['region']
    : 'TW';

  $_SESSION['view_server'] =
    $_SESSION['user_region'];

  unset($_SESSION['is_new_user']);
} else {
  $username = '訪客_' . $steamID;

  if (mb_strlen($username, 'UTF-8') > 45) {
    error_log(
      '[STEAM LOGIN USERNAME TOO LONG] length='
        . mb_strlen($username, 'UTF-8')
        . ' steamID='
        . $steamID
    );

    http_response_code(400);
    exit('建立使用者失敗：帳號格式異常');
  }

  $insert = $db->prepare("
    INSERT INTO game_user
      (username, steamID, ack, apply, region, update_time)
    VALUES
      (?, ?, 0, 0, 'TW', NOW())
  ");

  $insert->execute([$username, $steamID]);

  $newUserId = (int)$db->lastInsertId();

  session_regenerate_id(true);

  $_SESSION['steam_id']    = $steamID;
  $_SESSION['user_id']     = $newUserId;
  $_SESSION['username']    = $username;
  $_SESSION['nickname']    = null;
  $_SESSION['ack']         = 0;
  $_SESSION['permission']  = 0;
  $_SESSION['apply']       = 0;
  $_SESSION['user_region'] = 'TW';
  $_SESSION['view_server'] = 'TW';
  $_SESSION['is_new_user'] = 1;
}



/* 3️⃣ 讀取 Steam XML */
$xmlUrl = "https://steamcommunity.com/profiles/{$steamID}/?xml=1";
//$xml = @simplexml_load_file($xmlUrl);
$xmlContext = stream_context_create([
  'http' => [
    'timeout' => 5,
    'user_agent' => 'UL.GG Steam Profile Fetcher',
  ],
]);

$xmlContent = @file_get_contents(
  $xmlUrl,
  false,
  $xmlContext
);

$xml = $xmlContent !== false
  ? @simplexml_load_string($xmlContent)
  : false;

if ($xml) {
  $_SESSION['steam_name']        = (string) ($xml->steamID ?? "User-{$steamID}");
  $_SESSION['steam_avatar_full'] = (string) ($xml->avatarFull ?? "");
  $_SESSION['steam_profile']     = "https://steamcommunity.com/profiles/{$steamID}";
  // ⭐⭐⭐ 存進 DB（關鍵）
  $stmt = $db->prepare("
      UPDATE game_user
      SET steam_username = ?,
          steam_avatar_full = ?
      WHERE id = ?
    ");
  $stmt->execute([
    $_SESSION['steam_name'],
    $_SESSION['steam_avatar_full'],
    $_SESSION['user_id']
  ]);
} else {
  $_SESSION['steam_name']        = "User-{$steamID}";
  $_SESSION['steam_avatar_full'] = "";
  $_SESSION['steam_profile']     = "https://steamcommunity.com/profiles/{$steamID}";
}
$redirect = (string)(
  $_SESSION['login_redirect']
  ?? '/pages/index.php'
);

unset($_SESSION['login_redirect']);

if (
  !preg_match('#^/pages/[a-zA-Z0-9_./-]+(?:\?[^\x00-\x1F\x7F]*)?$#', $redirect)
  || str_contains($redirect, '..')
) {
  $redirect = '/pages/index.php';
}

/* ============================
 * Remember Me（Steam 登入專用｜多裝置）
 * ============================ */
if (!isset($_SESSION['remember_login']) || $_SESSION['remember_login']) {

  $rememberToken  = bin2hex(random_bytes(32));
  $rememberExpire = date('Y-m-d H:i:s', time() + 30 * 86400);

  $stmt = $db->prepare("
      INSERT INTO user_remember_tokens
        (user_id, token, expired_at, user_agent, ip)
      VALUES
        (?, ?, ?, ?, ?)
    ");
  $stmt->execute([
    $_SESSION['user_id'],
    $rememberToken,
    $rememberExpire,
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    $_SERVER['REMOTE_ADDR'] ?? null,
  ]);

  setcookie(
    'ulgg_remember',
    $rememberToken,
    [
      'expires'  => time() + 30 * 86400,
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => true,
      'samesite' => 'Lax',
    ]
  );
}

unset($_SESSION['remember_login']); // ⭐ 一定要清



header('Location: ' . $redirect, true, 303);
exit;
