<?php

declare(strict_types=1);

namespace App\Docs;

use Illuminate\Support\Collection;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Every page the Overseer is allowed to read, from two shelves.
 *
 * ## The two shelves, and why they are not one
 *
 * **`prism`** is the published documentation — the same markdown that renders
 * at prism.gen, read from `prism-sandbox`. It describes the ECOSYSTEM: what
 * each package is for, what its guarantees are, and what a third-party
 * developer should do with it.
 *
 * **`plab`** is this repository's own documentation, and it is deliberately NOT
 * published anywhere. It describes THE LAB: how it is wired, how a person
 * drives it, and how an agent drives it. That distinction matters because the
 * Lab is a testbed for the ecosystem, not a product of it — publishing its
 * internals on the ecosystem's docs site would tell a reader that the Lab's
 * shape is something to copy, and it is not.
 *
 * ## Read-only, and bounded to those two roots
 *
 * Paths are resolved and then checked against the shelf root, so `..` cannot
 * walk out of the docs tree into the application. An agent asking for
 * `../../.env` gets a refusal rather than a file. That check is not decoration:
 * the whole point of this class is that a MODEL chooses the path.
 *
 * ## A missing shelf is not an error
 *
 * The `prism` shelf lives in a sibling repository, and a checkout that does not
 * have it is normal — someone working on the Lab alone, or CI. A missing shelf
 * reports itself as empty and the tools say so, rather than throwing and taking
 * the whole conversation down over documentation.
 */
final readonly class DocsLibrary
{
    public function __construct(
        /** @var array<string, string> shelf name => absolute directory */
        private array $shelves,
    ) {}

    /**
     * @return array<string, string>
     */
    public function shelves(): array
    {
        return $this->shelves;
    }

    /**
     * Every page, as `shelf/relative/path.md` with its first heading.
     *
     * The heading is included because a path alone makes an agent open files to
     * find out what they are, and each open is a turn.
     *
     * @return list<array{shelf: string, path: string, title: string, words: int}>
     */
    public function index(?string $shelf = null): array
    {
        $pages = [];

        foreach ($this->shelves as $name => $root) {
            if ($shelf !== null && $shelf !== $name) {
                continue;
            }

            foreach ($this->files($root) as $file) {
                $body = (string) file_get_contents($file->getPathname());

                $pages[] = [
                    'shelf' => $name,
                    'path' => $this->relative($root, $file->getPathname()),
                    'title' => $this->titleOf($body),
                    'words' => str_word_count(strip_tags($body)),
                ];
            }
        }

        usort($pages, fn (array $a, array $b): int => [$a['shelf'], $a['path']] <=> [$b['shelf'], $b['path']]);

        return $pages;
    }

    /**
     * One page, or null when it is not a real page on that shelf.
     */
    public function read(string $shelf, string $path): ?string
    {
        $root = $this->shelves[$shelf] ?? null;

        if ($root === null || ! is_dir($root)) {
            return null;
        }

        // Resolved BEFORE the prefix check, so `docs/../../.env` is compared as
        // the file it actually names rather than as the string it was written
        // as. Comparing the raw string would pass anything with a `..` in it.
        $full = realpath($root.DIRECTORY_SEPARATOR.ltrim($path, '/\\'));
        $base = realpath($root);

        if ($full === false || $base === false || ! str_starts_with($full, $base)) {
            return null;
        }

        if (! is_file($full) || ! str_ends_with(strtolower($full), '.md')) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * Pages mentioning a term, with the lines that mention it.
     *
     * Substring rather than semantic, deliberately: the corpus is a few dozen
     * pages, the agent already knows the vocabulary of this ecosystem, and a
     * grep it can reason about beats a similarity score it cannot. If this
     * grows to the point where that stops being true, the fix is an index, not
     * a bigger `limit`.
     *
     * @return list<array{shelf: string, path: string, title: string, matches: list<string>}>
     */
    public function search(string $term, int $limit = 12): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $hits = [];

        foreach ($this->shelves as $name => $root) {
            foreach ($this->files($root) as $file) {
                $body = (string) file_get_contents($file->getPathname());

                if (stripos($body, $term) === false) {
                    continue;
                }

                $matches = Collection::make(preg_split('/\R/u', $body) ?: [])
                    ->filter(fn (string $line): bool => stripos($line, $term) !== false)
                    ->map(fn (string $line): string => trim($line))
                    ->reject(fn (string $line): bool => $line === '')
                    ->take(5)
                    ->values()
                    ->all();

                $hits[] = [
                    'shelf' => $name,
                    'path' => $this->relative($root, $file->getPathname()),
                    'title' => $this->titleOf($body),
                    'matches' => $matches,
                ];

                if (count($hits) >= $limit) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function files(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        return array_values(iterator_to_array(
            Finder::create()->files()->in($root)->name('*.md')->sortByName(),
            false,
        ));
    }

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));
    }

    private function titleOf(string $body): string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $found) === 1) {
            return trim($found[1]);
        }

        return '(untitled)';
    }
}
