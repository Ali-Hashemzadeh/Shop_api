<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReviewVersion;

/**
 * Edits a draft's name / rating / title / body. Each edit snapshots the prior
 * content into the append-only version history and moves the draft to `edited`.
 * A published (approved) draft can no longer be edited.
 */
class UpdateDraftAction
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(int $draftId, int $adminUserId, array $changes): AiGeneratedReview
    {
        /** @var AiGeneratedReview $draft */
        $draft = AiGeneratedReview::query()->findOrFail($draftId);

        if ($draft->status === DraftStatus::Approved) {
            abort(422, 'An approved draft has already been published and cannot be edited.');
        }

        return DB::transaction(function () use ($draft, $adminUserId, $changes): AiGeneratedReview {
            // Preserve the pre-edit content as a version.
            AiGeneratedReviewVersion::query()->create([
                'generated_review_id' => $draft->id,
                'content' => [
                    'name' => $draft->name,
                    'rating' => $draft->rating,
                    'title' => $draft->title,
                    'body' => $draft->body,
                    'status' => $draft->status->value,
                ],
                'changed_by' => $adminUserId,
                'created_at' => now(),
            ]);

            foreach (['name', 'rating', 'title', 'body'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $draft->{$field} = $changes[$field];
                }
            }

            $draft->status = DraftStatus::Edited->value;
            $draft->save();

            return $draft->refresh();
        });
    }
}
