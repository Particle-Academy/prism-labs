<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_the_root_sends_you_to_the_chat_console(): void
    {
        // This app has no landing page and should not grow one. The root is a
        // signpost to the first thing you would actually use.
        $this->get('/')->assertRedirect('/lab/chat');
    }
}
