<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModule extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'module:make {name : The name of the module (e.g., Media, Catalog)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold a complete, isolated business module structure';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $moduleName = Str::studly($this->argument('name'));
        $basePath = base_path("Modules/{$moduleName}");

        if (File::isDirectory($basePath)) {
            $this->error("Module '{$moduleName}' already exists at {$basePath}!");

            return;
        }

        $this->info("Creating architecture for module: {$moduleName}...");

        // 1. Define the standard project architecture layout
        $directories = [
            "{$basePath}/Application/Actions",
            "{$basePath}/Domain/Contracts",
            "{$basePath}/Domain/DTOs",
            "{$basePath}/Domain/Models",
            "{$basePath}/Domain/Policies",
            "{$basePath}/Infrastructure/Http/Controllers",
            "{$basePath}/Infrastructure/Http/Requests",
            "{$basePath}/Infrastructure/Http/Resources",
            "{$basePath}/Infrastructure/Persistence/Migrations",
            "{$basePath}/Infrastructure/Persistence/Repositories",
            "{$basePath}/Infrastructure/Providers",
            "{$basePath}/Infrastructure/Routes",
        ];

        // 2. Physically generate directories
        foreach ($directories as $directory) {
            File::makeDirectory($directory, 0755, true, true);
        }

        // 3. Generate the isolated Route file boilerplate
        $this->createApiRouteFile($basePath, $moduleName);

        // 4. Generate the Service Provider boilerplate
        $this->createServiceProviderFile($basePath, $moduleName);

        $this->components->info("Module '{$moduleName}' generated successfully!");
        $this->line('');
        $this->comment('Next steps:');
        $this->line("1. Register Modules\\{$moduleName}\\Infrastructure\\Providers\\{$moduleName}ServiceProvider::class in your application providers list.");
        $this->line('2. Start defining your isolated contracts and domain models!');
    }

    /**
     * Build the foundational route scaffolding.
     */
    protected function createApiRouteFile(string $basePath, string $moduleName): void
    {
        $slug = Str::kebab($moduleName);
        $content = <<<PHP
<?php

use Illuminate\Support\Facades\Route;

// Private or Public routes for the {$moduleName} module
Route::prefix('v1/{$slug}')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => '{$moduleName} module is functional']);
    });
});
PHP;

        File::put("{$basePath}/Infrastructure/Routes/api.php", $content);
    }

    /**
     * Build the foundational service provider boilerplate.
     */
    protected function createServiceProviderFile(string $basePath, string $moduleName): void
    {
        $content = <<<PHP
<?php

namespace Modules\\{$moduleName}\\Infrastructure\\Providers;

use Illuminate\Support\ServiceProvider;

class {$moduleName}ServiceProvider extends ServiceProvider
{
    /**
     * Register any module services.
     */
    public function register(): void
    {
        // Bind contracts to repositories here
    }

    /**
     * Bootstrap any module services.
     */
    public function boot(): void
    {
        \$this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        \$this->loadMigrationsFrom(__DIR__ . '/../Persistence/Migrations');
    }
}
PHP;

        File::put("{$basePath}/Infrastructure/Providers/{$moduleName}ServiceProvider.php", $content);
    }
}
