<?php
// lib/mail_helper.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * 發送系統信件（Gmail SMTP）
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $bodyText  純文字內容
 * @return bool
 */
function sendSystemMail(string $toEmail, string $toName, string $subject, string $bodyText): bool
{
    $mail = new PHPMailer(true);

    try {
        // ===== SMTP 設定 =====
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ulgg.online@gmail.com';   // 你的 Gmail
        $mail->Password   = 'vuzwapaewybqyrtj';     // ⭐ 換成你剛拿到的
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // ===== 寄件者 =====
        $mail->setFrom('ulgg.online@gmail.com', 'UL.GG 戰績網');

        // ===== 收件者 =====
        $mail->addAddress($toEmail, $toName);

        // ===== 信件內容 =====
        $mail->isHTML(false); // 純文字（先用這個，最穩）
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;

        $mail->send();
        return true;

    } catch (Exception $e) {
        // 可選：寫 log
        error_log('[MAIL ERROR] ' . $mail->ErrorInfo);
        return false;
    }
}
