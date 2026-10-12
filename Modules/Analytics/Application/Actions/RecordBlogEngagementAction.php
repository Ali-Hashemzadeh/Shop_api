<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Domain\Models\AnalyticsBlogDailyStat;
use Modules\Analytics\Domain\Models\AnalyticsProcessedEvent;

/**
 * Fold one Blog engagement event into the daily per-post read model.
 *
 * Same idempotency discipline as every other Analytics consumer: the event id
 * is checked (and recorded) in analytics_processed_events inside the same
 * transaction, so a retried or duplicated delivery is counted exactly once.
 */
class RecordBlogEngagementAction
{
    private const COUNTERS = ['views', 'likes', 'bookmarks', 'comments', 'product_clicks'];

    public function handle(string $eventId, string $eventName, int $postId, string $counter, ?string $occurredAt): void
    {
        if (! in_array($counter, self::COUNTERS, true)) {
            throw new \InvalidArgumentException("Unknown blog counter [{$counter}].");
        }

        $date = $occurredAt !== null ? Carbon::parse($occurredAt)->toDateString() : now()->toDateString();

        DB::transaction(function () use ($eventId, $eventName, $postId, $counter, $date): void {
            if (AnalyticsProcessedEvent::where('event_id', $eventId)->lockForUpdate()->exists()) {
                return;
            }

            $stat = AnalyticsBlogDailyStat::query()->lockForUpdate()->firstOrCreate(
                ['date' => $date, 'blog_post_id' => $postId],
                ['views' => 0, 'likes' => 0, 'bookmarks' => 0, 'comments' => 0, 'product_clicks' => 0],
            );
            $stat->increment($counter);

            AnalyticsProcessedEvent::create([
                'event_id' => $eventId,
                'event_name' => $eventName,
                'processed_at' => now(),
            ]);
        });
    }
}
