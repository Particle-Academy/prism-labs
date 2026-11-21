<?php

declare(strict_types=1);

namespace App\DocsLab;

use Composer\InstalledVersions;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Which prism-agent-docs the lab is exercising, precisely enough to certify.
 *
 * The Lab installs the agent from the sibling working tree (a composer path
 * repository), so the version label alone ("dev-main as 0.1.2") and even the
 * commit reference can name code that is not what ran: the tree can carry
 * uncommitted edits. So a run also records `source_digest`, the sha256 over
 * every file the package ships (src/, resources/, composer.json), in path
 * order, as installed. A certificate cites that digest, and a later run on
 * different bytes cannot borrow it.
 */
final class AgentVersion
{
    public const PACKAGE = 'particle-academy/prism-agent-docs';

    /** @return array{package: string, version: string, pretty: string, reference: ?string, source_digest: string} */
    public static function current(): array
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE)) {
            throw new RuntimeException('particle-academy/prism-agent-docs is not installed.');
        }
        $pretty = (string) InstalledVersions::getPrettyVersion(self::PACKAGE);

        return [
            'package' => self::PACKAGE,
            'version' => self::label() ?? $pretty,
            'pretty' => $pretty,
            'reference' => InstalledVersions::getReference(self::PACKAGE),
            'source_digest' => self::sourceDigest((string) InstalledVersions::getInstallPath(self::PACKAGE)),
        ];
    }

    /** The release label the Lab's composer.json gives the path install ("dev-main as X.Y.Z"). */
    public static function label(): ?string
    {
        $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $constraint = $manifest['require'][self::PACKAGE] ?? null;

        return is_string($constraint) && preg_match('/^dev-main as (\d+\.\d+\.\d+)$/D', $constraint, $m) === 1 ? $m[1]
            : (is_string($constraint) && preg_match('/^\d+\.\d+\.\d+$/D', $constraint) === 1 ? $constraint : null);
    }

    public static function sourceDigest(string $path): string
    {
        $root = realpath($path);
        if ($root === false) {
            throw new RuntimeException('The installed prism-agent-docs could not be found.');
        }
        $files = [];
        foreach (['src', 'resources'] as $directory) {
            foreach ((new Finder)->files()->in($root.'/'.$directory) as $file) {
                $files[$directory.'/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getRealPath();
            }
        }
        $files['composer.json'] = $root.'/composer.json';
        ksort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $relative => $absolute) {
            hash_update($context, $relative."\0".hash('sha256', Fixtures::lf((string) file_get_contents($absolute)))."\n");
        }

        return hash_final($context);
    }
}
