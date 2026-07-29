<?php
/* bind_ulid.php */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

$ulidBootstrapComplete = false;
ob_start();
register_shutdown_function(
  static function () use (&$ulidBootstrapComplete): void {
    if ($ulidBootstrapComplete) {
      return;
    }

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    error_log('[ULID Binding] application bootstrap terminated before completion.');

    if (!headers_sent()) {
      http_response_code(503);
      header('Content-Type: text/plain; charset=UTF-8');
      header('Cache-Control: no-store');
    }

    echo '服務暫時無法使用，請稍後再試。';
  }
);

require_once __DIR__ . '/../config.php';
$ulidBootstrapComplete = true;
ob_end_clean();

$seoTitle = '綁定 UNLIGHT ID | UL.GG 戰績網';
$pageTitleFull = '綁定 UNLIGHT ID | UL.GG 戰績網';
$pageTitleText = '綁定 UNLIGHT ID';
$activeMenu = '';

const ULID_VERIFY_MAX_BYTES = 5 * 1024 * 1024;

/**
 * Resolve an existing, writable private directory outside the application
 * webroot. The directory is deliberately not created by the web process.
 */
function ulidPrivateUploadDirectory(): string
{
  $configuredDirectory = appEnv('ULID_VERIFY_UPLOAD_DIR');
  $candidate = $configuredDirectory
    ?? dirname(APP_ROOT)
      . DIRECTORY_SEPARATOR . 'unlight-private'
      . DIRECTORY_SEPARATOR . 'ulid-verify';

  $directory = realpath($candidate);
  $webroot = realpath(APP_ROOT);

  if (
    $directory === false
    || $webroot === false
    || !is_dir($directory)
    || !is_writable($directory)
  ) {
    throw new RuntimeException(
      'ULID private upload directory does not exist or is not writable.'
    );
  }

  $normalize = static function (string $path): string {
    $normalized = rtrim(
      str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
      DIRECTORY_SEPARATOR
    );

    return DIRECTORY_SEPARATOR === '\\'
      ? strtolower($normalized)
      : $normalized;
  };

  $normalizedDirectory = $normalize($directory);
  $normalizedWebroot = $normalize($webroot);

  if (
    $normalizedDirectory === $normalizedWebroot
    || str_starts_with(
      $normalizedDirectory . DIRECTORY_SEPARATOR,
      $normalizedWebroot . DIRECTORY_SEPARATOR
    )
  ) {
    throw new RuntimeException(
      'ULID private upload directory must be outside the application webroot.'
    );
  }

  return $directory;
}

function ulidSafeUnlink(?string $path): void
{
  if ($path !== null && is_file($path) && !@unlink($path)) {
    error_log('[ULID Binding] Unable to remove private upload artifact.');
  }
}

function ulidValidText(string $value, int $maximumLength): bool
{
  return $value !== ''
    && mb_check_encoding($value, 'UTF-8')
    && mb_strlen($value, 'UTF-8') <= $maximumLength
    && !preg_match('/[\x00-\x1F\x7F]/u', $value);
}

