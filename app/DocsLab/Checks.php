<?php

declare(strict_types=1);

namespace App\DocsLab;

use InvalidArgumentException;
use LastWord\Agent;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\Limits\LimitCatalog;
use Prism\AgentDocs\Pictures\PictureRegistry;
use Prism\AgentDocs\Support\DocumentTree;
use Prism\AgentDocs\WorkingCopy;
use Throwable;

/**
 * The structural half of the verdict: questions with a right answer, checked
 * by code against the FILE the lane wrote and re-read, never by a model.
 *
 * Every check reads the draft as last-word reads it back from disk
 * (`Agent::read` of the artefact), so "the header holds the logo" means the
 * bytes of the logo are in the header part of the .docx a person would open.
 * Pictures are compared by sha256 per part. The message is compared EXACTLY
 * against the sentences the agent's catalogue allows, because the owner's rule
 * is about the words, and "close enough" is how narration creeps back in.
 *
 * A check that cannot run (no draft where one is needed) FAILS; it never
 * passes vacuously, except the few named `_if_written` that are explicitly
 * about a draft that may or may not exist.
 */
final class Checks
{
    private readonly LimitCatalog $catalog;

    /** The catalogue is the agent's own resources/limits.json, the same words the agent composes its message from. */
    public function __construct(private readonly Fixtures $fixtures, ?LimitCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? LimitCatalog::load();
    }

    /**
     * @param  ?array<string, mixed>  $source  the working copy, as last-word read it
     * @param  ?array<string, mixed>  $draft  the written draft, re-read from the artefact file
     * @return list<array{check: string, label: string, passed: bool, detail: string}>
     */
    public function evaluate(Scenario $scenario, LaneRun $run, ?array $source, ?array $draft): array
    {
        $out = [];
        foreach ($scenario->expect as $check) {
            try {
                [$passed, $detail] = $this->one($check, $run, $source, $draft);
            } catch (Throwable $e) {
                [$passed, $detail] = [false, 'The check could not run: '.class_basename($e)];
            }
            $out[] = ['check' => (string) $check['check'], 'label' => $this->label($check), 'passed' => $passed, 'detail' => $detail];
        }

        return $out;
    }

