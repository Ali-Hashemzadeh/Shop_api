<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;

/**
 * Rejects a draft. A rejected draft is kept for audit and never published. An
 * already-published (approved) draft cannot be rejected here — unpublishing a
 * live review is a separate, deliberate action.
 */
class RejectDraftAction
{
    public function __construct(
        private readonly GenerationLogWriter $log,
    ) {}

    public function handle(int $draftId, int $adminUserId): AiGeneratedReview
    {
        /** @var AiGeneratedReview $draft */
        $draft = AiGeneratedReview::query()->findOrFail($draftId);

        if ($draft->status === DraftStatus::Approved) {
            abort(422, 'An approved draft has already been published and cannot be rejected.');
        }

        return DB::transaction(function () use ($draft, $adminUserId): AiGeneratedReview {
            $draft->update(['status' => DraftStatus::Rejected->value]);

            $this->log->success($draft->generation_id, GenerationLogType::Rejection, [
                'draft_id' => $draft->id,
                'rejected_by' => $adminUserId,
            ]);

            return $draft->refresh();
        });
    }
}
