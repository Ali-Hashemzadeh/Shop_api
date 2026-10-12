<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Routing\Route;
use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Infrastructure\Providers\BlogServiceProvider;
use Symfony\Component\Finder\Finder;

/**
 * Static guards for the enclosure rules: the module is registered, imports no
 * other module's models, owns its tables only, never FKs across the wall, never
 * hard-codes a public-code prefix, and throttles every route.
 */
class BlogArchitectureTest extends BlogTestCase
{
    /** @return array<string, string> path => contents */
    private function blogSources(): array
    {
        $files = [];
        foreach ((new Finder)->files()->in(base_path('Modules/Blog'))->name('*.php') as $file) {
            $files[$file->getRelativePathname()] = $file->getContents();
        }

        return $files;
    }

    /** @test */
    public function the_provider_is_registered_and_contracts_resolve(): void
    {
        $this->assertContains(BlogServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(BlogManagerInterface::class, app(BlogManagerInterface::class));
        $this->assertInstanceOf(BlogHtmlSanitizerInterface::class, app(BlogHtmlSanitizerInterface::class));
    }

    /** @test */
    public function blog_public_code_segments_are_registered_and_unique(): void
    {
        $this->assertSame('b', PublicCodeEntity::BlogPost->value);
        $this->assertSame('m', PublicCodeEntity::BlogComment->value);

        $segments = array_map(fn (PublicCodeEntity $e) => $e->value, PublicCodeEntity::cases());
        $this->assertSame($segments, array_values(array_unique($segments)), 'segments must be unique');

        $code = PublicCodeGenerator::generate(PublicCodeEntity::BlogPost);
        $this->assertMatchesRegularExpression('/^bdb-['.PublicCodeGenerator::ALPHABET.']{6}$/', $code);
        $this->assertTrue(PublicCodeGenerator::matches(strtolower($code), PublicCodeEntity::BlogPost));
        $this->assertFalse(PublicCodeGenerator::matches($code, PublicCodeEntity::BlogComment));
    }

    /** @test */
    public function blog_never_hard_codes_a_public_code_prefix(): void
    {
        foreach ($this->blogSources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression("/['\"]bd[bm]-?/", $source, "{$path} hard-codes a public-code prefix");
        }
    }

    /** @test */
    public function blog_imports_no_other_modules_models(): void
    {
        foreach ($this->blogSources() as $path => $source) {
            preg_match_all('/^use (Modules\\\\(\w+)\\\\[^;]+);/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $import, $module]) {
                if ($module === 'Blog') {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/\\\\Domain\\\\Models\\\\/', $import, "{$path} imports {$import}");
                $this->assertMatchesRegularExpression('/\\\\Domain\\\\(Contracts|DTOs)\\\\/', $import, "{$path} may only use other modules' contracts/DTOs, found {$import}");
            }
        }
    }

    /** @test */
    public function other_modules_reach_blog_only_through_its_contract_and_events(): void
    {
        $finder = (new Finder)->files()->in(base_path('Modules'))->exclude('Blog')->name('*.php')->contains('Modules\\Blog\\');

        foreach ($finder as $file) {
            preg_match_all('/^use (Modules\\\\Blog\\\\[^;]+);/m', $file->getContents(), $matches);
            foreach ($matches[1] as $import) {
                $this->assertMatchesRegularExpression('/^Modules\\\\Blog\\\\Domain\\\\(Contracts|DTOs|Events)\\\\/', $import, "{$file->getRelativePathname()} imports {$import}");
            }
        }
    }

    /** @test */
    public function blog_queries_and_constrains_only_its_own_tables(): void
    {
        foreach ($this->blogSources() as $path => $source) {
            preg_match_all("/(?:DB::table|->from|->join|->leftJoin|constrained|Rule::exists|Rule::unique|->table)\\(\\s*'([a-z_]+)'/", $source, $matches);
            foreach ($matches[1] as $table) {
                $this->assertStringStartsWith('blog_', $table, "{$path} touches non-Blog table {$table}");
            }
        }

        foreach (glob(base_path('Modules/Blog/Infrastructure/Persistence/Migrations/*.php')) as $migration) {
            $this->assertStringNotContainsString("'media'", file_get_contents($migration), basename($migration).' references the media table');
            $this->assertStringNotContainsString("'products'", file_get_contents($migration));
            $this->assertStringNotContainsString("'users'", file_get_contents($migration));
        }
    }

    /** @test */
    public function every_blog_route_carries_a_named_throttle_and_writes_require_auth(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/blog') || str_starts_with($r->uri(), 'api/v1/admin/blog'));

        $this->assertGreaterThan(80, $routes->count());

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $throttles = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotEmpty($throttles, "{$route->uri()} has no named throttle");

            if (str_starts_with($route->uri(), 'api/v1/admin/blog')) {
                $this->assertContains('auth:sanctum', $middleware, "{$route->uri()} must be authenticated");
                $this->assertContains('throttle:api', $middleware);
            }

            $isPublicRead = in_array('throttle:public', $middleware, true);
            if ($isPublicRead) {
                $this->assertNotContains('auth:sanctum', $middleware, "{$route->uri()} is public and must not require auth");
            }
        }
    }
}
