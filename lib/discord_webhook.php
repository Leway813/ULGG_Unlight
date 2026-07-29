<?php
/**
 * Discord Webhook Helper
 * ======================
 * - 只提供 function
 * - 不輸出任何文字
 * - 不自動執行
 * - 可安全被 require_once
 */

declare(strict_types=1);

/**
 * 發送 Discord Webhook
 *
 * @param string $webhookUrl Discord Webhook URL
 * @param array  $payload    Discord payload（會自動轉 JSON）
 * @param int    $timeout    逾時秒數（預設 5 秒）
 *
 * @return bool true = 已嘗試送出（不代表 Discord 一定成功）
 */
function sendDiscordWebhook(string $webhookUrl, array $payload, int $timeout = 5): bool
{
    if (empty($webhookUrl)) {
        return false;
    }

    $ch = curl_init($webhookUrl);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    try {
        curl_exec($ch);
    } catch (Throwable $e) {
        // 不要 echo、不影響前端
        error_log('[DiscordWebhook] ' . $e->getMessage());
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return true;
}
