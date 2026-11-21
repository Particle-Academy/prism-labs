<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The scripted lane catches a regression in the agent, not just in the lab.
 *
 * A green lane is a claim; this makes it a check. The installed agent's own
 * PictureRegistry is copied and broken the way Moic's host was (only the
 * BODY's pictures are registered, so a header picture is unknown to the
 * writer), then loaded in place of the real class in a CHILD PHP process
 * (tests/fixtures/docs-lab/mutant.php), so the mutant can never leak into this
 * process. S1 must then FAIL on the lab's structural checks, and R2, whose
 * pictures are all in the body, must still pass, so the failure is the
 * mutation's and not the lab's.
 *
 * The sibling working tree is never edited. The child boots on an empty env
 * file with in-memory SQLite, and asserts both before it migrates.
 */
final class ScriptedLaneMutationTest extends TestCase
{
    private const SEARCH = '$bytes = self::decode($src);';

    private const BODY_ONLY = '$bytes = $part === \Prism\AgentDocs\Support\DocumentTree::BODY ? self::decode($src) : null;';

    public function test_registering_only_body_pictures_fails_s1_and_spares_r2(): void
    {
        $directory = storage_path('framework/testing/docs-lab-mutant-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0777, true);
        $this->beforeApplicationDestroyed(static fn () => app(Filesystem::class)->deleteDirectory($directory));
        file_put_contents($directory.'/fixture.env', '');
        $source = (string) file_get_contents(InstalledVersions::getInstallPath('particle-academy/prism-agent-docs').'/src/Pictures/PictureRegistry.php');
        // Exactly once, so a refactor that moves the line fails loudly here
        // instead of quietly testing an unmutated agent.
        $this->assertSame(1, substr_count($source, self::SEARCH));
        file_put_contents($directory.'/PictureRegistry.php', str_replace(self::SEARCH, self::BODY_ONLY, $source));

        $s1 = $this->child($directory, 'S1');
        $this->assertTrue($s1['mutant_loaded'], 'The child ran the real PictureRegistry, not the mutant.');
        $this->assertSame('failed', $s1['status']);
        $this->assertFalse($s1['results'][0]['passed']);
        $this->assertContains('never_problem', $s1['results'][0]['failed'], 'image_unknown must be seen');
        $this->assertContains('draft_written', $s1['results'][0]['failed']);
        $this->assertContains('pictures', $s1['results'][0]['failed']);

        $r2 = $this->child($directory, 'R2');
        $this->assertTrue($r2['mutant_loaded']);
        $this->assertSame('passed', $r2['status'], json_encode($r2));
    }

    private function child(string $directory, string $scenario): array
    {
        $process = new Process([PHP_BINARY, 'tests/fixtures/docs-lab/mutant.php', $directory, $directory.'/PictureRegistry.php', $scenario], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'LAB_DB_DATABASE' => ':memory:',
            'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'GENIE_INBOX_URL' => '', 'GENIE_INBOX_TOKEN' => '',
        ], null, 120);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, $process->getOutput());

        return $decoded;
    }
}
