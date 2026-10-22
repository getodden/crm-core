<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Odden\Core\Console\InstallSteps;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Models\Contact;
use Odden\Core\Models\DemoRecord;

class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:install
        {--skip-migrate : Do not run the migrations}
        {--demo : Load the sample data without asking}
        {--no-demo : Do not offer the sample data}
        {--schedule : Add the scheduled commands to routes/console.php without asking}
        {--no-schedule : Do not offer to edit routes/console.php}
        {--force : Run the migrations in production without asking}';

    /**
     * @var string
     */
    protected $description = 'Set up Odden: run the migrations, offer sample data, and check the scheduler, queue and admin panel';

    /** @var array<string, string> Scheduled commands per installed module, keyed by command name. */
    private const SCHEDULE = [
        'sales:process-cadences' => '->everyFifteenMinutes()->withoutOverlapping()',
        'sales:expire-quotes' => "->dailyAt('01:00')->withoutOverlapping()",
    ];

    public function handle(): int
    {
        $this->components->info('Setting up Odden.');
        $this->line('  Installed: '.implode(', ', $this->modules()).'.');
        $this->newLine();

        $this->migrate();
        $this->demo();
        $this->schedule();
        $this->checkQueueAndMail();
        $this->moduleSteps();

        $this->newLine();
        $this->components->info('Done. Anything above marked as a warning is worth a look before you go live.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function modules(): array
    {
        return array_map(fn ($seeder): string => $seeder->module(), app(DemoRegistry::class)->seeders());
    }

    private function migrate(): void
    {
        if ($this->option('skip-migrate')) {
            return;
        }

        $this->call('migrate', ['--force' => (bool) $this->option('force')]);
        $this->newLine();
    }

    private function demo(): void
    {
        if ($this->option('no-demo')) {
            return;
        }

        if (DemoRecord::exist()) {
            $this->components->twoColumnDetail('Sample data', 'already loaded (remove it with odden:demo --remove)');

            return;
        }

        $hasData = Contact::query()->exists();

        $load = $this->option('demo') || $this->confirm(
            $hasData
                ? 'You already have contacts. Add sample data alongside them? It can be removed again with odden:demo --remove.'
                : 'Load sample data so you can look around? It can be removed again with odden:demo --remove.',
            ! $hasData,
        );

        if ($load) {
            $this->call('odden:demo', ['--force' => true]);
        }
    }

    private function schedule(): void
    {
        $installed = array_filter(self::SCHEDULE, fn (string $_, string $command): bool => $this->commandExists($command), ARRAY_FILTER_USE_BOTH);

        if ($installed === [] || $this->option('no-schedule')) {
            return;
        }

        $path = base_path('routes/console.php');
        $contents = File::exists($path) ? File::get($path) : '';
        $missing = array_filter($installed, fn (string $_, string $command): bool => ! str_contains($contents, $command), ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            $this->components->twoColumnDetail('Scheduler', 'Odden commands are already in routes/console.php');

            return;
        }

        $lines = collect($missing)->map(fn (string $frequency, string $command): string => "\\Illuminate\\Support\\Facades\\Schedule::command('{$command}'){$frequency}->onOneServer();")->implode("\n");

        $this->components->warn('These commands need to run on a schedule:');
        $this->line($lines);

        if (File::exists($path) && ($this->option('schedule') || $this->confirm('Add them to routes/console.php?', false))) {
            File::append($path, "\n// Odden\n{$lines}\n");
            $this->components->info('Added to routes/console.php. Drop ->onOneServer() if you run a single server without a shared cache.');
        }

        $this->line('  Make sure the scheduler runs: <info>php artisan schedule:work</info> locally, or a cron entry for <info>php artisan schedule:run</info>.');
    }

    private function checkQueueAndMail(): void
    {
        $this->newLine();

        if (config('queue.default') === 'sync') {
            $this->components->warn('QUEUE_CONNECTION is "sync". Odden queues every email it sends, so use a real queue and run a worker (php artisan queue:work).');
        } else {
            $this->components->twoColumnDetail('Queue', (string) config('queue.default').' (run a worker: php artisan queue:work)');
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->components->warn('MAIL_MAILER is "'.config('mail.default').'", so no email leaves the app. Configure a real mailer before you use the email features.');
        }
    }

    /**
     * Other modules add their own checks (the Filament admin checks that its plugin is registered on a panel).
     */
    private function moduleSteps(): void
    {
        foreach (app(InstallSteps::class)->all() as $step) {
            $step->run($this);
        }
    }

    private function commandExists(string $command): bool
    {
        return array_key_exists($command, $this->getApplication()?->all() ?? []);
    }
}
