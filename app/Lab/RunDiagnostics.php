<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Prism\Exceptions\PrismRunException;

/** Explicitly opt into partial provider content for the local Lab response only. */
final class RunDiagnostics
{
    /** @return array<string, mixed> */
    public static function from(PrismRunException $exception): array
    {
        return [
            'code' => $exception->code(),
            'run_id' => $exception->runId(),
            'incomplete_reason' => $exception->incompleteReason(),
            'output' => $exception->output(),
            'citations' => $exception->citations(),
            'usage' => $exception->usage()?->toArray(),
        ];
    }
}
