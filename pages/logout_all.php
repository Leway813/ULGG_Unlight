<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: /pages/index.php', true, 302);
    exit;
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'GET') {
    if (empty($_SESSION['logout_all_csrf_token'])) {
        $_SESSION['logout_all_csrf_token'] = bin2hex(random_bytes(32));
    }

    $pageTitleText = '登出所有裝置';
    $seoTitle = $pageTitleText . ' | UL.GG 戰績網';
    $pageTitleFull = $pageTitleText . ' | UL.GG';
    $activeMenu = '';

    ob_start();
    ?>
    <div class="container" style="max-width:640px;margin:48px auto;text-align:center;">
      <h1>登出所有裝置</h1>
      <p>這會撤銷目前帳號在所有裝置上的 Remember Me 登入。</p>
      <form method="post" action="/pages/logout_all.php">
        <input type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars(
              (string)$_SESSION['logout_all_csrf_token'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>">
        <button type="submit" class="btn btn-danger">確認登出所有裝置</button>
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
$expectedToken = (string)($_SESSION['logout_all_csrf_token'] ?? '');

if (
    $submittedToken === ''
    || $expectedToken === ''
    || !hash_equals($expectedToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request');
}

$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $db->prepare("
      DELETE FROM user_remember_tokens
      WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
} catch (Throwable $error) {
    error_log(sprintf(
        '[LOGOUT ALL TOKEN DELETE ERROR] type=%s code=%s',
        get_class($error),
        (string)$error->getCode()
    ));
    http_response_code(503);
    exit('Logout is temporarily unavailable');
}

unset($_SESSION['logout_all_csrf_token']);

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
