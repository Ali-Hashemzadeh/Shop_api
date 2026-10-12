<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Route;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogAnalyticsController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogCommentController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogCurationController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogEditorialController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogPostController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogTaxonomyController;
use Modules\Blog\Infrastructure\Http\Controllers\Admin\AdminBlogWorkflowController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogCommentController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogCurationController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogEngagementController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogFeedController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogHomeController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogPostController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogPreviewController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogSubscriptionController;
use Modules\Blog\Infrastructure\Http\Controllers\BlogTaxonomyController;

$commentCode = PublicCodeGenerator::routePattern(PublicCodeEntity::BlogComment);

// ── Public storefront reads (+ anonymous engagement tracking) ─────────────────
// No auth middleware: guests are never 401/403. A bearer token, when present, is
// read optionally (is_liked / is_bookmarked / is_mine).
Route::middleware(['api', 'throttle:public'])
    ->prefix('api/v1/blog')
    ->group(function () {
        Route::get('/', BlogHomeController::class);

        Route::get('posts', [BlogPostController::class, 'index']);
        Route::get('posts/{slug}', [BlogPostController::class, 'show']);
        Route::get('posts/{slug}/related', [BlogPostController::class, 'related']);
        Route::get('posts/{slug}/comments', [BlogCommentController::class, 'index']);
        Route::post('posts/{slug}/views', [BlogPostController::class, 'recordView']);
        Route::post('posts/{slug}/product-clicks', [BlogPostController::class, 'recordProductClick']);

        Route::get('categories', [BlogTaxonomyController::class, 'categories']);
        Route::get('categories/{slug}', [BlogTaxonomyController::class, 'category']);
        Route::get('categories/{slug}/posts', [BlogTaxonomyController::class, 'categoryPosts']);

        Route::get('tags', [BlogTaxonomyController::class, 'tags']);
        Route::get('tags/{slug}', [BlogTaxonomyController::class, 'tag']);
        Route::get('tags/{slug}/posts', [BlogTaxonomyController::class, 'tagPosts']);

        Route::get('authors', [BlogTaxonomyController::class, 'authors']);
        Route::get('authors/{slug}', [BlogTaxonomyController::class, 'author']);
        Route::get('authors/{slug}/posts', [BlogTaxonomyController::class, 'authorPosts']);

        Route::get('collections', [BlogCurationController::class, 'collections']);
        Route::get('collections/{slug}', [BlogCurationController::class, 'collection']);
        Route::get('placements/{key}', [BlogCurationController::class, 'placements'])->where('key', '[a-z0-9_.]+');

        Route::get('feed', [BlogFeedController::class, 'feed']);
        Route::get('sitemap', [BlogFeedController::class, 'sitemap']);

        // Signed, time-limited preview of one unpublished post (see preview-link).
        Route::get('preview/{post}', BlogPreviewController::class)
            ->whereNumber('post')
            ->middleware('signed')
            ->name('blog.preview');
    });

// ── Authenticated reader self-service ─────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/blog')
    ->group(function () use ($commentCode) {
        // Writing is additionally bounded per user by the `blog-comments` limiter.
        Route::post('posts/{slug}/comments', [BlogCommentController::class, 'store'])->middleware('throttle:blog-comments');
        Route::post('comments/{publicCode}/replies', [BlogCommentController::class, 'reply'])->where('publicCode', $commentCode)->middleware('throttle:blog-comments');
        Route::post('comments/{publicCode}/report', [BlogCommentController::class, 'report'])->where('publicCode', $commentCode)->middleware('throttle:blog-comments');
        Route::patch('comments/{publicCode}', [BlogCommentController::class, 'update'])->where('publicCode', $commentCode);
        Route::delete('comments/{publicCode}', [BlogCommentController::class, 'destroy'])->where('publicCode', $commentCode);
        Route::post('comments/{publicCode}/reactions', [BlogCommentController::class, 'react'])->where('publicCode', $commentCode);
        Route::delete('comments/{publicCode}/reactions', [BlogCommentController::class, 'unreact'])->where('publicCode', $commentCode);

        Route::post('posts/{slug}/like', [BlogEngagementController::class, 'like']);
        Route::delete('posts/{slug}/like', [BlogEngagementController::class, 'unlike']);
        Route::post('posts/{slug}/bookmark', [BlogEngagementController::class, 'bookmark']);
        Route::delete('posts/{slug}/bookmark', [BlogEngagementController::class, 'unbookmark']);
        Route::get('bookmarks', [BlogEngagementController::class, 'bookmarks']);

        Route::get('subscriptions', [BlogSubscriptionController::class, 'index']);
        Route::post('subscriptions', [BlogSubscriptionController::class, 'store']);
        Route::delete('subscriptions/{subscription}', [BlogSubscriptionController::class, 'destroy'])->whereNumber('subscription');
    });

