<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Body-less workflow endpoints: resolve the post (404 when unknown), then ask
 * BlogPostPolicy for the subclass's ability (403 when refused).
 */
abstract class BlogPostAbilityRequest extends FormRequest
{
    private ?BlogPost $resolvedPost = null;

    abstract protected function ability(): string;

    protected function includeTrashed(): bool
    {
        return false;
    }

    public function blogPost(): BlogPost
    {
        if ($this->resolvedPost === null) {
            $query = BlogPost::query();
            if ($this->includeTrashed()) {
                $query->withTrashed();
            }
            $this->resolvedPost = $query->findOrFail((int) $this->route('post'));
        }

        return $this->resolvedPost;
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->ability(), $this->blogPost());
    }

    public function rules(): array
    {
        return [];
    }
}
