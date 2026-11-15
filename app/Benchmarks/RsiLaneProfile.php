<?php

declare(strict_types=1);

namespace App\Benchmarks;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Exact loaded runtime identity; no network discovery, source checkout or mutation. */
final class RsiLaneProfile
{
    public const MODE = 'rsi-test';

    /** Hash the package's executable/configuration/migration/resource bytes. */
    public function installedReference(string $package = 'particle-academy/prism-harness'): array
    {
        if (! in_array($package, ['particle-academy/prism-harness', 'particle-academy/prism'], true)) {
            throw new RuntimeException('Unsupported native runtime.');
        }
        $root = InstalledVersions::getInstallPath($package);
        if (! is_string($root) || ($root = realpath($root)) === false) {
            throw new RuntimeException('Harness runtime is unavailable.');
        }
        $files = ['composer.json' => hash_file('sha256', $root.'/composer.json')];
        foreach (['src', 'config', 'database', 'resources'] as $directory) {
            $base = $root.'/'.$directory;
            if (! file_exists($base)) {
                continue;
            }
            if (is_link($base)) {
                throw new RuntimeException('Harness runtime identity is unresolved.');
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Harness runtime identity is unresolved.');
                }
                if ($file->isFile()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $files[$relative] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files, SORT_STRING);
        $version = InstalledVersions::getPrettyVersion($package);
        $source = InstalledVersions::getReference($package);
        $release = is_string($version) && preg_match('/\Av?\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?\z/', $version);
        if (! $release && (! is_string($source) || preg_match('/\A[a-f0-9]{40}\z/', $source) !== 1)) {
            throw new RuntimeException('Harness immutable reference is unavailable.');
        }

        return ['kind' => $release ? 'release' : 'source', 'value' => $release ? ltrim($version, 'v') : $source,
            'sha256' => hash('sha256', CanonicalJson::encode($files))];
    }

    /** Explicit unsupported states, before creating any paid call. */
    public function failures(array $definition, array $specification = []): array
    {
        $failures = [];
        if ($definition['language'] !== 'php' || $definition['package'] !== 'particle-academy/prism-harness' || $definition['harness'] !== 'prism-harness') {
            $failures[] = 'This lane has no native shared-cost executor for its package and language.';
        }
        if ($definition['mode'] !== self::MODE || $definition['agent'] !== null) {
            $failures[] = 'This lane needs a supported native mode and agent identity.';
        }
        // This first native profile deliberately offers no nested paid tools.
        // Other profiles remain declared and explicitly unsupported, never rewritten.
        $mode = config('prism-harness.agent.modes.'.self::MODE);
        if (! is_array($mode) || ($mode['tools'] ?? null) !== [] || ($mode['skills'] ?? null) !== []
            || ($mode['subagents'] ?? []) !== [] || ($mode['provider_options'] ?? []) !== []) {
            $failures[] = 'The native execution profile changed or exposes unsupported capabilities.';
        }
        $limits = $definition['bounds']['limits'];
        if (! is_int($limits['max_output_tokens'] ?? null) || $limits['max_output_tokens'] < 1
            || array_diff(array_keys($limits), ['max_output_tokens', 'max_tool_calls']) !== [] || ($limits['max_tool_calls'] ?? 0) !== 0) {
            $failures[] = 'A declared lane bound cannot be enforced by this native profile.';
        }
        if (($specification['fixtures'] ?? []) !== []) {
            $failures[] = 'Fixture bytes are not attached to this native execution profile.';
        }
        try {
            $actual = $this->installedReference();
            foreach (['package_ref', 'harness_ref'] as $key) {
                if (CanonicalJson::encode($actual) !== CanonicalJson::encode($definition[$key])) {
                    $failures[] = 'The installed runtime differs from the frozen lane reference.';
                    break;
                }
            }
        } catch (RuntimeException) {
            $failures[] = 'The native runtime identity is unknown.';
        }

        return $failures;
    }
}
