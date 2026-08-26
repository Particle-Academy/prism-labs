<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lab\ProviderRegistry;
use Illuminate\Console\Command;

class LabProviders extends Command
{
    protected $signature = 'lab:providers {--configured : Only list providers that are ready to use}';

    protected $description = 'Show which Prism providers the Lab can reach, and how to switch on the ones it cannot';

    public function handle(ProviderRegistry $registry): int
    {
        $providers = $this->option('configured')
            ? $registry->availableForText()
            : $registry->all();

        if ($providers === []) {
            $this->warn('No providers configured. Run without --configured to see how to set one up.');

            return self::SUCCESS;
        }

        $this->table(
            ['Provider', 'Key', 'Modality', 'Status', 'How to enable'],
            array_map(fn (array $p): array => [
                $p['label'],
                $p['key'],
                $p['modality'],
                $p['configured'] ? '<info>ready</info>' : '<comment>not configured</comment>',
                $p['configured'] ? '' : $registry->setupHint($p['key']),
            ], $providers),
        );

        $ready = count($registry->availableForText());
        $this->line(sprintf('%d of %d text providers ready.', $ready, count($registry->textProviderKeys())));

        // A provider Prism gained that the Lab has no descriptor for still
        // works, but shows a guessed label and no setup hint — worth saying.
        if (($undescribed = $registry->undescribed()) !== []) {
            $this->newLine();
            $this->warn('No Lab descriptor for: '.implode(', ', $undescribed));
            $this->line('Add them to App\Lab\ProviderRegistry::DESCRIPTORS for a proper label and setup hint.');
        }

        return self::SUCCESS;
    }
}
