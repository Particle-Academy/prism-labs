<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_the_root_sends_you_to_the_operations_cockpit(): void
    {
        $this->get('/')->assertRedirect('/lab');
    }
}