function ulidSendDiscordWebhook(string $webhookUrl, array $payload): bool
{
  if ($webhookUrl === '' || !function_exists('curl_init')) {
    return false;
  }

  $handle = curl_init($webhookUrl);
  if ($handle === false) {
    return false;
  }

  try {
    $json = json_encode(
      $payload,
      JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    curl_setopt_array($handle, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $response = curl_exec($handle);
    $curlError = curl_errno($handle);
    $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if (
      $response === false
      || $curlError !== 0
      || $httpStatus < 200
      || $httpStatus >= 300
    ) {
      error_log(
        '[ULID Binding] Discord request failed: curl_errno='
        . $curlError . ' http_status=' . $httpStatus
      );
      return false;
    }

    return true;
  } finally {
    curl_close($handle);
  }
}

// A valid Steam authentication creates both keys from the same game_user row.
$steamId = trim((string)($_SESSION['steam_id'] ?? ''));
$userId = filter_var(
  $_SESSION['user_id'] ?? null,
  FILTER_VALIDATE_INT,
  ['options' => ['min_range' => 1]]
);

if ($steamId === '' || $userId === false) {
  $_SESSION['login_redirect'] = '/pages/bind_ulid.php';
  header('Location: /api/auth/steam_start.php', true, 302);
  exit;
}

$errors = [];
$warnings = [];
$success = false;
$serviceAvailable = true;
$bindingState = 'eligible';
$account = null;
$form = [
  'region' => in_array(
    $_SESSION['user_region'] ?? '',
    ['TW', 'JP'],
    true
  ) ? (string)$_SESSION['user_region'] : 'TW',
  'ul_username' => '',
  'friend_code' => '',
  'email' => (string)($_SESSION['email'] ?? ''),
];

try {
  $accountStatement = $db->prepare("
    SELECT
      id,
      username,
      ack,
      apply,
      region,
      verify_image
    FROM game_user
    WHERE id = :id
      AND steamID = :steam_id
    LIMIT 1
  ");
  $accountStatement->execute([
    ':id' => $userId,
    ':steam_id' => $steamId,
  ]);
  $account = $accountStatement->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($account === null) {
    http_response_code(401);
    $serviceAvailable = false;
    $errors[] = '登入狀態已失效，請重新登入。';
  } elseif ((int)$account['ack'] >= 1) {
    $bindingState = 'verified';
  } elseif ((int)$account['apply'] === 1) {
    $bindingState = 'pending';
  }
} catch (Throwable $error) {
  http_response_code(503);
  $serviceAvailable = false;
  $errors[] = '目前無法讀取帳號狀態，請稍後再試。';
  error_log(
    '[ULID Binding] account lookup failed: '
    . get_class($error) . ' code=' . $error->getCode()
    . ' message=' . $error->getMessage()
  );
}

if (empty($_SESSION['ulid_binding_csrf'])) {
  $_SESSION['ulid_binding_csrf'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
  $expectedCsrf = (string)($_SESSION['ulid_binding_csrf'] ?? '');

  if (
    $expectedCsrf === ''
    || $submittedCsrf === ''
    || !hash_equals($expectedCsrf, $submittedCsrf)
  ) {
    $errors[] = '表單已失效，請重新確認後再送出。';
  } else {
    // A successfully verified token is single-use, including validation errors.
    unset($_SESSION['ulid_binding_csrf']);

    $form = [
      'region' => trim((string)($_POST['region'] ?? '')),
      'ul_username' => trim((string)($_POST['ul_username'] ?? '')),
      'friend_code' => trim((string)($_POST['friend_code'] ?? '')),
      'email' => trim((string)($_POST['email'] ?? '')),
    ];

    if (!$serviceAvailable || $account === null) {
      $errors[] = '登入狀態已失效，請重新登入。';
    } elseif ($bindingState === 'verified') {
      $errors[] = '此帳號已完成 UNLIGHT ID 驗證，無法由此頁覆寫。';
    }

    if (!in_array($form['region'], ['TW', 'JP'], true)) {
      $errors[] = '請選擇遊戲平台（Steam / DMM）。';
    }

    if (!ulidValidText($form['ul_username'], 45)) {
      $errors[] = 'UNLIGHT 遊戲 ID 必須為 1 至 45 個有效字元。';
    }

    if (!ulidValidText($form['friend_code'], 64)) {
      $errors[] = '好友代碼必須為 1 至 64 個有效字元。';
    }

    if (
      $form['email'] !== ''
      && (
        mb_strlen($form['email'], 'UTF-8') > 254
        || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
      )
    ) {
      $errors[] = '電子信箱格式不正確。';
    }

    $upload = $_FILES['verify_image'] ?? null;
    $uploadMime = null;
    $uploadExtension = null;

    if (!is_array($upload)) {
      $errors[] = '請上傳驗證截圖。';
    } elseif ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = '驗證截圖上傳失敗，請重新選擇檔案。';
    } else {
      $temporaryUpload = (string)($upload['tmp_name'] ?? '');
      $uploadSize = (int)($upload['size'] ?? 0);

      if (
        $temporaryUpload === ''
        || !is_uploaded_file($temporaryUpload)
      ) {
        $errors[] = '驗證截圖來源無效。';
      } elseif (
        $uploadSize < 1
        || $uploadSize > ULID_VERIFY_MAX_BYTES
      ) {
        $errors[] = '驗證截圖不可超過 5 MB。';
      } else {
        $mimeMap = [
          'image/jpeg' => 'jpg',
          'image/png' => 'png',
          'image/webp' => 'webp',
        ];
        try {
          if (!class_exists(finfo::class)) {
            throw new RuntimeException('PHP fileinfo extension is unavailable.');
          }

          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $uploadMime = $finfo->file($temporaryUpload);
          $imageInfo = @getimagesize($temporaryUpload);

          if (
            !is_string($uploadMime)
            || !isset($mimeMap[$uploadMime])
            || $imageInfo === false
            || ($imageInfo['mime'] ?? '') !== $uploadMime
          ) {
            $errors[] = '驗證截圖僅接受有效的 JPG、PNG 或 WEBP 圖片。';
          } else {
            $uploadExtension = $mimeMap[$uploadMime];
          }
        } catch (Throwable $error) {
          $errors[] = '目前無法安全檢查驗證截圖，請稍後再試。';
          error_log(
            '[ULID Binding] image inspection failed: '
            . get_class($error) . ' message=' . $error->getMessage()
          );
        }
      }
    }

    $privateDirectory = null;
    if (empty($errors)) {
      try {
        $privateDirectory = ulidPrivateUploadDirectory();
      } catch (Throwable $error) {
        $errors[] = '私密上傳空間目前不可用，請稍後再試。';
        error_log(
          '[ULID Binding] private storage unavailable: '
          . get_class($error) . ' message=' . $error->getMessage()
        );
      }
    }

    // PVP history is an advisory check; a missing history service does not
    // weaken binding uniqueness or account ownership checks.
    if (empty($errors)) {
      try {
        $historyStatement = $db->prepare("
          SELECT 1
          FROM arena_unlight
          WHERE (name_p1 = :name_p1 OR name_p2 = :name_p2)
            AND region = :region
          LIMIT 1
        ");
        $historyStatement->execute([
          ':name_p1' => $form['ul_username'],
          ':name_p2' => $form['ul_username'],
          ':region' => $form['region'],
        ]);

        if (!$historyStatement->fetchColumn()) {
          $warnings[] =
            "⚠️ 系統尚未查到此 UNLIGHT 遊戲 ID 的 PVP 對戰紀錄。\n"
            . "若你是以 PVE 為主的玩家可直接忽略；\n"
            . "若有進行 PVP，請再次確認遊戲 ID 是否輸入正確（大小寫需一致）。";
        }
      } catch (Throwable $error) {
        $warnings[] = '目前無法核對 PVP 紀錄，但不影響送出申請。';
        error_log(
          '[ULID Binding] arena history lookup failed: '
          . get_class($error) . ' code=' . $error->getCode()
          . ' message=' . $error->getMessage()
        );
      }
    }

    // Complete DB preflight before accepting the uploaded file into private
    // storage. The same checks are repeated under a row lock below.
    if (empty($errors)) {
      try {
        $duplicateStatement = $db->prepare("
          SELECT id
          FROM game_user
          WHERE username = :username
            AND region = :region
            AND id <> :user_id
          LIMIT 1
        ");
        $duplicateStatement->execute([
          ':username' => $form['ul_username'],
          ':region' => $form['region'],
          ':user_id' => $userId,
        ]);

        if ($duplicateStatement->fetchColumn()) {
          $errors[] =
            '此 UNLIGHT 遊戲 ID 已被其他帳號綁定；'
            . '若你曾更換 Steam 帳號，請聯絡管理員協助處理。';
        }
      } catch (Throwable $error) {
        $errors[] = '目前無法驗證綁定狀態，請稍後再試。';
        error_log(
          '[ULID Binding] duplicate preflight failed: '
          . get_class($error) . ' code=' . $error->getCode()
          . ' message=' . $error->getMessage()
        );
      }
    }

    $temporaryPrivatePath = null;
    $finalPrivatePath = null;
    $fileKey = null;
    $oldFileKey = null;
    $finalFileMoved = false;

    if (
      empty($errors)
      && $privateDirectory !== null
      && $uploadExtension !== null
      && isset($temporaryUpload)
    ) {
      try {
        $fileKey = bin2hex(random_bytes(32)) . '.' . $uploadExtension;
        $temporaryPrivatePath = $privateDirectory
          . DIRECTORY_SEPARATOR . '.upload-' . bin2hex(random_bytes(16)) . '.tmp';
        $finalPrivatePath = $privateDirectory . DIRECTORY_SEPARATOR . $fileKey;

        if (
          file_exists($temporaryPrivatePath)
          || file_exists($finalPrivatePath)
          || !move_uploaded_file($temporaryUpload, $temporaryPrivatePath)
        ) {
          throw new RuntimeException('Unable to create private upload temporary file.');
        }

        $db->beginTransaction();

        $lockedAccountStatement = $db->prepare("
          SELECT id, ack, apply, verify_image
          FROM game_user
          WHERE id = :id
            AND steamID = :steam_id
          LIMIT 1
          FOR UPDATE
        ");
        $lockedAccountStatement->execute([
          ':id' => $userId,
          ':steam_id' => $steamId,
        ]);
        $lockedAccount = $lockedAccountStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($lockedAccount === null) {
          throw new RuntimeException('Authenticated game_user row no longer exists.');
        }

        if ((int)$lockedAccount['ack'] >= 1) {
          throw new DomainException('Verified binding cannot be overwritten.');
        }

        $lockedDuplicateStatement = $db->prepare("
          SELECT id
          FROM game_user
          WHERE username = :username
            AND region = :region
            AND id <> :user_id
          LIMIT 1
          FOR UPDATE
        ");
        $lockedDuplicateStatement->execute([
          ':username' => $form['ul_username'],
          ':region' => $form['region'],
          ':user_id' => $userId,
        ]);

        if ($lockedDuplicateStatement->fetchColumn()) {
          throw new DomainException('ULID binding conflict detected.');
        }

        $oldFileKey = is_string($lockedAccount['verify_image'])
          ? $lockedAccount['verify_image']
          : null;

        $updateStatement = $db->prepare("
          UPDATE game_user
          SET
            username = :username,
            friend_code = :friend_code,
            verify_image = :verify_image,
            email = :email,
            region = :region,
            apply = 1,
            update_time = NOW()
          WHERE id = :id
            AND steamID = :steam_id
            AND ack = 0
        ");
        $updateStatement->execute([
          ':username' => $form['ul_username'],
          ':friend_code' => $form['friend_code'],
          ':verify_image' => $fileKey,
          ':email' => $form['email'] !== '' ? $form['email'] : null,
          ':region' => $form['region'],
          ':id' => $userId,
          ':steam_id' => $steamId,
        ]);

        if ($updateStatement->rowCount() !== 1) {
          throw new RuntimeException('ULID binding update did not affect one row.');
        }

        $verifyStatement = $db->prepare("
          SELECT username, apply, ack, region, verify_image
          FROM game_user
          WHERE id = :id
            AND steamID = :steam_id
          LIMIT 1
        ");
        $verifyStatement->execute([
          ':id' => $userId,
          ':steam_id' => $steamId,
        ]);
        $updatedAccount = $verifyStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        if (
          $updatedAccount === null
          || (string)$updatedAccount['username'] !== $form['ul_username']
          || (int)$updatedAccount['apply'] !== 1
          || (int)$updatedAccount['ack'] !== 0
          || (string)$updatedAccount['region'] !== $form['region']
          || (string)$updatedAccount['verify_image'] !== $fileKey
        ) {
          throw new RuntimeException('ULID binding verification failed.');
        }

        // The rename is on the same private filesystem and occurs while the
        // transaction can still be rolled back.
        if (!rename($temporaryPrivatePath, $finalPrivatePath)) {
          throw new RuntimeException('Unable to finalize private upload.');
        }
        $temporaryPrivatePath = null;
        $finalFileMoved = true;
        @chmod($finalPrivatePath, 0600);

        $db->commit();

        $_SESSION['username'] = $form['ul_username'];
        $_SESSION['apply'] = 1;
        $_SESSION['ack'] = 0;
        $_SESSION['email'] = $form['email'] !== '' ? $form['email'] : null;
        $_SESSION['user_region'] = $form['region'];
        $_SESSION['view_server'] = $form['region'];

        $bindingState = 'pending';
        $success = true;

        // Only opaque keys produced by this implementation are eligible for
        // cleanup. Legacy public URLs are never interpreted as local paths.
        if (
          is_string($oldFileKey)
          && $oldFileKey !== $fileKey
          && preg_match('/\A[a-f0-9]{64}\.(?:jpg|png|webp)\z/', $oldFileKey)
        ) {
          ulidSafeUnlink(
            $privateDirectory . DIRECTORY_SEPARATOR . $oldFileKey
          );
        }
      } catch (Throwable $error) {
        if ($db->inTransaction()) {
          $db->rollBack();
        }

        ulidSafeUnlink($temporaryPrivatePath);
        if ($finalFileMoved) {
          ulidSafeUnlink($finalPrivatePath);
        }

        if ($error instanceof DomainException) {
          $errors[] =
            '此帳號或 UNLIGHT ID 的狀態已變更，請重新整理後再試。';
        } else {
          $errors[] = '綁定申請未完成，請稍後再試。';
        }

        error_log(
          '[ULID Binding] submission failed: '
          . get_class($error) . ' code=' . $error->getCode()
          . ' message=' . $error->getMessage()
        );
      }
    }

    // Notifications are best-effort and run only after durable DB and file
    // success. Their failure never rolls back a completed binding request.
    if ($success) {
      if (!appDiscordWebhookConfigured()) {
        error_log(
          '[ULID Binding] Discord notification disabled: webhook not configured.'
        );
      } else {
        try {
          $notificationSent = ulidSendDiscordWebhook(
            DISCORD_WEBHOOK_UL_VERIFY,
            [
              'username' => 'UL.GG 驗證通知',
              'allowed_mentions' => ['parse' => []],
              'embeds' => [[
                'title' => '🆕 UNLIGHT ID 綁定申請',
                'color' => 0x3498db,
                'fields' => [
                  [
                    'name' => '👤 玩家',
                    'value' => $form['ul_username'],
                    'inline' => true,
                  ],
                  [
                    'name' => '🎮 Friend Code',
                    'value' => $form['friend_code'],
                    'inline' => true,
                  ],
                  [
                    'name' => '🌏 Server',
                    'value' => $form['region'] === 'TW'
                      ? 'Steam（台服）'
                      : 'DMM（日服）',
                    'inline' => true,
                  ],
                  [
                    'name' => '🆔 Steam ID',
                    'value' => $steamId,
                    'inline' => false,
                  ],
                  [
                    'name' => '📧 Email',
                    'value' => $form['email'] !== ''
                      ? $form['email']
                      : '（未填）',
                    'inline' => false,
                  ],
                ],
                'timestamp' => date('c'),
              ]],
            ]
          );

          if (!$notificationSent) {
            error_log('[ULID Binding] Discord notification was not sent.');
          }
        } catch (Throwable $error) {
          error_log(
            '[ULID Binding] Discord notification failed: '
            . get_class($error) . ' code=' . $error->getCode()
            . ' message=' . $error->getMessage()
          );
        }
      }
    }
  }

  if (!$success) {
    $_SESSION['ulid_binding_csrf'] = bin2hex(random_bytes(32));
  }
}

$csrfToken = (string)($_SESSION['ulid_binding_csrf'] ?? '');

ob_start();
?>

<style>
  .bind-box {
    max-width: 600px;
    margin: 0 auto;
    background: rgba(30, 30, 30, .85);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 10px;
    padding: 24px;
  }

  .bind-box h2 {
    margin-bottom: 16px;
  }

  .form-group label {
    font-weight: 600;
  }

  .help-text {
    font-size: 13px;
    color: #aaa;
  }

  .example-img {
    display: block;
    max-width: 100%;
    height: auto;
    box-sizing: border-box;

    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.15);

    /* 視覺穩定用 */
    background: #111;
  }

  .server-select {
    display: flex;
    gap: 12px;
  }

  .server-card {
    position: relative;
    flex: 1;
    cursor: pointer;
  }

  .server-card input {
    display: none;
  }

  .server-content {
    padding: 14px 12px;
    border-radius: 10px;
    border: 2px solid rgba(255, 255, 255, .15);
    background: rgba(20, 20, 20, .8);
    text-align: center;
    transition: all .2s ease;
  }

  .server-title {
    font-size: 16px;
    font-weight: 700;
  }

  .server-desc {
    font-size: 12px;
    color: #aaa;
  }

  .server-card:hover .server-content {
    border-color: rgba(255, 255, 255, .35);
  }

  .server-card input:checked+.server-content {
    border-color: #f2c94c;
    box-shadow: 0 0 0 1px rgba(242, 201, 76, .6),
      0 0 12px rgba(242, 201, 76, .35);
    background: rgba(35, 35, 20, .9);
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="bind-box">

        <h2>🔗 綁定 UNLIGHT 遊戲 ID</h2>
        <?php if ($success): ?>
          <div class="alert alert-success">
            ✅ 已送出綁定申請，請等待管理員審核。
          </div>
        <?php elseif ($bindingState === 'verified'): ?>
          <div class="alert alert-success">
            ✅ 此帳號已完成 UNLIGHT ID 驗證。
          </div>
        <?php elseif (!empty($errors)): ?>
          <div class="alert alert-danger">
            <ul>
              <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!$success && $bindingState === 'pending'): ?>
          <div class="alert alert-info">
            目前已有待審核申請；重新送出會安全取代前一份待審資料。
          </div>
        <?php endif; ?>

        <?php if (!$success && $serviceAvailable && $bindingState !== 'verified'): ?>
          <div class="bind-example">
            <h4>📸 驗證截圖範例</h4>
            <p class="example-desc">
              請至遊戲內 <strong>Friend List</strong> 畫面，截取顯示「好友代碼」的畫面，
              並上傳作為驗證依據。
            </p>

            <a
              href="/assets/img/example/friend_code_example.png"
              target="_blank"
              rel="noopener noreferrer">
              <img
                src="/assets/img/example/friend_code_example.png"
                class="example-img"
                alt="好友代碼截圖範例">
            </a>

            <div class="alert alert-warning mt-3" role="alert" style="border-left:4px solid #f0ad4e;">
              <strong>⚠️ 請特別注意：</strong><br>
              驗證截圖<strong>必須清楚顯示
                <br>1.「遊戲 ID（玩家名稱）」
                <br>2.「好友邀請碼（Friend Code）」</strong>
            </div>

          </div>
          <hr>

          <form method="POST" enctype="multipart/form-data" onsubmit="return confirmVerifyImage();">
            <input
              type="hidden"
              name="csrf_token"
              value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input
              type="hidden"
              name="MAX_FILE_SIZE"
              value="<?= ULID_VERIFY_MAX_BYTES ?>">

            <div class="form-group">
              <label class="d-block mb-2">遊戲平台 / 伺服器 <span class="text-danger">*</span></label>

              <div class="server-select">
                <label class="server-card">
                  <input
                    type="radio"
                    name="region"
                    value="TW"
                    <?= $form['region'] === 'TW' ? 'checked' : '' ?>
                    required>
                  <div class="server-content">
                    <div class="server-title">Steam</div>
                    <div class="server-desc">伺服器</div>
                  </div>
                </label>

                <label class="server-card">
                  <input
                    type="radio"
                    name="region"
                    value="JP"
                    <?= $form['region'] === 'JP' ? 'checked' : '' ?>>
                  <div class="server-content">
                    <div class="server-title">DMM</div>
                    <div class="server-desc">伺服器</div>
                  </div>
                </label>
              </div>

              <div class="help-text mt-2">
                請依實際遊玩平台選擇，選錯會影響資料歸屬與審核結果。
              </div>
            </div>


            <div class="form-group">
              <label>UNLIGHT 遊戲 ID *</label>
              <input type="text" name="ul_username" class="form-control"
                maxlength="45"
                placeholder="請輸入遊戲中顯示的 ID"
                value="<?= htmlspecialchars(
                          $form['ul_username'],
                          ENT_QUOTES,
                          'UTF-8'
                        ) ?>"
                required>

              <div class="help-text">
                建議先使用本站「搜尋玩家」確認正確 ID，直接複製貼上可避免錯誤。
              </div>

              <?php if (!empty($warnings)): ?>
                <div class="alert alert-warning">
                  <strong>提示（不影響送出）：</strong>
                  <ul>
                    <?php foreach ($warnings as $w): ?>
                      <li><?= nl2br(
                              htmlspecialchars($w, ENT_QUOTES, 'UTF-8')
                            ) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
            </div>


            <div class="form-group">
              <label>好友代碼（Friend Code）*</label>
              <input type="text" name="friend_code" class="form-control"
                maxlength="64"
                placeholder="例如：7LZfN8Nkd"
                value="<?= htmlspecialchars(
                          $form['friend_code'],
                          ENT_QUOTES,
                          'UTF-8'
                        ) ?>"
                required>
              <div class="help-text">
                請至遊戲內「Friend List」畫面查看
              </div>
            </div>

            <div class="form-group">
              <label>驗證截圖</label>
              <input
                type="file"
                name="verify_image"
                class="form-control"
                accept="image/jpeg,image/png,image/webp"
                style="height: 40px;"
                required>
              <div class="help-text">
                請上傳顯示「好友代碼」的 JPG、PNG 或 WEBP 圖片，最大 5 MB。
              </div>
            </div>
            <div class="form-group">
              <label>電子信箱（選填）</label>
              <input type="email" name="email" class="form-control"
                placeholder="example@email.com"
                maxlength="254"
                value="<?= htmlspecialchars(
                          $form['email'],
                          ENT_QUOTES,
                          'UTF-8'
                        ) ?>">
              <div class="help-text">
                若填寫，審核通過後將寄送通知信（不會用於其他用途）
              </div>
            </div>

            <button type="submit" class="btn btn-primary">
              送出綁定申請
            </button>

          </form>
        <?php endif; ?>

      </div>
    </div>
  </section>
</div>
<script>
  function confirmVerifyImage() {
    return confirm(
      "⚠️ 請確認你上傳的截圖中：\n\n" +
      "✔ 清楚顯示「遊戲 ID（玩家名稱）」\n" +
      "✔ 清楚顯示「好友代碼（Friend Code）」\n\n" +
      "若未顯示完整，將無法通過審核。\n\n確定要送出嗎？"
    );
  }
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
