<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\Checks;
use App\DocsLab\LaneRun;
use App\DocsLab\Scenarios;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\Limits\LimitCatalog;
use Prism\AgentDocs\Outcome;
use Tests\TestCase;

/**
 * G-316, prism-agent-docs 0.1.4: a partly doable request is told what the
 * draft did, in the catalogue's `done` sentence, between "The draft is ready."
 * and the offer. The message check allows that sentence ONLY where a scenario
 * names the part: S4 must say it, and S3 and R2, which name none, must not.
 */
final class MessageCheckTest extends TestCase
{
    private const HEADER_DONE = 'The header now starts at the top edge of the page.';

    public function test_the_header_done_sentence_is_the_catalogue_s(): void
    {
        $this->assertSame(self::HEADER_DONE, LimitCatalog::load()->get('side-margins-shared')->expressible[0]->done);
    }

    public function test_s4_passes_with_the_done_sentence(): void
    {
        $this->assertTrue($this->messageCheck('S4', $this->ready(withDone: true)));
    }

    public function test_s4_fails_without_the_done_sentence(): void
    {
        $this->assertFalse($this->messageCheck('S4', $this->ready(withDone: false)));
    }

    public function test_s4_fails_with_the_done_sentence_in_the_wrong_place(): void
    {
        $this->assertFalse($this->messageCheck('S4', $this->ready(withDone: false).' '.self::HEADER_DONE));
    }

    public function test_r2_fails_with_the_done_sentence(): void
    {
        $this->assertFalse($this->messageCheck('R2', $this->ready(withDone: true, alternative: 1)));
        $this->assertTrue($this->messageCheck('R2', $this->ready(withDone: false, alternative: 1)));
    }

    public function test_s3_fails_with_the_done_sentence(): void
    {
        $this->assertFalse($this->messageCheck('S3', $this->ready(withDone: true)));
        $this->assertTrue($this->messageCheck('S3', $this->ready(withDone: false)));
        $this->assertTrue($this->messageCheck('S3', $this->offer(0)));
    }

    public function test_a_check_naming_a_part_no_listed_limit_has_cannot_pass(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Checks::class)->allowedMessages(true, ['band-no-footnote'], ['page.margins.header']);
    }

    private function offer(int $alternative): string
    {
        $limit = LimitCatalog::load()->get('side-margins-shared');

        return $limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[$alternative]);
    }

    private function ready(bool $withDone, int $alternative = 0): string
    {
        return DocsResult::READY.($withDone ? ' '.self::HEADER_DONE : '').' '.$this->offer($alternative);
    }

    /** Whether the scenario's `message` check passes for a result that said $message. */
    private function messageCheck(string $scenario, string $message): bool
    {
        $result = new DocsResult(
            Outcome::Ready,
            $message,
            'none',
            'docx',
            [['limit' => LimitCatalog::load()->get('side-margins-shared'), 'alternative' => 0]],
        );
        $checks = app(Checks::class)->evaluate(app(Scenarios::class)->find($scenario), new LaneRun($result, null, null), null, null);
        $message = array_values(array_filter($checks, fn (array $c): bool => $c['check'] === 'message'));
        $this->assertCount(1, $message);

        return $message[0]['passed'];
    }
}
