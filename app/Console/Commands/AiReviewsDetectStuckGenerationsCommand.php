<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\ProductReviewAI\Application\Actions\DetectStuckGenerationsAction;

/**
 * Scheduled (every five minutes, routes/console.php) by Laravel's scheduler —
 * which is a different mechanism from the queue worker that runs the
 * generations themselves. Safe to run on several servers and repeatedly.
 */
class AiReviewsDetectStuckGenerationsCommand extends Command
{
    protected $signature = 'ai-reviews:detect-stuck-generations
                            {--dry-run : Report suspected/abandoned generations without changing any state}';

    protected $description = 'Detect AI review generations stuck in pending/processing; conservatively fail runs that are provably abandoned.';

    public function handle(DetectStuckGenerationsAction $action): int
    {
        $result = $action->handle((bool) $this->option('dry-run'));

        $this->info("Inspected {$result['inspected']} active generation(s); {$result['suspected']} suspected stuck; {$result['reconciled']} reconciled as failed.");

        if (! $result['auto_fail_enabled']) {
            $this->comment('Automatic failing is off (dry run, disabled, or processing_timeout_seconds is not above queue.job_timeout): state was not changed.');
        }

        return Command::SUCCESS;
    }
}
