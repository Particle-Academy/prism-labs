<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Selected only by WarningFailureGateTest's contained child, never a suite root.
final class SuppressedWarningProbeTest extends TestCase
{
    public function test_user_suppressed_warning(): void
    {
        @trigger_error('Synthetic suppressed warning gate control.', E_USER_WARNING);
        $this->assertTrue(true);
    }

    public function test_native_suppressed_warning(): void
    {
        @fopen('php://g260-invalid-warning-control', 'r');
        $this->assertTrue(true);
    }
}