// ── Admin / editorial back office ─────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/blog')
    ->group(function () {
        // Posts.
        Route::get('posts', [AdminBlogPostController::class, 'index']);
        Route::post('posts', [AdminBlogPostController::class, 'store']);
        Route::get('posts/{post}', [AdminBlogPostController::class, 'show'])->whereNumber('post');
        Route::patch('posts/{post}', [AdminBlogPostController::class, 'update'])->whereNumber('post');
        Route::delete('posts/{post}', [AdminBlogPostController::class, 'destroy'])->whereNumber('post');
        Route::post('posts/{post}/restore', [AdminBlogPostController::class, 'restore'])->whereNumber('post');
        Route::get('posts/{post}/activity', [AdminBlogPostController::class, 'activity'])->whereNumber('post');

        // Workflow — one endpoint per business action.
        Route::post('posts/{post}/submit-review', [AdminBlogWorkflowController::class, 'submitReview'])->whereNumber('post');
        Route::post('posts/{post}/return-to-draft', [AdminBlogWorkflowController::class, 'returnToDraft'])->whereNumber('post');
        Route::post('posts/{post}/approve', [AdminBlogWorkflowController::class, 'approve'])->whereNumber('post');
        Route::post('posts/{post}/schedule', [AdminBlogWorkflowController::class, 'schedule'])->whereNumber('post');
        Route::post('posts/{post}/publish', [AdminBlogWorkflowController::class, 'publish'])->whereNumber('post');
        Route::post('posts/{post}/unpublish', [AdminBlogWorkflowController::class, 'unpublish'])->whereNumber('post');
        Route::post('posts/{post}/archive', [AdminBlogWorkflowController::class, 'archive'])->whereNumber('post');

        // Revisions, notes, autosave, preview.
        Route::get('posts/{post}/revisions', [AdminBlogEditorialController::class, 'revisions'])->whereNumber('post');
        Route::get('posts/{post}/revisions/{revision}', [AdminBlogEditorialController::class, 'revision'])->whereNumber(['post', 'revision']);
        Route::post('posts/{post}/revisions/{revision}/restore', [AdminBlogEditorialController::class, 'restoreRevision'])->whereNumber(['post', 'revision']);
        Route::get('posts/{post}/notes', [AdminBlogEditorialController::class, 'notes'])->whereNumber('post');
        Route::post('posts/{post}/notes', [AdminBlogEditorialController::class, 'storeNote'])->whereNumber('post');
        Route::patch('posts/{post}/notes/{note}', [AdminBlogEditorialController::class, 'updateNote'])->whereNumber(['post', 'note']);
        Route::get('posts/{post}/autosave', [AdminBlogEditorialController::class, 'autosave'])->whereNumber('post');
        Route::put('posts/{post}/autosave', [AdminBlogEditorialController::class, 'saveAutosave'])->whereNumber('post');
        Route::post('posts/{post}/preview-link', [AdminBlogEditorialController::class, 'previewLink'])->whereNumber('post');

        // Categories / tags / authors.
        Route::get('categories', [AdminBlogTaxonomyController::class, 'categories']);
        Route::get('categories/tree', [AdminBlogTaxonomyController::class, 'categoryTree']);
        Route::post('categories', [AdminBlogTaxonomyController::class, 'storeCategory']);
        Route::patch('categories/{category}', [AdminBlogTaxonomyController::class, 'updateCategory'])->whereNumber('category');
        Route::delete('categories/{category}', [AdminBlogTaxonomyController::class, 'destroyCategory'])->whereNumber('category');

        Route::get('tags', [AdminBlogTaxonomyController::class, 'tags']);
        Route::post('tags', [AdminBlogTaxonomyController::class, 'storeTag']);
        Route::patch('tags/{tag}', [AdminBlogTaxonomyController::class, 'updateTag'])->whereNumber('tag');
        Route::delete('tags/{tag}', [AdminBlogTaxonomyController::class, 'destroyTag'])->whereNumber('tag');

        Route::get('authors', [AdminBlogTaxonomyController::class, 'authors']);
        Route::post('authors', [AdminBlogTaxonomyController::class, 'storeAuthor']);
        Route::patch('authors/{author}', [AdminBlogTaxonomyController::class, 'updateAuthor'])->whereNumber('author');
        Route::delete('authors/{author}', [AdminBlogTaxonomyController::class, 'destroyAuthor'])->whereNumber('author');

        // Collections / placements.
        Route::get('collections', [AdminBlogCurationController::class, 'collections']);
        Route::post('collections', [AdminBlogCurationController::class, 'storeCollection']);
        Route::get('collections/{collection}', [AdminBlogCurationController::class, 'showCollection'])->whereNumber('collection');
        Route::patch('collections/{collection}', [AdminBlogCurationController::class, 'updateCollection'])->whereNumber('collection');
        Route::delete('collections/{collection}', [AdminBlogCurationController::class, 'destroyCollection'])->whereNumber('collection');

        Route::get('placements', [AdminBlogCurationController::class, 'placements']);
        Route::post('placements', [AdminBlogCurationController::class, 'storePlacement']);
        Route::patch('placements/{placement}', [AdminBlogCurationController::class, 'updatePlacement'])->whereNumber('placement');
        Route::delete('placements/{placement}', [AdminBlogCurationController::class, 'destroyPlacement'])->whereNumber('placement');

        // Comment moderation + reports.
        Route::get('comments', [AdminBlogCommentController::class, 'index']);
        Route::get('comments/{comment}', [AdminBlogCommentController::class, 'show'])->whereNumber('comment');
        Route::post('comments/{comment}/approve', [AdminBlogCommentController::class, 'approve'])->whereNumber('comment');
        Route::post('comments/{comment}/reject', [AdminBlogCommentController::class, 'reject'])->whereNumber('comment');
        Route::post('comments/{comment}/spam', [AdminBlogCommentController::class, 'spam'])->whereNumber('comment');
        Route::post('comments/{comment}/restore', [AdminBlogCommentController::class, 'restore'])->whereNumber('comment');
        Route::delete('comments/{comment}', [AdminBlogCommentController::class, 'destroy'])->whereNumber('comment');

        Route::get('comment-reports', [AdminBlogCommentController::class, 'reports']);
        Route::post('comment-reports/{report}/review', [AdminBlogCommentController::class, 'reviewReport'])->whereNumber('report');
        Route::post('comment-reports/{report}/dismiss', [AdminBlogCommentController::class, 'dismissReport'])->whereNumber('report');
        Route::post('comment-reports/{report}/action', [AdminBlogCommentController::class, 'actionReport'])->whereNumber('report');

        // Engagement report.
        Route::get('analytics', AdminBlogAnalyticsController::class);
    });
