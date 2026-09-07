<?php

declare(strict_types=1);

namespace App\Context;

use Illuminate\Support\Facades\DB;
use Prism\Harness\Contracts\ContextRecall;

/**
 * Finds evicted turns by keyword, within a budget.
 *
 * Keyword rather than semantic, on purpose — see {@see TableEvictionSink}. The
 * question this exists to answer is whether the mechanism works, and the
 * cheapest search that could possibly work is the one that answers it without
 * confounding the result.
 *
 * ## The budget is enforced here, not hoped for
 *
 * The contract says the ceiling is "not a suggestion", and this is the class
 * that has to mean it. Recall exists because the window is finite; handing back
 * everything that matched would re-expand exactly what compaction just removed,
 * and the caller would find out from the provider rather than from us.
 *
 * Rows are added whole and the budget is checked BEFORE each one, so the result
 * never exceeds the ceiling — as opposed to trimming the final string, which
 * would cut a row in half and hand the model a truncated fact that reads as a
 * complete one.
 */
final class TableContextRecall implements ContextRecall
{
    /** Four characters to a token — the same rough estimate prism-memory uses. */
    private const CHARS_PER_TOKEN = 4;

    #[\Override]
    public function recall(string $query, string $scope, int $budget): string
    {
        $terms = $this->termsIn($query);

        if ($terms === []) {
            return '';
        }

        $rows = DB::table('evicted_messages')
            ->where('scope', $scope)
            ->where(function ($search) use ($terms): void {
                foreach ($terms as $term) {
                    $search->orWhere('content', 'like', '%'.$term.'%');
                }
            })
            ->orderBy('id')
            ->limit(50)
            ->get(['role', 'content']);

        $ceiling = $budget * self::CHARS_PER_TOKEN;
        $used = 0;
        $lines = [];

        foreach ($rows as $row) {
            $line = '['.$row->role.'] '.$row->content;

            // Checked before adding, so the ceiling holds. Trimming afterwards
            // would leave a half-row that reads to the model as a whole fact.
            if ($used + strlen($line) > $ceiling) {
                break;
            }

            $used += strlen($line);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The words worth searching for.
     *
     * Short words are dropped because a `LIKE %of%` matches most of the corpus
     * and would fill the budget with rows that matched nothing anyone asked
     * about — which looks like a working recall returning junk, the hardest
     * failure to notice.
     *
     * @return list<string>
     */
    private function termsIn(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_-]{4,}/u', $query, $matches);

        return array_values(array_unique(array_slice($matches[0], 0, 12)));
    }
}
