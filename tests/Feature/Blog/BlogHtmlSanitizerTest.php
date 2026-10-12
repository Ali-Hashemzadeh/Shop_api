<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Server-side rich-text sanitization: dangerous markup never survives, normal
 * editor HTML (including Persian/RTL) does, and the stored value is what the
 * API returns.
 */
class BlogHtmlSanitizerTest extends BlogTestCase
{
    private function clean(string $html): string
    {
        return app(BlogHtmlSanitizerInterface::class)->sanitize($html);
    }

    /** @test */
    public function scripts_event_handlers_styles_and_frames_are_removed(): void
    {
        $dirty = '<p onclick="alert(1)" style="color:red">Hi<script>alert("x")</script></p>'
            .'<img src="x" onerror="alert(1)">'
            .'<iframe src="https://evil.test"></iframe>'
            .'<style>body{display:none}</style>'
            .'<svg onload="alert(1)"><circle/></svg>'
            .'<form action="https://evil.test"><input name="card"></form>'
            .'<object data="evil.swf"></object>';

        $clean = $this->clean($dirty);

        foreach (['<script', 'alert', 'onclick', 'onerror', 'onload', 'style=', '<iframe', '<style', '<svg', '<form', '<input', '<object'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean, "{$needle} survived sanitization");
        }
        $this->assertStringContainsString('<p>Hi</p>', $clean);
    }

    /** @test */
    public function javascript_and_data_urls_are_stripped_from_links_and_images(): void
    {
        $clean = $this->clean(
            '<a href="javascript:alert(1)">x</a>'
            .'<a href="JaVaScRiPt:alert(1)">y</a>'
            .'<a href="data:text/html;base64,PHNjcmlwdD4=">z</a>'
            .'<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="d">'
            .'<img src="javascript:alert(1)" alt="j">'
        );

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        $this->assertStringNotContainsStringIgnoringCase('data:', $clean);
    }

    /** @test */
    public function links_are_forced_to_a_safe_rel_even_with_target_blank(): void
    {
        $clean = $this->clean('<a href="https://example.com" target="_blank" rel="opener">ok</a>');

        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringNotContainsString('rel="opener"', $clean);
    }

    /** @test */
    public function normal_editor_html_survives_including_rtl_tables_and_code(): void
    {
        $html = '<h2 dir="rtl">راهنمای خرید</h2>'
            .'<p dir="rtl">متن <strong>پررنگ</strong> و <em>کج</em> و <u>زیرخط</u> و <s>خط‌خورده</s></p>'
            .'<ul><li>یک</li><li>دو</li></ul><ol><li>first</li></ol>'
            .'<blockquote>quote</blockquote>'
            .'<figure><img src="https://cdn.example.com/a.jpg" alt="phone" width="600" height="400"><figcaption>caption</figcaption></figure>'
            .'<table><thead><tr><th scope="col">Model</th></tr></thead><tbody><tr><td colspan="2">X</td></tr></tbody></table>'
            .'<hr><pre class="language-php"><code class="language-php">echo 1;</code></pre>'
            .'<a href="/blog/other-post">relative</a> <a href="mailto:hi@example.com">mail</a>';

        $clean = $this->clean($html);

        foreach ([
            '<h2 dir="rtl">راهنمای خرید</h2>', '<strong>پررنگ</strong>', '<em>کج</em>', '<u>زیرخط</u>', '<s>خط‌خورده</s>',
            '<li>یک</li>', '<blockquote>quote</blockquote>', '<figcaption>caption</figcaption>',
            'src="https://cdn.example.com/a.jpg"', 'alt="phone"', '<th scope="col">Model</th>', '<td colspan="2">X</td>',
            '<hr', '<code class="language-php">echo 1;</code>', 'href="/blog/other-post"', 'href="mailto:hi',
        ] as $expected) {
            $this->assertStringContainsString($expected, $clean);
        }
    }

    /** @test */
    public function long_articles_are_not_truncated(): void
    {
        $paragraph = '<p>'.str_repeat('متن طولانی ', 200).'</p>';
        $html = str_repeat($paragraph, 30); // ~130 KB, far beyond the vendor's 20 KB default

        $this->assertSame(substr_count($html, '<p>'), substr_count($this->clean($html), '<p>'));
    }

    /** @test */
    public function content_is_sanitized_before_persistence_through_the_api(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'content' => '<p>Safe</p><script>steal()</script><img src=x onerror=steal()>',
        ]))->assertCreated()->json('data.id');

        $stored = BlogPost::find($id)->content;
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('<p>Safe</p>', $stored);

        $this->patchJson("/api/v1/admin/blog/posts/{$id}", ['content' => '<h3 onmouseover="x()">Edit</h3>', 'version' => 1])->assertOk();
        $this->assertSame('<h3>Edit</h3>', BlogPost::find($id)->content);

        // Autosave content is sanitized too.
        $this->putJson("/api/v1/admin/blog/posts/{$id}/autosave", ['content' => '<p>draft</p><script>x</script>'])
            ->assertOk()->assertJsonPath('data.content', '<p>draft</p>');

        $post = $this->makePost(BlogPostStatus::Published, ['content' => BlogPost::find($id)->content]);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertJsonPath('data.content', '<h3>Edit</h3>');
    }
}
