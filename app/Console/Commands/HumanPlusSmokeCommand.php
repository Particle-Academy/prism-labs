<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\HumanPlusManager;
use Throwable;

final class HumanPlusSmokeCommand extends Command
{
    protected $signature = 'lab:human-plus:smoke';

    protected $description = 'Join the active Prism Lab Fancy surface and call its structured form bridge';

    public function handle(HumanPlusManager $humanPlus): int
    {
        $token = config('capabilities.human_plus.fixture.token');
        if (! is_string($token) || strlen($token) < 16) {
            $this->error('PRISM_HUMAN_PLUS_FIXTURE_TOKEN is not configured.');

            return self::FAILURE;
        }
        $owner = 'prism-lab:human-plus-smoke';
        $attachment = null;
        try {
            $attachment = $humanPlus->attach($owner, new SurfaceInvitation(
                (string) config('capabilities.human_plus.fixture.relay_url'),
                (string) config('capabilities.human_plus.fixture.session_id'),
                $token, 'lab-form', 'Prism Lab Human+ fixture', allowInsecureLoopback: true,
            ), new Participant('prism-lab-smoke', 'Prism Lab smoke', '#06b6d4'));
            $tools = $humanPlus->tools($owner, $attachment->id);
            $description = $humanPlus->call($owner, $attachment->id, 'form_describe');
            $descriptionReceived = str_contains($description, '<untrusted-tool-output source="human-plus:lab-form"')
                && str_contains($description, 'form_describe');
            $this->line(json_encode([
                'ok' => $descriptionReceived, 'mode' => 'human_plus', 'surface' => 'lab-form',
                'tools' => array_map(fn ($tool): string => $tool->name, $tools),
                'form_describe_received' => $descriptionReceived,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $descriptionReceived ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $failure) {
            report($failure);
            $this->error('Human+ smoke run failed: '.$failure::class);

            return self::FAILURE;
        } finally {
            if ($attachment !== null) {
                try {
                    $humanPlus->detach($owner, $attachment->id);
                } catch (Throwable) {
                }
            }
        }
    }
}
