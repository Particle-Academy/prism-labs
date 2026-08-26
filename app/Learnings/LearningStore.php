<?php

declare(strict_types=1);

namespace App\Learnings;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Files a 0L report to both stores.
 *
 * Two stores because they answer different questions: the markdown file is the
 * record — committed, greppable, readable by every agent and human in the
 * workspace — and the database row is the feed the board renders.
 *
 * The file is authoritative. If they ever disagree, the file wins, because it
 * is the one under version control. So it is written FIRST, and the row is
 * only created once the file is on disk. A row pointing at a file that does
 * not exist is worse than no row.
 */
final class LearningStore
{
    public function __construct(private readonly string $directory) {}

    /**
     * @param  list<string>  $languages
     */
    public function file(
        string $title,
        string $filedBy,
        array $languages,
        string $whatWasLearned,
        string $evidence,
        string $whyItMatters,
        ?string $whatShouldChange = null,
        Severity $severity = Severity::Info,
    ): Learning {
        if (trim($whyItMatters) === '') {
            // Enforced here rather than left to the caller's discipline. This
            // is the section that makes a 0L worth reading later, and it is
            // the first one an agent in a hurry will leave blank.
            throw new RuntimeException('A 0L must say why it matters to the ecosystem.');
        }

        $ref = $this->nextRef();
        $path = $this->write($ref, $title, $filedBy, $languages, $severity, $whatWasLearned, $evidence, $whyItMatters, $whatShouldChange);

        return Learning::create([
            'ref' => $ref,
            'title' => $title,
            'filed_by' => $filedBy,
            'severity' => $severity,
            'languages' => $languages,
            'what_was_learned' => $whatWasLearned,
            'evidence' => $evidence,
            'why_it_matters' => $whyItMatters,
            'what_should_change' => $whatShouldChange,
            'path' => $path,
        ]);
    }

    /**
     * Next id, derived from the FILES rather than the table.
     *
     * The files are the record, and they outlive any one database — a Lab
     * rebuilt from scratch must not start reissuing 0L-0001 over learnings
     * that already exist on disk.
     */
    public function nextRef(): string
    {
        $this->ensureDirectory();

        $highest = 0;

        foreach (glob($this->directory.'/0L-*.md') ?: [] as $file) {
            if (preg_match('/0L-(\d+)/', basename($file), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('0L-%04d', $highest + 1);
    }

    /**
     * @param  list<string>  $languages
     */
    private function write(
        string $ref,
        string $title,
        string $filedBy,
        array $languages,
        Severity $severity,
        string $whatWasLearned,
        string $evidence,
        string $whyItMatters,
        ?string $whatShouldChange,
    ): string {
        $this->ensureDirectory();

        $path = $this->directory.'/'.$ref.'-'.Str::slug($title).'.md';

        $front = [
            'id: '.$ref,
            'title: '.$this->scalar($title),
            'filed_by: '.$filedBy,
            'filed_at: '.now()->toIso8601String(),
            'languages: ['.implode(', ', $languages).']',
            'severity: '.$severity->value,
        ];

        $body = "---\n".implode("\n", $front)."\n---\n\n"
            ."# {$ref} — {$title}\n\n"
            ."## What was learned\n\n".trim($whatWasLearned)."\n\n"
            ."## Evidence\n\n".trim($evidence)."\n\n"
            ."## Why it matters to the ecosystem\n\n".trim($whyItMatters)."\n";

        if ($whatShouldChange !== null && trim($whatShouldChange) !== '') {
            $body .= "\n## What should change\n\n".trim($whatShouldChange)."\n";
        }

        file_put_contents($path, $body);

        return $path;
    }

    /**
     * Quote a title that YAML would otherwise read as structure.
     *
     * A title is written by a model and will eventually contain a colon.
     */
    private function scalar(string $value): string
    {
        return str_contains($value, ':') || str_contains($value, '#')
            ? '"'.str_replace('"', '\"', $value).'"'
            : $value;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, recursive: true);
        }
    }
}
