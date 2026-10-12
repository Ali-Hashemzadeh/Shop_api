<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a view / product click should be counted.
 *
 *  - Known bots, crawlers, link-preview fetchers and scripted clients (by user
 *    agent, configurable pattern) are never counted; nor is an empty UA.
 *  - Each viewer is counted at most once per post (or post+product) per dedup
 *    window. `Cache::add` is atomic on every Laravel store, so two concurrent
 *    refreshes cannot both win, and a retried request inside the window is a
 *    no-op. Nothing about the viewer is stored except a hash in the cache key.
 */
final class BlogEngagementGate
{
    public static function isBot(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return true;
        }

        $pattern = (string) config('blog.engagement.bot_user_agent_pattern', '');

        return $pattern !== '' && preg_match('~'.str_replace('~', '\~', $pattern).'~i', $userAgent) === 1;
    }

    public static function viewerKey(?int $userId, ?string $sessionId, ?string $ip, ?string $userAgent): string
    {
        $identity = $userId !== null
            ? 'u:'.$userId
            : ($sessionId !== null && $sessionId !== '' ? 's:'.$sessionId : 'ip:'.$ip.'|'.$userAgent);

        return hash('sha256', $identity);
    }

    /** True the first time this key is seen inside the window. */
    public static function firstWithin(string $scope, string $viewerKey, int $minutes): bool
    {
        return Cache::add('blog:engagement:'.$scope.':'.$viewerKey, 1, now()->addMinutes(max(1, $minutes)));
    }
}
