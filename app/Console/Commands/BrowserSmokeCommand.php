<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Browser\BrowserManager;
use Throwable;

final class BrowserSmokeCommand extends Command
{
    protected $signature = 'lab:browser:smoke {url=https://example.com}';

    protected $description = 'Exercise Prism Browser through its real local headless Chromium service';

    public function handle(BrowserManager $browser): int
    {
        $owner = 'prism-lab:browser-smoke';
        $attachment = $browser->open($owner, 'smoke');
        try {
            $observation = $browser->navigate($owner, $attachment->id, (string) $this->argument('url'));
            $this->line(json_encode([
                'ok' => true, 'mode' => 'browser', 'attachment' => $attachment->id,
                'url' => $observation->url, 'title' => $observation->title,
                'elements' => count($observation->elements), 'text_blocks' => count($observation->visibleText),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $failure) {
            report($failure);
            $this->error('Browser smoke run failed: '.$failure::class);

            return self::FAILURE;
        } finally {
            try {
                $browser->close($owner, $attachment->id);
            } catch (Throwable) {
                // The primary failure is already reported. Close is best effort
                // because an engine crash may make the attachment unreachable.
            }
        }

        return self::SUCCESS;
    }
}
