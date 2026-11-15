<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lab\OwnerIdentity;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireApprovedRun
{
    public function __construct(private OwnerIdentity $identity, private RunAuthority $authority, private RunScopeFactory $scopes, private PaidRunContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->identity->requireOwner();
        $header = $request->header('X-Lab-Run-Id');
        $body = $request->input('approved_run_id');
        if (($header !== null && $body !== null && $header !== $body)
            || ! is_string($id = $header ?? $body) || strlen($id) !== 36) {
            throw new PaidRunNotAuthorised;
        }
        $scope = $this->scopes->fromRequest($request);
        $intent = $this->authority->consume($id, $scope);
        $request->attributes->set('lab_run_authority', $intent);
        $request->attributes->set('lab_run_scope', $scope);

        return $this->context->within($intent->id, $scope, fn (): Response => $next($request));
    }
}
