<?php
session_start();
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';
require_once __DIR__ . '/../../../lib/mail_helper.php';

header('Content-Type: application/json');

$id     = (int)($_POST['id'] ?? 0);
$result = $_POST['result'] ?? '';
$note   = trim($_POST['note'] ?? '');

if ($id <= 0) {
  echo json_encode(['error' => 'Invalid ID']);
  exit;
}

if (!in_array($result, ['approve', 'reject'], true)) {
  echo json_encode(['error' => 'Invalid action']);
  exit;
}

if ($result === 'reject' && $note === '') {
  echo json_encode(['error' => 'Reject note required']);
  exit;
}
if ($result === 'approve' && $note === '') {
  $note = '驗證通過';
}

if ($result === 'approve') {
  $sql = "UPDATE game_user
    SET
      ack = 1,
      apply = 0,
      verify_note = :note
    WHERE id = :id
    LIMIT 1
  ";
} else {
  $sql = "UPDATE game_user
    SET
      ack = 0,
      apply = 0,
      verify_note = :note
    WHERE id = :id
    LIMIT 1
  ";
}

$stmt = $db->prepare($sql);
$stmt->execute([
  ':id'   => $id,
  ':note' => $note
]);
if ($stmt->rowCount() === 0) {
  echo json_encode(['error' => '狀態未變更或資料不存在']);
  exit;
}
// ===== // 3. SELECT 使用者資料（寄信用）=====
$stmtU = $db->prepare("
  SELECT username, email
  FROM game_user
  WHERE id = :id
  LIMIT 1
");
$stmtU->execute([':id' => $id]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);

// ===== 4. 清洗 / 驗證 email（僅在有填寫時）=====
$email = trim((string)($user['email'] ?? ''));
$email = preg_replace('/[[:^print:]]/', '', $email);

if ($email !== '') {
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // email 格式不合法 → 回錯誤（但只限有填的情況）
    echo json_encode(['error' => 'Invalid email format']);
    exit;
  }
}


// 5. 寄信
// ===== 審核通過後寄信（選填 email）=====
if ($result === 'approve' && !empty($user['email'])) {

  $subject = '【UL.GG】UNLIGHT ID 綁定審核通過通知';

  $body =
    "您好，{$user['username']} 您好：\n\n" .
    "感謝您申請 ULGG 戰績網 的 UNLIGHT ID 綁定服務。\n\n" .
    "我們已完成您的申請審核，並確認資料無誤，\n" .
    "您的 UNLIGHT ID 綁定已正式通過 ✅\n\n" .
    "即日起，您可登入 ULGG 戰績網 使用完整功能，包括：\n" .
    "・個人對戰紀錄查詢\n" .
    "・角色／隊伍統計分析\n" .
    "・相關進階分析功能\n\n" .
    "請由以下網址前往：\n" .
    "https://ulgg.online/\n\n" .
    "若您後續需要重新調整綁定資訊，\n" .
    "或對功能有任何建議與疑問，歡迎再次提出申請。\n\n" .
    "本信件為系統自動通知，請勿直接回覆。\n\n" .
    "敬祝 遊戲愉快\n" .
    "ULGG 戰績網\n" .
    "Unlight Fan-made Statistics";


  sendSystemMail(
    $email,
    $user['username'],
    $subject,
    $body
  );
}
// ===== 審核退回後寄信（含原因）=====
if ($result === 'reject' && !empty($user['email'])) {

  $subject = '【UL.GG】UNLIGHT ID 綁定申請未通過';

  $body =
    "您好 {$user['username']}：\n\n" .
    "很抱歉，您的 UNLIGHT ID 綁定申請未通過審核。\n\n" .
    "原因如下：\n" .
    ($note ?: '（未提供說明）') . "\n\n" .
    "您可重新提交申請：\n" .
    "https://ulgg.online/pages/bind_ulid.php\n\n" .
    "— UL.GG 戰績網";

  sendSystemMail(
    $user['email'],
    $user['username'],
    $subject,
    $body
  );
}

// 6. 回傳 JSON
echo json_encode(['success' => true]);
exit;
