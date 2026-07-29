<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Cache-Control: no-store');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'GET') {
    if (empty($_SESSION['logout_csrf_token'])) {
        $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
    }

    $pageTitleText = '確認登出';
    $seoTitle = $pageTitleText . ' | UL.GG 戰績網';
    $pageTitleFull = $pageTitleText . ' | UL.GG';
    $activeMenu = '';

    ob_start();
    ?>
    <div class="container" style="max-width:640px;margin:48px auto;text-align:center;">
      <h1>確認登出</h1>
      <p>這只會登出目前裝置。</p>
      <form method="post" action="/pages/logout.php">
        <input type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars(
              (string)$_SESSION['logout_csrf_token'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>">
        <button type="submit" class="btn btn-danger">登出目前裝置</button>
        <a href="/pages/index.php" class="btn btn-default">取消</a>
      </form>
    </div>
    <?php
    $pageContent = ob_get_clean();
    include __DIR__ . '/../layout/base.php';
    return;
}

if ($requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$submittedToken = (string)($_POST['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['logout_csrf_token'] ?? '');

if (
    $submittedToken === ''
    || $expectedToken === ''
    || !hash_equals($expectedToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request');
}

unset($_SESSION['logout_csrf_token']);

$rememberToken = (string)($_COOKIE['ulgg_remember'] ?? '');
if ($rememberToken !== '') {
    try {
        $stmt = $db->prepare("
          DELETE FROM user_remember_tokens
          WHERE token = ?
        ");
        $stmt->execute([$rememberToken]);
    } catch (Throwable $error) {
        error_log(sprintf(
            '[LOGOUT TOKEN DELETE ERROR] type=%s code=%s',
            get_class($error),
            (string)$error->getCode()
        ));
    }
}

setcookie('ulgg_remember', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $sessionCookie = [
        'expires' => time() - 3600,
        'path' => $params['path'] ?: '/',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax',
    ];
    if ($params['domain'] !== '') {
        $sessionCookie['domain'] = $params['domain'];
    }

    setcookie(session_name(), '', $sessionCookie);
}

session_destroy();

header('Location: /pages/index.php', true, 303);
exit;
