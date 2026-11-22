<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Acp\Accounts\ClaudeAccountBridge;
use App\Acp\Accounts\ClaudeAccountDirectory;
use App\Acp\Accounts\ClaudeAccountFailure;
use App\Acp\Accounts\LoginState;
use App\Acp\Accounts\NodeClaudeAccountBridge;
use App\Jobs\ClaudeAccountLoginJob;
use App\Lab\RunScopeFactory;
use App\Models\ClaudeAccount;
use App\Models\User;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * /lab/accounts: Claude accounts added, checked, switched and signed out with no terminal.
 *
 * The bridge to prism-acp is faked for the HTTP tests, and the real helper script is run against a fake prism-acp
 * module for the bridge tests: nothing here runs a Claude CLI or signs anything in.
 */
final class ClaudeAccountsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $owner;

    private FakeClaudeAccountBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $this->directory = str_replace('\\', '/', storage_path('framework/testing/claude-accounts-'.bin2hex(random_bytes(6))));
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'acp.accounts.directory' => $this->directory,
            'acp.accounts.login_store' => 'array',
            'acp.accounts.module' => base_path('tests/fixtures/claude-accounts/fake-prism-acp.mjs'),
            'acp.claude.binary' => 'claude-fixture-never-run',
            'acp.codex.model' => 'codex-fixture-model',
            'queue.default' => 'sync',
        ]);
        $this->bridge = new FakeClaudeAccountBridge;
        $this->app->instance(ClaudeAccountBridge::class, $this->bridge);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->owner->id]);
        $this->actingAs($this->owner, 'web');
        $this->withSession(['_token' => 'accounts-csrf'])->withHeader('X-CSRF-TOKEN', 'accounts-csrf');
        $directory = $this->directory;
        $this->beforeApplicationDestroyed(fn () => app(Filesystem::class)->deleteDirectory($directory));
    }

    public function test_every_route_is_owner_only(): void
    {
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $stranger = User::factory()->create();
        $this->actingAs($stranger, 'web');
        $this->get('/lab/accounts')->assertForbidden();
        $this->getJson("/lab/accounts/{$account->id}/login")->assertForbidden();
        foreach (['/lab/accounts', '/lab/accounts/check', '/lab/accounts/active', "/lab/accounts/{$account->id}/login",
            "/lab/accounts/{$account->id}/login/cancel", "/lab/accounts/{$account->id}/logout", "/lab/accounts/{$account->id}/shared"] as $uri) {
            $this->postJson($uri, ['label' => 'x', 'method' => 'subscription', 'account' => null, 'shared' => true])->assertForbidden();
        }
        $this->assertSame([], $this->bridge->calls);
        $this->assertFalse(ClaudeAccount::query()->where('label', 'x')->exists());
    }

    public function test_changes_are_posts_in_the_web_group_so_the_csrf_check_applies(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'lab/accounts')) {
                continue;
            }
            $this->assertContains('web', $route->gatherMiddleware(), $route->uri());
            if (! in_array('GET', $route->methods(), true)) {
                $this->assertSame(['POST'], $route->methods(), $route->uri());
            }
        }
        $this->withHeader('X-CSRF-TOKEN', 'not-the-session-token');
        $this->postJson('/lab/accounts', ['label' => 'work', 'method' => 'subscription'])->assertStatus(419);
        $this->postJson('/lab/accounts/active', ['account' => null])->assertStatus(419);
        $this->assertSame(0, ClaudeAccount::query()->count());
        $this->assertSame([], $this->bridge->calls);
    }

    public function test_the_page_renders_the_stored_accounts(): void
    {
        ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work', 'active' => true]);
        $this->get('/lab/accounts')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/Accounts')
            ->where('available', true)
            ->where('accounts.0.label', 'work')
            ->where('accounts.0.active', true));
    }

    public function test_adding_an_account_stores_only_a_label_and_path_and_relays_the_url_then_drops_it(): void
    {
        $this->bridge->onLogin = function (string $dir, string $method, Closure $onUrl): array {
            $onUrl('https://claude.ai/oauth/authorize?state=abc');
            $this->assertSame('https://claude.ai/oauth/authorize?state=abc', app(LoginState::class)->view(ClaudeAccount::query()->sole()->id)['url']);

            return ['signedIn' => true, 'method' => 'subscription', 'billing' => 'subscription'];
        };

        $response = $this->postJson('/lab/accounts', ['label' => 'work', 'method' => 'console', 'shared' => true])->assertStatus(202);

        $this->assertSame(['id', 'label', 'path', 'active', 'created_at', 'updated_at'], Schema::getColumnListing('claude_accounts'));
        $account = ClaudeAccount::query()->sole();
        $this->assertSame($this->directory.'/work', $account->path);
        $this->assertFalse($account->active);
        $this->assertSame([['prepare', $this->directory.'/work', true], ['login', $this->directory.'/work', 'console']], $this->bridge->calls);
        $this->assertSame('signedIn', $response->json('login.state'));
        $this->assertNull($response->json('login.url'), 'the URL is dropped once the sign-in ends');
        $this->assertArrayNotHasKey('token', $response->json('login'));
        $this->assertDirectoryExists($this->directory);
    }

    public function test_a_label_cannot_choose_the_directory(): void
    {
        foreach (['../escape', 'Work', '', '-dash', 'a/b', str_repeat('a', 33), 'c:', 'wo rk'] as $label) {
            $this->postJson('/lab/accounts', ['label' => $label, 'method' => 'subscription'])->assertUnprocessable();
        }
        $this->postJson('/lab/accounts', ['label' => 'work', 'method' => 'apiKey'])->assertUnprocessable();
        $this->assertSame(0, ClaudeAccount::query()->count());
        $this->assertSame([], $this->bridge->calls);
    }

    public function test_a_row_pointing_anywhere_else_is_refused_before_the_cli_is_reached(): void
    {
        $home = str_replace('\\', '/', (string) (getenv('USERPROFILE') ?: getenv('HOME'))).'/.claude';
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $home]);

        $this->postJson("/lab/accounts/{$account->id}/logout")->assertUnprocessable()->assertJsonPath('code', 'configDirInvalid');
        $this->postJson("/lab/accounts/{$account->id}/shared", ['shared' => true])->assertUnprocessable();
        $this->postJson('/lab/accounts/active', ['account' => $account->id])->assertUnprocessable();
        $this->postJson("/lab/accounts/{$account->id}/login", ['method' => 'subscription'])->assertStatus(202)->assertJsonPath('login.state', 'failed');
        $this->assertSame([], $this->bridge->calls);
        $this->assertFalse($account->refresh()->active);
    }

    public function test_check_asks_the_cli_about_every_account(): void
    {
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $this->bridge->failDescribe = 'timeout';

        $json = $this->postJson('/lab/accounts/check')->assertOk()->json();

        $this->assertSame('timeout', $json['default']['error']);
        $this->assertSame([['describe', null], ['describe', $this->directory.'/work']], $this->bridge->calls);
        $this->assertSame('timeout', $json['accounts'][(string) $account->id]['error']);

        $this->bridge->failDescribe = null;
        $this->bridge->calls = [];
        $json = $this->postJson('/lab/accounts/check')->assertOk()->json();
        $this->assertTrue($json['accounts'][(string) $account->id]['signedIn']);
        $this->assertFalse($json['accounts'][(string) $account->id]['shared']);
    }

    public function test_make_active_reaches_the_approved_claude_launch(): void
    {
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $other = ClaudeAccount::query()->create(['label' => 'home', 'path' => $this->directory.'/home', 'active' => true]);
        $scope = fn () => app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['prompt' => 'hi', 'provider' => 'claude'])->payload['acp'];

        $this->postJson('/lab/accounts/active', ['account' => $account->id])->assertOk();
        $this->assertTrue($account->refresh()->active);
        $this->assertFalse($other->refresh()->active, 'one account is active at a time');
        $this->assertSame(['label' => 'work', 'config_dir' => $this->directory.'/work'], $scope()['claude_account']);

        $env = ClaudeAccountDirectory::launchEnvironment(['PATH' => '/bin', 'claude_config_dir' => '/inherited'], $scope()['claude_account']['config_dir']);
        $this->assertSame(['PATH' => '/bin', 'CLAUDE_CONFIG_DIR' => $this->directory.'/work'], $env);

        $this->postJson('/lab/accounts/active', ['account' => null])->assertOk();
        $this->assertSame(0, ClaudeAccount::query()->where('active', true)->count());
        $this->assertSame(['label' => null, 'config_dir' => null], $scope()['claude_account']);
        $this->assertSame(['PATH' => '/bin'], ClaudeAccountDirectory::launchEnvironment(['PATH' => '/bin', 'CLAUDE_CONFIG_DIR' => '/inherited'], null),
            'the default account is the CLI default, never an inherited directory');

        $codex = app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['prompt' => 'hi', 'provider' => 'codex'])->payload['acp'];
        $this->assertArrayNotHasKey('claude_account', $codex);
        $this->assertSame([], $this->bridge->calls);
    }

    public function test_switching_accounts_after_approval_changes_the_approved_scope(): void
    {
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $digest = fn () => hash('sha256', json_encode(app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['prompt' => 'hi', 'provider' => 'claude'])->payload['acp']));
        $before = $digest();
        $this->postJson('/lab/accounts/active', ['account' => $account->id])->assertOk();
        $this->assertNotSame($before, $digest());
    }

    public function test_cancel_before_a_worker_starts_means_the_cli_is_never_run(): void
    {
        Queue::fake();
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $this->postJson("/lab/accounts/{$account->id}/login", ['method' => 'subscription'])->assertStatus(202)->assertJsonPath('login.state', 'queued');
        $this->postJson("/lab/accounts/{$account->id}/login", ['method' => 'subscription'])->assertStatus(409);
        $this->postJson("/lab/accounts/{$account->id}/logout")->assertStatus(409);

        $this->postJson("/lab/accounts/{$account->id}/login/cancel")->assertOk()->assertJsonPath('login.state', 'cancelled');

        Queue::assertPushed(ClaudeAccountLoginJob::class, function (ClaudeAccountLoginJob $job): bool {
            app()->call([$job, 'handle']);

            return $job->queue === 'overseer';
        });
        $this->assertSame([], $this->bridge->calls);
        $this->assertSame('cancelled', $this->getJson("/lab/accounts/{$account->id}/login")->json('login.state'));
    }

    public function test_a_failure_carries_a_code_and_a_sentence_never_the_cli_text(): void
    {
        $account = ClaudeAccount::query()->create(['label' => 'work', 'path' => $this->directory.'/work']);
        $this->bridge->onLogin = fn () => throw new ClaudeAccountFailure('codeRequired');
        $login = $this->postJson("/lab/accounts/{$account->id}/login", ['method' => 'subscription'])->json('login');
        $this->assertSame(['failed', 'codeRequired'], [$login['state'], $login['code']]);
        $this->assertStringContainsString('pasted code', $login['message']);

        $this->bridge->failPrepare = 'unexpectedLink';
        $this->postJson("/lab/accounts/{$account->id}/shared", ['shared' => false])->assertUnprocessable()->assertJsonPath('code', 'unexpectedLink');
    }

    public function test_the_helper_relays_a_checked_url_and_no_credential(): void
    {
        $node = new NodeClaudeAccountBridge;
        putenv('ANTHROPIC_API_KEY=synthetic-must-not-reach-the-helper');
        putenv('CLAUDE_CONFIG_DIR=/must-not-reach-the-helper');
        // Where Laravel's dotenv puts .env values: Symfony's Process would pass these on by default.
        $_ENV['LAB_DOTENV_API_KEY'] = $_SERVER['LAB_DOTENV_API_KEY'] = 'synthetic-dotenv-key';
        try {
            $default = $node->describe(null);
            $signed = $node->describe($this->directory.'/signed');
        } finally {
            putenv('ANTHROPIC_API_KEY');
            putenv('CLAUDE_CONFIG_DIR');
            unset($_ENV['LAB_DOTENV_API_KEY'], $_SERVER['LAB_DOTENV_API_KEY']);
        }
        $this->assertSame(['signedIn', 'method', 'billing', 'email', 'organizationName', 'subscriptionType', 'configDirectoryMatches', 'loginExpiresAt'], array_keys($signed));
        $this->assertNull($default['organizationName'], 'the fixture names any Lab key or CLAUDE_CONFIG_DIR it can see');
        $this->assertTrue($signed['signedIn']);
        $this->assertFalse($node->describe($this->directory.'/other')['signedIn']);

        $urls = [];
        $status = $node->login($this->directory.'/work', 'subscription', function (string $url) use (&$urls): void {
            $urls[] = $url;
        }, fn () => false, 60);
        $this->assertSame(['https://claude.ai/oauth/authorize?state=fixture'], $urls);
        $this->assertTrue($status['signedIn']);
        $this->assertStringNotContainsString('must-not-pass-through', json_encode($status));

        $this->assertSame(['sharedSessions' => true, 'linked' => 'created'], $node->prepare($this->directory.'/work', true));
        $this->assertNull($node->shared($this->directory.'/work'));
        $this->assertFalse($node->logout($this->directory.'/work')['signedIn']);
        $this->assertFailure('missingDirectory', fn () => $node->logout($this->directory.'/missing'));
        $this->assertFailure('realFolder', fn () => $node->prepare($this->directory.'/own', true));
        $this->assertFailure('configDirInvalid', fn () => $node->logout('relative/dir'));
    }

    public function test_the_helper_refuses_a_lookalike_url_and_cancel_ends_the_sign_in(): void
    {
        $node = new NodeClaudeAccountBridge;
        $urls = [];
        $this->assertFailure('refusedUrl', fn () => $node->login($this->directory.'/evil', 'subscription', function (string $url) use (&$urls): void {
            $urls[] = $url;
        }, fn () => false, 60));
        $this->assertSame([], $urls, 'a refused URL is never handed out');

        $started = microtime(true);
        $this->assertFailure('aborted', fn () => $node->login($this->directory.'/slow', 'subscription', function (string $url) use (&$urls): void {
            $urls[] = $url;
        }, function () use (&$urls): bool {
            return $urls !== [];
        }, 60));
        $this->assertLessThan(15, microtime(true) - $started, 'cancel ends it rather than the fixture timeout');

        config(['acp.accounts.module' => base_path('tests/fixtures/claude-accounts/absent.mjs')]);
        $this->assertFalse($node->available());
        $this->assertFailure('moduleUnavailable', fn () => $node->describe(null));
    }

    public function test_the_sign_in_url_check_is_exact(): void
    {
        foreach (['https://claude.ai/x', 'https://platform.claude.com/oauth?a=b', 'https://console.anthropic.com/'] as $good) {
            $this->assertSame($good, NodeClaudeAccountBridge::signInUrl($good));
        }
        foreach (['http://claude.ai/x', 'https://claude.ai.example/x', 'https://user@claude.ai/x', 'https://claude.ai:8443/x',
            'https://evil.com/?claude.ai', "https://claude.ai/x\n", 'javascript:alert(1)', null, 42] as $bad) {
            $this->assertNull(NodeClaudeAccountBridge::signInUrl($bad));
        }
    }

    private function assertFailure(string $code, Closure $call): void
    {
        try {
            $call();
            $this->fail("expected {$code}");
        } catch (ClaudeAccountFailure $failure) {
            $this->assertSame($code, $failure->reason);
        }
    }
}

