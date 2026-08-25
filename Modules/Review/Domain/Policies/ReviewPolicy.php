<?php

declare(strict_types=1);

namespace Modules\Review\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Review\Domain\Models\Review;

/**
 * Reviews are self-service writes: a customer edits their own review only.
 * Permission-based and typehinted against framework contracts — never against
 * another module's User model. Store/admin surfaces are gated by their Form
 * Requests (`review.create`, `review.view-admin`, `review.moderate`); unlike
 * the delivery-driver pattern this is a standard policy 403 for non-owners,
 * because an owner may always address their own review.
 */
class ReviewPolicy
{
    public function update(Authorizable&Authenticatable $user, Review $review): bool
    {
        return $user->can('review.create') && $this->owns($user, $review);
    }

    private function owns(Authenticatable $user, Review $review): bool
    {
        return (int) $user->getAuthIdentifier() === (int) $review->user_id;
    }
}
