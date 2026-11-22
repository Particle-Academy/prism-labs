<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Acp\Accounts\ClaudeAccountBridge;
use App\Acp\Accounts\ClaudeAccountDirectory;
use App\Acp\Accounts\ClaudeAccountFailure;
use App\Acp\Accounts\LoginState;
use App\Http\Controllers\Controller;
use App\Jobs\ClaudeAccountLoginJob;
use App\Models\ClaudeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /lab/accounts: the owner adds, checks, switches and signs out Claude accounts with no terminal, through prism-acp's
 * `claudeAccounts` API. Owner-only; every change is a POST behind the CSRF check.
 *
 * The Lab stores a label, the directory and which account is active. Sign-in state, method and billing are asked of
 * the CLI each time (Check) and never stored.
 */
final class ClaudeAccountController extends Controller
{
    public function __construct(
        private readonly ClaudeAccountBridge $bridge,
        private readonly ClaudeAccountDirectory $directories,
        private readonly LoginState $logins,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Lab/Accounts', [
            'accounts' => $this->accounts(),
            'available' => $this->bridge->available(),
        ]);
    }

    /** Ask the CLI about every account: the default first, then each added one. */
    public function check(): JsonResponse
    {
        $default = $this->attempt(fn () => $this->bridge->describe(null));
        $accounts = [];
        foreach (ClaudeAccount::query()->orderBy('label')->get() as $account) {
            $accounts[(string) $account->id] = $this->attempt(function () use ($account): array {
                $path = $this->directories->pathOf($account);

                return [...$this->bridge->describe($path), 'shared' => $this->bridge->shared($path)];
            });
        }

        return response()->json(['default' => $default, 'accounts' => $accounts, 'list' => $this->accounts()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'label' => ['required', 'string', 'regex:'.ClaudeAccountDirectory::LABEL, Rule::unique('claude_accounts', 'label')],
            'method' => ['required', 'string', Rule::in(['subscription', 'console'])],
            'shared' => ['sometimes', 'boolean'],
        ], ['label.regex' => 'Use up to 32 lowercase letters, digits and dashes, starting with a letter or digit.']);
        try {
            $this->directories->ensureBase();
            $account = ClaudeAccount::query()->create(['label' => $input['label'], 'path' => $this->directories->pathFor($input['label']), 'active' => false]);
            if (($input['shared'] ?? false) === true) {
                $this->bridge->prepare($this->directories->pathOf($account), true);
            }
        } catch (ClaudeAccountFailure $failure) {
            return $this->refusal($failure);
        }

        return $this->beginLogin($account, $input['method']);
    }

    public function signIn(Request $request, ClaudeAccount $account): JsonResponse
    {
        $input = $request->validate(['method' => ['required', 'string', Rule::in(['subscription', 'console'])]]);

        return $this->beginLogin($account, $input['method']);
    }

    /** Polled by the page while a sign-in runs: a cache read, no CLI call. */
    public function login(ClaudeAccount $account): JsonResponse
    {
        return response()->json(['login' => $this->logins->view($account->id)]);
    }

    public function cancel(ClaudeAccount $account): JsonResponse
    {
        $this->logins->requestCancel($account->id);

        return response()->json(['login' => $this->logins->view($account->id)]);
    }

    public function signOut(ClaudeAccount $account): JsonResponse
    {
        if ($this->logins->pending($account->id)) {
            return response()->json(['error' => 'Cancel the sign-in in progress first.'], 409);
        }
        try {
            return response()->json(['status' => $this->bridge->logout($this->directories->pathOf($account))]);
        } catch (ClaudeAccountFailure $failure) {
            return $this->refusal($failure);
        }
    }

    public function shared(Request $request, ClaudeAccount $account): JsonResponse
    {
        $input = $request->validate(['shared' => ['required', 'boolean']]);
        try {
            $path = $this->directories->pathOf($account);
            $this->bridge->prepare($path, $input['shared']);

            return response()->json(['shared' => $this->bridge->shared($path)]);
        } catch (ClaudeAccountFailure $failure) {
            return $this->refusal($failure);
        }
    }

    /** Record which account the Lab's Claude launches use. Null is the CLI's default account. */
    public function activate(Request $request): JsonResponse
    {
        $input = $request->validate(['account' => ['present', 'nullable', 'integer', Rule::exists('claude_accounts', 'id')]]);
        if ($input['account'] !== null) {
            try {
                $this->directories->pathOf(ClaudeAccount::query()->findOrFail($input['account']));
            } catch (ClaudeAccountFailure $failure) {
                return $this->refusal($failure);
            }
        }
        DB::transaction(function () use ($input): void {
            ClaudeAccount::query()->where('active', true)->update(['active' => false]);
            if ($input['account'] !== null) {
                ClaudeAccount::query()->whereKey($input['account'])->update(['active' => true]);
            }
        });

        return response()->json(['list' => $this->accounts()]);
    }

    private function beginLogin(ClaudeAccount $account, string $method): JsonResponse
    {
        if (! $this->bridge->available()) {
            return $this->refusal(new ClaudeAccountFailure('moduleUnavailable'));
        }
        $token = DB::transaction(function () use ($account): ?string {
            ClaudeAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            return $this->logins->pending($account->id) ? null : $this->logins->begin($account->id);
        });
        if ($token === null) {
            return response()->json(['error' => 'A sign-in for this account is already in progress.'], 409);
        }
        ClaudeAccountLoginJob::dispatch($account->id, $method, $token);

        return response()->json(['account' => $this->row($account), 'login' => $this->logins->view($account->id)], 202);
    }

    private function accounts(): array
    {
        return ClaudeAccount::query()->orderBy('label')->get()->map(fn (ClaudeAccount $account) => $this->row($account))->all();
    }

    private function row(ClaudeAccount $account): array
    {
        $account->refresh();

        return ['id' => $account->id, 'label' => $account->label, 'active' => $account->active, 'login' => $this->logins->view($account->id)];
    }

    private function attempt(\Closure $call): array
    {
        try {
            return $call();
        } catch (ClaudeAccountFailure $failure) {
            return ['error' => $failure->reason, 'message' => $failure->getMessage()];
        }
    }

    private function refusal(ClaudeAccountFailure $failure): JsonResponse
    {
        return response()->json(['error' => $failure->getMessage(), 'code' => $failure->reason], 422);
    }
}
