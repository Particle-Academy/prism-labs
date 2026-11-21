<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Lab\PaidRunNotAuthorised;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Exceptions\RunNotPermitted;
use Throwable;

/** How a lane failure is recorded: a code, never an exception's text, which may quote a document or a provider. */
final class Failure
{
    public static function code(Throwable $e): string
    {
        return $e instanceof HasErrorCode ? 'refused:'.$e->code() : 'error:'.class_basename($e);
    }

    /** A refusal of spend or authority stops the whole run, not just the scenario. */
    public static function stopsRun(Throwable $e): bool
    {
        return $e instanceof CallRefused || $e instanceof RunNotPermitted || $e instanceof PaidRunNotAuthorised;
    }
}
