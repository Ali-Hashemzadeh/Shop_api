<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogEditorialNote;

/**
 * Resolve/reopen a note (any collaborator) and/or edit its body (the note's
 * author only — enforced by the request).
 */
final class UpdateBlogEditorialNoteAction
{
    public function handle(BlogEditorialNote $note, ?bool $resolved, ?string $body): BlogEditorialNote
    {
        if ($resolved !== null) {
            $note->resolved_at = $resolved ? ($note->resolved_at ?? now()) : null;
        }

        if ($body !== null) {
            $note->body = $body;
        }

        $note->save();

        return $note->fresh();
    }
}
