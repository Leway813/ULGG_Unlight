<?php

declare(strict_types=1);

require_once __DIR__ . '/summarize_local.php';

function getUnlightSteamNews(int $count = 5): array
{
    $count = max(1, min(10, $count));
    $query = http_build_query(
        [
            'appid' => 3247080,
            'count' => $count,
            'maxlength' => 5000,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $url = 'https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/?'
        . $query;

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'UL.GG Steam News Fetcher',
        ],
    ]);
    $json = @file_get_contents($url, false, $context);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $items = $data['appnews']['newsitems'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $allowedFeeds = [
        'steam_community',
        'steam_community_announcements',
        'steam_updates',
    ];
    $filtered = array_filter(
        $items,
        static function ($item) use ($allowedFeeds): bool {
            return is_array($item)
                && in_array(
                    $item['feedname'] ?? null,
                    $allowedFeeds,
                    true
                );
        }
    );

    $filtered = array_slice(array_values($filtered), 0, $count);

    foreach ($filtered as &$item) {
        $item['summary'] = localSummarize(
            (string)($item['contents'] ?? '')
        );
    }
    unset($item);

    return $filtered;
}
