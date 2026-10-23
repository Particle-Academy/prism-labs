<?php

declare(strict_types=1);

namespace App\Lab;

use Composer\InstalledVersions as Composer;

/**
 * Which versions of the Prism packages this Lab is actually exercising.
 *
 * The docs site reported a documentation version here, which is the right
 * answer for a docs site and the wrong one for a testbed. A run against
 * v0.115.0-alpha.1 and a run against v0.114.0 can disagree, and a result you
 * cannot attribute to a version is a result you cannot act on.
 *
 * Read from Composer's own runtime metadata rather than a constant, because a
 * constant is a second place for the version to be wrong.
 */
final class InstalledVersions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $versions = [];

        $packages = Composer::getInstalledPackages();
        sort($packages);
        foreach ($packages as $package) {
            if (! preg_match('/^particle-academy\/prism(?:-|$)/', $package)) {
                continue;
            }

            // Short name: the vendor prefix is the same for every row and
            // spending horizontal space on it tells the reader nothing.
            $versions[str_replace('particle-academy/', '', $package)] = self::pretty($package);
        }

        return $versions;
    }

    /**
     * The headline version — Prism itself.
     */
    public static function prism(): string
    {
        return Composer::isInstalled('particle-academy/prism')
            ? self::pretty('particle-academy/prism')
            : 'unknown';
    }

    private static function pretty(string $package): string
    {
        // getPrettyVersion() returns null for a replaced or provided package.
        // Say so rather than rendering an empty string that reads as a bug.
        return Composer::getPrettyVersion($package) ?? 'unknown';
    }
}
