<?php

declare(strict_types=1);

namespace App\Lab;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;

final readonly class LabSession
{
    public function __construct(private PrismHarness $harness) {}

    public function resolve(Request $request): Session
    {
        // Prism Lab has one local human operator and one overseer. The agent's
        // memory must survive browser-session rotation and remain the same in
        // the Benchmark Studio, flyout, and full chat surface.
        return $this->resolveScope('lab:agent');
    }

    public function resolveScope(string $scope): Session
    {
        if (trim($scope) === '' || strlen($scope) > 240) {
            throw new \InvalidArgumentException('A Lab Harness scope must contain 1–240 characters.');
        }

        $operator = User::firstOrCreate(
            ['email' => 'lab@prism.local'],
            ['name' => 'Prism Lab operator', 'password' => bcrypt(Str::random(32))],
        );

        return $this->harness->for($operator)->session($scope);
    }
}
