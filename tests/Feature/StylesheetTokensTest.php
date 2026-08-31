<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every CSS custom property the stylesheet USES must be one it DEFINES.
 *
 * This exists because of a specific failure, not a hypothesis. `--k-bg-0` was
 * referenced by the PLab drawer and its composer and defined nowhere. An
 * undefined custom property does not fall back to something sensible — it makes
 * the whole declaration invalid at computed-value time, so
 * `background: var(--k-bg-0)` resolved to `transparent` and the agent chat let
 * the page show through it. `--k-violet` and `--font-mono` were the same,
 * quietly dropping accent borders and monospace runs.
 *
 * Nothing reports this. There is no console error, no build warning, and the
 * page renders — just wrong. It took a screenshot from a human to find, which
 * is the most expensive way to catch a typo in a variable name.
 */
class StylesheetTokensTest extends TestCase
{
    public function test_no_css_variable_is_used_without_being_defined(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match_all('/var\((--[a-z0-9-]+)/i', (string) $css, $used);
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/im', (string) $css, $defined);

        $undefined = array_values(array_unique(array_diff($used[1], $defined[1])));

        $this->assertSame([], $undefined, sprintf(
            'These custom properties are used but never defined, so every declaration reading them '
            .'is invalid and silently resolves to the initial value (transparent, for a background): %s',
            implode(', ', $undefined),
        ));
    }
}
