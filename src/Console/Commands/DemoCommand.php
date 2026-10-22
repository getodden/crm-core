<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;
use Odden\Core\Demo\DemoManager;

class DemoCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:demo
        {--remove : Delete the sample data instead of loading it}
        {--force : Run in production without asking}';

    /**
     * @var string
     */
    protected $description = 'Load sample companies, contacts, deals and more so you can see how Odden looks, or remove them again';

    public function handle(DemoManager $demo): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force') && ! $this->confirm('This is a production environment. Continue?')) {
            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $removed = $demo->remove();
            $this->components->info($removed === 0 ? 'There was no sample data to remove.' : "Removed {$removed} sample records. Your own records were not touched.");

            return self::SUCCESS;
        }

        $wasLoaded = $demo->isLoaded();

        $loaded = $demo->load(fn (string $module) => $this->components->task("Loading {$module}"));

        $this->components->info(($wasLoaded ? 'Replaced the sample data' : 'Loaded the sample data').' for: '.implode(', ', $loaded).'.');
        $this->line('  Every sample contact is on the reserved ".example" domain, so nothing sent to one can reach a real person.');
        $this->line('  Remove it all with <info>php artisan odden:demo --remove</info>.');

        return self::SUCCESS;
    }
}
