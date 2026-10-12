<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogAuthorRole;
use Modules\Blog\Domain\Enums\BlogPostMediaRole;
use Modules\Blog\Domain\Enums\BlogProductRelationType;
use Modules\Blog\Domain\Services\BlogSlugger;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Shared validation for post create/update.
 *
 * - Same-module references (categories, tags, authors, related posts) use
 *   `exists` against Blog's own tables.
 * - Media ids are checked through MediaManagerInterface and product ids through
 *   CatalogManagerInterface — one batch call each, never a cross-module query.
 * - `public_code`, `status`, `version` bumps, timestamps, and audit columns are
 *   never accepted from input (they are absent from the rules, so validated()
 *   cannot carry them).
 */
trait BlogPostRules
{
    /** @return array<string, mixed> */
    protected function postRules(bool $creating, ?int $ignorePostId = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:180',
                'regex:'.BlogSlugger::PATTERN,
                Rule::unique('blog_posts', 'slug')->ignore($ignorePostId),
                function (string $attribute, mixed $value, \Closure $fail) use ($ignorePostId): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }
                    if (BlogSlugger::collidesWithPublicCode($value)) {
                        $fail('The slug may not look like an article public code.');

                        return;
                    }
                    $owner = DB::table('blog_post_slug_history')->where('slug', $value)->value('blog_post_id');
                    if ($owner !== null && (int) $owner !== (int) $ignorePostId) {
                        $fail('This slug previously belonged to another article and is reserved for its redirect.');
                    }
                },
            ],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'content' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('blog.content.max_length', 1_000_000)],
            'featured_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'og_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'canonical_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'og_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'og_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'robots_index' => ['sometimes', 'boolean'],
            'robots_follow' => ['sometimes', 'boolean'],
            'allow_comments' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'unpublish_at' => ['sometimes', 'nullable', 'date', 'after:now'],

            'category_ids' => ['sometimes', 'array', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('blog_categories', 'id')],
            'primary_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('blog_categories', 'id')],

            'tag_ids' => ['sometimes', 'array', 'max:50'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('blog_tags', 'id')],

            'authors' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'authors.*.author_id' => ['required', 'integer', 'distinct', Rule::exists('blog_authors', 'id')->where('is_active', true)],
            'authors.*.role' => ['sometimes', 'nullable', 'string', Rule::in(BlogAuthorRole::values())],

            'media' => ['sometimes', 'array', 'max:100'],
            'media.*.media_id' => ['required', 'integer', 'min:1'],
            'media.*.role' => ['sometimes', 'nullable', 'string', Rule::in(BlogPostMediaRole::values())],
            'media.*.alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'media.*.caption' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'products' => ['sometimes', 'array', 'max:50'],
            'products.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'products.*.relation_type' => ['sometimes', 'nullable', 'string', Rule::in(BlogProductRelationType::values())],

            'related_post_ids' => ['sometimes', 'array', 'max:20'],
            'related_post_ids.*' => [
                'integer', 'distinct', Rule::exists('blog_posts', 'id'),
                function (string $attribute, mixed $value, \Closure $fail) use ($ignorePostId): void {
                    if ($ignorePostId !== null && (int) $value === $ignorePostId) {
                        $fail('A post cannot be related to itself.');
                    }
                },
            ],
        ];
    }

    /** Cross-module + composite checks, run after the shape rules. */
    protected function validatePostReferences(Validator $validator, ?array $currentCategoryIds = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        // Primary category must be one of the post's categories.
        if ($this->filled('primary_category_id')) {
            $categories = $this->has('category_ids')
                ? array_map('intval', (array) $this->input('category_ids'))
                : ($currentCategoryIds ?? []);
            if (! in_array((int) $this->input('primary_category_id'), $categories, true)) {
                $validator->errors()->add('primary_category_id', 'The primary category must be one of the post\'s categories.');
            }
        }

        // At least one credited `author` among the contributors.
        if ($this->has('authors')) {
            $hasAuthor = collect((array) $this->input('authors'))
                ->contains(fn ($a) => is_array($a) && (($a['role'] ?? null) === null || $a['role'] === BlogAuthorRole::Author->value));
            if (! $hasAuthor) {
                $validator->errors()->add('authors', 'At least one contributor must have the "author" role.');
            }
        }

        // Every Media reference must exist — one batch lookup via the Media contract.
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', [
            $this->input('featured_media_id'),
            $this->input('og_media_id'),
            ...collect((array) $this->input('media', []))->pluck('media_id')->all(),
        ]))));
        if ($mediaIds !== []) {
            $found = app(MediaManagerInterface::class)->getMediaCollection($mediaIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($mediaIds, $found);
            foreach (['featured_media_id', 'og_media_id'] as $field) {
                if ($this->filled($field) && in_array((int) $this->input($field), $missing, true)) {
                    $validator->errors()->add($field, 'The selected media does not exist.');
                }
            }
            foreach ((array) $this->input('media', []) as $index => $row) {
                if (is_array($row) && in_array((int) ($row['media_id'] ?? 0), $missing, true)) {
                    $validator->errors()->add("media.{$index}.media_id", 'The selected media does not exist.');
                }
            }
        }

        // Every product reference must exist — one batch lookup via the Catalog contract.
        $productIds = collect((array) $this->input('products', []))->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($productIds !== []) {
            $existing = app(CatalogManagerInterface::class)->getExistingProductIds($productIds);
            foreach ((array) $this->input('products', []) as $index => $row) {
                if (is_array($row) && ! in_array((int) ($row['product_id'] ?? 0), $existing, true)) {
                    $validator->errors()->add("products.{$index}.product_id", 'The selected product does not exist.');
                }
            }
        }
    }
}
