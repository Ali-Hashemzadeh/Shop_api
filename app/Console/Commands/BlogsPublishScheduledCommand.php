<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Blog\Application\Actions\ProcessScheduledBlogPostsAction;

class BlogsPublishScheduledCommand extends Command
{
    protected $signature = 'blogs:publish-scheduled';

    protected $description = 'Publish Blog posts whose scheduled time has come and unpublish posts whose unpublish_at is due (idempotent).';

    public function handle(ProcessScheduledBlogPostsAction $action): int
    {
        $result = $action->handle();
        $this->info("Published {$result['published']} scheduled post(s); unpublished {$result['unpublished']} expired post(s).");

        return Command::SUCCESS;
    }
}
