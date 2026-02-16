<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Alerts\AlertManager;

class AlertsCheckCommand extends Command
{
    protected $signature = 'station:alerts:check {--seed : Seed default channels and rules from config}';

    protected $description = 'Evaluate alert rules and send notifications for triggered alerts';

    public function handle(AlertManager $alertManager): int
    {
        if ($this->option('seed')) {
            $seeded = $alertManager->seedFromConfig();
            $this->info("Seeded channels and {$seeded} alert rule(s) from config.");
        }

        $triggered = $alertManager->evaluate();

        if ($triggered === []) {
            $this->info('No alerts triggered.');

            return self::SUCCESS;
        }

        foreach ($triggered as $alert) {
            $this->warn("[{$alert->severity->label()}] {$alert->rule_name}: {$alert->message}");
        }

        $this->info(\sprintf('%d alert(s) triggered.', \count($triggered)));

        return self::SUCCESS;
    }
}
