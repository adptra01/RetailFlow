<?php

namespace Tests\Unit;

use App\Support\Sync\Backoff;
use Tests\TestCase;

class BackoffTest extends TestCase
{
    public function test_first_attempt_has_no_delay(): void
    {
        $this->assertSame(0, Backoff::delayFor(0));
    }

    public function test_delays_follow_the_configured_schedule(): void
    {
        config(['pos.sync.retry_backoff' => [1, 5, 10]]);

        $this->assertSame(1, Backoff::delayFor(1));
        $this->assertSame(5, Backoff::delayFor(2));
        $this->assertSame(10, Backoff::delayFor(3));
    }

    public function test_last_delay_is_the_ceiling(): void
    {
        config(['pos.sync.retry_backoff' => [1, 5, 10]]);

        $this->assertSame(10, Backoff::delayFor(4));
        $this->assertSame(10, Backoff::delayFor(99));
    }

    public function test_empty_schedule_means_no_delay(): void
    {
        config(['pos.sync.retry_backoff' => []]);

        $this->assertSame(0, Backoff::delayFor(3));
    }
}
