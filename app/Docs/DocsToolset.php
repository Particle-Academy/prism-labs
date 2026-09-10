<?php

declare(strict_types=1);

namespace App\Docs;

use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;

/**
 * The Overseer's reading. Three tools over {@see DocsLibrary}.
 *
 * ## Why the Overseer needs documentation at all
 *
 * It is asked to design experiments against the Prism ecosystem, and it was
 * doing that from whatever it happened to know. An agent reasoning about
 * `EvictionSink` from a half-remembered prior is exactly the failure the Lab
 * exists to catch in everyone else's agents — it produces a benchmark that
 * tests the shape the model imagined rather than the shape that shipped.
 *
 * So: the published docs, and the Lab's own.
 *
 * ## Three tools, not one
 *
 * `docs_index` is deliberately separate from `docs_read`, because the shape of
 * the corpus is a cheap question and reading eleven pages to answer it is not.
 * The index carries titles and word counts so the agent can choose without
 * opening anything.
 *
 * `docs_search` exists because the common case is "where is X described",
 * which the index cannot answer and which otherwise becomes a sweep.
 *
 * ## Offered to every session, unlike the workspace tools
 *
 * The lane toolsets return `[]` unless the session belongs to a benchmark lane,
 * because writing files is only meaningful there. Reading documentation is
 * meaningful everywhere, and a narrowed sub-agent that cannot look something up
 * will guess instead — which is the behaviour this Lab keeps finding and
 * reporting in other people's systems.
 */
final readonly class DocsToolset
{
    public function __construct(private DocsLibrary $docs) {}

    /**
     * @return list<Tool>
     */
    public function forSession(Session $session): array
    {
        $shelves = implode(', ', array_keys($this->docs->shelves()));

        return [
            (new Tool)
                ->as('docs_index')
                ->for(
                    "List the documentation available to you. Shelves: {$shelves}. "
                    .'`prism` is the published ecosystem documentation — packages, guarantees, and how a '
                    .'third-party developer uses them. `plab` is this Lab\'s own documentation, unpublished, '
                    .'covering how the Lab is built, how a person drives it and how an agent drives it. '
                    .'Returns titles and sizes so you can choose a page without opening several.'
                )
                ->withStringParameter('shelf', "Limit to one shelf ({$shelves}), or empty for all.", required: false)
                ->using(fn (string $shelf = ''): string => json_encode(
                    $this->docs->index($shelf === '' ? null : $shelf),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
                )),

            (new Tool)
                ->as('docs_read')
                ->for('Read one documentation page in full. Use `docs_index` or `docs_search` first to get an exact shelf and path.')
                ->withStringParameter('shelf', "Which shelf: {$shelves}.")
                ->withStringParameter('path', 'The page path as reported by docs_index, e.g. packages/harness.md.')
                ->using(function (string $shelf, string $path): string {
                    $body = $this->docs->read($shelf, $path);

                    // Named as a miss rather than returned empty. An agent told
                    // "no such page" asks a different question; one handed an
                    // empty string concludes the topic is undocumented.
                    return $body ?? "No page [{$path}] on shelf [{$shelf}]. Call docs_index to see what exists.";
                }),

            (new Tool)
                ->as('docs_search')
                ->for('Find which documentation pages mention a term, with the lines that mention it. Use it before answering anything about how a Prism package behaves.')
                ->withStringParameter('term', 'The word or phrase to look for, e.g. EvictionSink or summary_words.')
                ->using(function (string $term): string {
                    $hits = $this->docs->search($term);

                    return $hits === []
                        ? "Nothing in the documentation mentions [{$term}]. That is not proof the behaviour does not exist — it may be undocumented, which is worth saying plainly."
                        : json_encode($hits, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                }),
        ];
    }
}