final class FakeClaudeAccountBridge implements ClaudeAccountBridge
{
    public array $calls = [];

    public ?Closure $onLogin = null;

    public ?string $failDescribe = null;

    public ?string $failPrepare = null;

    public function available(): bool
    {
        return true;
    }

    public function describe(?string $configDir): array
    {
        $this->calls[] = ['describe', $configDir];
        if ($this->failDescribe !== null) {
            throw new ClaudeAccountFailure($this->failDescribe);
        }

        return NodeClaudeAccountBridge::view(['signedIn' => true, 'method' => 'subscription', 'billing' => 'subscription']);
    }

    public function login(string $configDir, string $method, Closure $onUrl, Closure $cancelled, int $seconds): array
    {
        $this->calls[] = ['login', $configDir, $method];

        return NodeClaudeAccountBridge::view(($this->onLogin ?? fn () => ['signedIn' => true])($configDir, $method, $onUrl, $cancelled));
    }

    public function logout(string $configDir): array
    {
        $this->calls[] = ['logout', $configDir];

        return NodeClaudeAccountBridge::view(['signedIn' => false]);
    }

    public function prepare(string $configDir, bool $sharedSessions): array
    {
        $this->calls[] = ['prepare', $configDir, $sharedSessions];
        if ($this->failPrepare !== null) {
            throw new ClaudeAccountFailure($this->failPrepare);
        }

        return ['sharedSessions' => $sharedSessions, 'linked' => $sharedSessions ? 'created' : null];
    }

    public function shared(string $configDir): ?bool
    {
        return false;
    }
}
