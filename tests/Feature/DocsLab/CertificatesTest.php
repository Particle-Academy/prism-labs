<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\AgentVersion;
use App\DocsLab\Certificates;
use App\DocsLab\DocsLabRunner;
use App\Models\DocsLabCertificate;
use App\Models\DocsLabRun;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DocsLabLive;
use Tests\TestCase;

/** G-316: a hash-chained certificate only for a fully passed, judged live run of the installed agent. */
final class CertificatesTest extends TestCase
{
    use DocsLabLive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLabLive();
    }

    public function test_a_passed_judged_live_run_is_certified_and_chained(): void
    {
        $first = $this->passedLiveRun();
        $this->artisan('lab:docs-certify', ['--run' => $first->id])->assertSuccessful();
        $second = $this->passedLiveRun();
        $this->artisan('lab:docs-certify')->assertSuccessful();

        [$a, $b] = DocsLabCertificate::query()->orderBy('sequence')->get()->all();
        $this->assertSame(Certificates::FORMAT, $a->body['format']);
        $this->assertSame([1, 2], [$a->sequence, $b->sequence]);
        $this->assertNull($a->previous_hash);
        $this->assertSame($a->hash, $b->previous_hash);
        $this->assertSame($second->id, $b->body['evidence']['run']);
        $this->assertSame(AgentVersion::current()['source_digest'], $a->body['subject']['source_digest']);
        $this->assertCount(6, $a->body['evidence']['scenarios']);
        $this->assertSame(Certificates::hash($a->body), $a->hash);
        $file = (string) config('docs-lab.artefacts').'/certificates/0001-prism-agent-docs-'.$a->version.'.json';
        $this->assertFileExists($file);
        $this->assertSame($a->hash, json_decode((string) file_get_contents($file), true)['hash']);
        $this->artisan('lab:docs-certify', ['--verify' => true])->expectsOutputToContain('Certificate chain intact.')->assertSuccessful();
    }

    public function test_verify_finds_an_edited_certificate(): void
    {
        $this->artisan('lab:docs-certify', ['--run' => $this->passedLiveRun()->id])->assertSuccessful();
        $certificate = DocsLabCertificate::query()->sole();
        $body = $certificate->body;
        $body['subject']['version'] = '9.9.9';
        DB::table('docs_lab_certificates')->where('id', $certificate->id)->update(['body' => json_encode($body)]);

        $this->artisan('lab:docs-certify', ['--verify' => true])->expectsOutputToContain('do not match its hash')->assertFailed();
    }

    public function test_only_a_complete_judged_live_pass_of_the_installed_agent_is_certified(): void
    {
        $certificates = app(Certificates::class);

        $scripted = app(DocsLabRunner::class)->scripted('all');
        $this->assertStringContainsString('Only a live-lane run', (string) $certificates->refusal($scripted));

        $partial = $this->passedLiveRun('S1');
        $this->assertSame('The run does not cover every lab scenario.', $certificates->refusal($partial));

        $unjudged = $this->passedLiveRun('all', judge: false);
        $this->assertStringContainsString('both the structural checks and the judge', (string) $certificates->refusal($unjudged));

        $moved = $this->passedLiveRun();
        $moved->forceFill(['agent' => [...$moved->agent, 'source_digest' => str_repeat('0', 64)]])->save();
        $this->assertSame('The installed prism-agent-docs is not the code this run exercised.', $certificates->refusal($moved->refresh()));

        $this->artisan('lab:docs-certify', ['--run' => $scripted->id])->expectsOutputToContain('Refused')->assertFailed();
        $this->assertSame(0, DocsLabCertificate::query()->count());
    }

    private function passedLiveRun(string $selection = 'all', bool $judge = true): DocsLabRun
    {
        $intent = $this->approveLive($this->liveInput($selection, $judge), '1');
        $this->scriptLive($selection);
        $this->artisan('lab:docs-run', array_filter(['--lane' => 'live', '--scenario' => $selection, '--judge' => $judge, '--approved-run' => $intent->id]))->assertSuccessful();

        return DocsLabRun::query()->where('intent_id', $intent->id)->sole();
    }
}