    /**
     * Every sentence the agent may say for these limits, exactly.
     *
     * `$done` names the doable parts (`expressible[*].path` in the catalogue)
     * the draft must have made. Their `done` sentences, in the catalogue's
     * words and the order given, go between "The draft is ready." and the
     * offer, as the agent composes it. Only in a ready message, and only the
     * parts named: a check that names none allows no done sentence at all, so
     * a draft that quietly makes a change the scenario did not expect fails.
     *
     * @param  list<string>  $limits
     * @param  list<string>  $done
     * @return list<string>
     */
    public function allowedMessages(bool|string $ready, array $limits, array $done = []): array
    {
        $said = '';
        foreach ($done as $path) {
            $said .= ' '.$this->doneSentence($path, $limits);
        }

        $notes = [''];
        foreach ($limits as $id) {
            $limit = $this->catalog->get($id);
            $next = [];
            foreach ($notes as $prefix) {
                foreach ($limit->alternatives as $alternative) {
                    $next[] = ltrim($prefix.' '.$limit->statement.DocsResult::OFFER.lcfirst($alternative));
                }
            }
            $notes = $next;
        }
        $out = [];
        foreach ($notes as $note) {
            if ($ready === true || $ready === 'either') {
                $out[] = trim(DocsResult::READY.$said.' '.$note);
            }
            // No draft, nothing done: a message without a draft never carries a done sentence.
            if (($ready === false || $ready === 'either') && $note !== '' && $done === []) {
                $out[] = $note;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The catalogue's sentence for a doable part of one of these limits.
     *
     * @param  list<string>  $limits
     */
    private function doneSentence(string $path, array $limits): string
    {
        foreach ($limits as $id) {
            foreach ($this->catalog->get($id)->expressible as $part) {
                if ($part->path === $path) {
                    return $part->done;
                }
            }
        }

        throw new InvalidArgumentException('No limit in ['.implode(', ', $limits)."] has a doable part at [{$path}].");
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array{bool, string}
     */
    private function one(array $check, LaneRun $run, ?array $source, ?array $draft): array
    {
        $result = $run->result;

        switch ($check['check']) {
            case 'outcome':
                $outcome = $result?->outcome->value ?? 'none';

                return [in_array($outcome, (array) $check['in'], true), 'outcome '.$outcome.($run->error !== null ? ' ('.$run->error.')' : '')];

            case 'never_problem':
                $seen = collect($run->calls)->filter(fn (array $c): bool => in_array($check['code'], $c['problems'], true))->count();

                return [$seen === 0, $seen === 0 ? 'never raised' : "raised {$seen} time(s)"];

            case 'draft_written':
                return [$draft !== null, $draft !== null ? 'a draft was written and re-read' : 'no draft was written'];

            case 'pictures':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }
                $want = array_map(fn (string $name): string => $this->fixtures->pictureHash($name), (array) $check['pictures']);
                $got = self::picturesByPart($draft)[$check['part']] ?? [];

                return [$got === $want, sprintf('%s holds %d picture(s); %s', $check['part'], count($got), $got === $want ? 'same bytes, same order' : 'bytes or order differ')];

            case 'pictures_only_in':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }
                $elsewhere = array_diff(array_keys(self::picturesByPart($draft)), (array) $check['parts']);

                return [$elsewhere === [], $elsewhere === [] ? 'no picture elsewhere' : 'pictures also in '.implode(', ', $elsewhere)];

            case 'body_empty':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }

                return [($draft['blocks'] ?? []) === [], 'body has '.count($draft['blocks'] ?? []).' block(s)'];

            case 'body_changed':
                if ($draft === null || $source === null) {
                    return [false, 'no draft to inspect'];
                }
                $same = json_encode($draft['blocks'] ?? []) === json_encode($source['blocks'] ?? []);

                return [! $same, $same ? 'the body is unchanged' : 'the body was rewritten'];

            case 'band_kept':
                if ($draft === null || $source === null) {
                    return [false, 'no draft to inspect'];
                }
                $band = (string) $check['band'];
                $before = self::normalised($source)['page'][$band] ?? null;
                $after = self::normalised($draft)['page'][$band] ?? null;

                return [$before === $after && $before !== null, $before === $after ? "{$band} identical to the working copy's" : "{$band} differs from the working copy's"];

            case 'margin':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }
                $value = $draft['page']['margins'][$check['name']] ?? null;

                return [$value !== null && (float) $value === (float) $check['equals'], sprintf('margins.%s is %s', $check['name'], $value === null ? 'unset (the default)' : json_encode($value))];

            case 'margin_kept':
                if ($draft === null) {
                    return [true, 'no draft: nothing could change'];
                }
                $moved = [];
                foreach ((array) $check['names'] as $name) {
                    if (($source['page']['margins'][$name] ?? null) != ($draft['page']['margins'][$name] ?? null)) {
                        $moved[] = $name;
                    }
                }

                return [$moved === [], $moved === [] ? 'unchanged' : 'changed: '.implode(', ', $moved)];

            case 'no_first_page_band':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }
                $first = array_values(array_filter(['firstHeader', 'firstFooter'], fn (string $b): bool => isset($draft['page'][$b])));

                return [$first === [], $first === [] ? 'none' : 'has '.implode(', ', $first)];

            case 'draft_changed_if_written':
                if ($draft === null) {
                    return [true, 'no draft was presented'];
                }
                $changed = $source === null || ! Agent::equivalent($draft, $source);

                return [$changed, $changed ? 'the draft differs from the working copy' : 'a draft that changes nothing'];

            case 'message':
                $allowed = $this->allowedMessages($check['ready'], (array) $check['limits'], array_values(array_map('strval', (array) ($check['done'] ?? []))));
                $message = $result?->message ?? '';

                return [in_array($message, $allowed, true), 'said: '.($message === '' ? '(nothing)' : $message)];

            case 'limit_reported':
                $ids = array_map(fn (array $reported): string => $reported['limit']->id, $result->limits ?? []);

                return [in_array($check['limit'], $ids, true), $ids === [] ? 'no limit reported' : 'reported '.implode(', ', $ids)];

            case 'shown':
                $missing = array_values(array_filter((array) $check['contains'], fn (string $needle): bool => ! str_contains($run->shown, $needle)));

                return [$run->shown !== '' && $missing === [], $missing === [] ? 'the model was shown the working copy' : 'not shown: '.implode('; ', $missing)];

            case 'store_draft_is_result':
                $ok = $result?->draft !== null && $run->storeDraft === $result->draft;

                return [$ok, $ok ? 'the store\'s draft slot holds the new draft' : 'the store\'s draft slot was not replaced'];

            case 'base_version_is_saved':
                $want = WorkingCopy::versionOf($run->saved);

                return [$result?->baseVersion === $want, 'baseVersion '.($result?->baseVersion ?? 'none')];

            case 'max_model_calls':
                return [$run->modelCalls <= (int) $check['max'], "{$run->modelCalls} model call(s)"];

            case 'text_present':
                if ($draft === null) {
                    return [false, 'no draft to inspect'];
                }
                $text = json_encode($draft['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return [str_contains((string) $text, (string) $check['text']), str_contains((string) $text, (string) $check['text']) ? 'present' : 'absent'];
        }

        return [false, 'unknown check'];
    }

    /** @param array<string, mixed> $check */
    private function label(array $check): string
    {
        $args = $check;
        unset($args['check']);

        return $check['check'].($args === [] ? '' : ' '.json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, list<string>> part => sha256 of each picture, in document order */
    public static function picturesByPart(array $doc): array
    {
        $out = [];
        foreach (DocumentTree::images($doc) as $image) {
            $out[$image['part']][] = hash('sha256', (string) PictureRegistry::decode($image['src']));
        }

        return $out;
    }

    /** The document with every picture as its sha256, so bands compare by bytes. */
    public static function normalised(array $doc): array
    {
        return DocumentTree::mapImages($doc, static fn (string $src): string => 'sha256:'.hash('sha256', (string) PictureRegistry::decode($src)));
    }
}
