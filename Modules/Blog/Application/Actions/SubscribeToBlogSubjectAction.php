<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Enums\BlogSubscriptionSubject;
use Modules\Blog\Domain\Models\BlogSubscription;

/**
 * Follow a Blog category, tag, or author. Idempotent: following the same
 * subject twice returns the existing subscription (`created = false`). The
 * subject was validated to exist and be active by the request.
 *
 * @return array{subscription: BlogSubscription, created: bool}
 */
final class SubscribeToBlogSubjectAction
{
    /** @return array{subscription: BlogSubscription, created: bool} */
    public function handle(int $userId, BlogSubscriptionSubject $type, int $subjectId): array
    {
        $subscription = BlogSubscription::query()->firstOrCreate([
            'user_id' => $userId,
            'subject_type' => $type->value,
            'subject_id' => $subjectId,
        ]);

        return ['subscription' => $subscription, 'created' => $subscription->wasRecentlyCreated];
    }
}
