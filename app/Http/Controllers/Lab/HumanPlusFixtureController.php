<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use Inertia\Inertia;
use Inertia\Response;

final class HumanPlusFixtureController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/HumanPlusFixture', [
            'version' => InstalledVersions::prism(),
            'relayUrl' => (string) config('capabilities.human_plus.fixture.relay_url'),
            'sessionId' => (string) config('capabilities.human_plus.fixture.session_id'),
            'token' => (string) config('capabilities.human_plus.fixture.token'),
        ]);
    }
}
