<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_the_root_sends_you_to_the_team_board(): void
    {
        // This app has no landing page and should not grow one. The root is a
        // signpost to the board, which is the thing that shows you the whole
        // ecosystem at once — the reason the Lab exists rather than one of the
        // sections inside it.
        $this->get('/')->assertRedirect('/lab/team');
    }
}
