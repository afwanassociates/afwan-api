<?php

namespace App\Console\Commands;

use App\Services\WorkflowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflow:recompute-stages {--chunk=500 : Passports per chunk}')]
#[Description('Recompute every passport\'s stored workflow stage, status and status date from its records')]
class RecomputeWorkflowStages extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WorkflowService $workflow): int
    {
        $count = $workflow->recomputeAll(max(1, (int) $this->option('chunk')));

        $this->info("Recomputed {$count} passports.");

        return self::SUCCESS;
    }
}
