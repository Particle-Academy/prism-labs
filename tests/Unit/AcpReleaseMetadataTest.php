<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AcpReleaseMetadataTest extends TestCase
{
    public function test_dependency_lanes_match_the_single_reviewed_release(): void
    {
        $root = dirname(__DIR__, 2);
        $release = require $root.'/scripts/acp-release.php';
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $constraint = $manifest['require']['particle-academy/prism-acp'];
        $this->assertContains($constraint, [$release['version'], 'dev-main as '.$release['version']]);
        $package = array_values(array_filter($lock['packages'], fn (array $package): bool => $package['name'] === 'particle-academy/prism-acp'))[0];
        if ($constraint === $release['version']) {
            $this->assertSame($release['version'], ltrim($package['version'], 'v'));
            $this->assertSame($release['source_sha'], $package['source']['reference']);
            $this->assertSame($release['source_sha'], $package['dist']['reference']);
        } else {
            $this->assertSame('dev-main', $package['version']);
            $aliases = array_values(array_filter($lock['aliases'], fn (array $alias): bool => $alias['package'] === 'particle-academy/prism-acp'));
            $this->assertCount(1, $aliases);
            $this->assertSame($release['version'], $aliases[0]['alias']);
        }
    }

    public function test_metadata_keys_can_be_reordered_without_changing_identity(): void
    {
        $release = require dirname(__DIR__, 2).'/scripts/acp-release.php';
        $this->assertEquals($release, $this->readFixture(array_reverse($release, true)));
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_release_identity_fails_closed(array $changes): void
    {
        $release = require dirname(__DIR__, 2).'/scripts/acp-release.php';
        $this->expectException(RuntimeException::class);
        $this->readFixture(array_replace($release, $changes));
    }

    public static function invalidMetadata(): array
    {
        return [
            'prerelease' => [['version' => '0.1.0-alpha.5']],
            'tag mismatch' => [['tag' => 'v0.1.0']],
            'short commit' => [['source_sha' => 'a3edf05']],
            'wrong field type' => [['source_sha' => []]],
            'unexpected field' => [['download_url' => 'https://untrusted.invalid/archive.zip']],
        ];
    }

    private function readFixture(array $release): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-lab-release-metadata-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            copy(dirname(__DIR__, 2).'/scripts/acp-release.php', $directory.'/acp-release.php');
            file_put_contents($directory.'/acp-release.json', json_encode($release, JSON_THROW_ON_ERROR));

            return require $directory.'/acp-release.php';
        } finally {
            unlink($directory.'/acp-release.json');
            unlink($directory.'/acp-release.php');
            rmdir($directory);
        }
    }
}
