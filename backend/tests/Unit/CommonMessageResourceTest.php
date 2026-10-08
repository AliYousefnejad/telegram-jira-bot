<?php

namespace Tests\Unit;

use App\Exceptions\JiraTimeoutException;
use App\Resources\CommonMessageResource;
use RuntimeException;
use Tests\TestCase;

class CommonMessageResourceTest extends TestCase
{
    public function test_only_timeout_exceptions_get_the_timeout_message(): void
    {
        $this->assertSame(
            CommonMessageResource::timeoutError(),
            CommonMessageResource::forThrowable(new JiraTimeoutException('cURL error 28'))
        );
    }

    public function test_other_failures_keep_their_own_fallback_message(): void
    {
        $this->assertSame(
            'custom error',
            CommonMessageResource::forThrowable(new RuntimeException('Jira API returned HTTP 500'), 'custom error')
        );
    }

    public function test_failures_without_a_fallback_get_the_internal_error_message(): void
    {
        $this->assertSame(
            CommonMessageResource::internalError(),
            CommonMessageResource::forThrowable(new RuntimeException('anything'))
        );
    }

    public function test_timeout_wins_over_the_fallback(): void
    {
        $this->assertSame(
            CommonMessageResource::timeoutError(),
            CommonMessageResource::forThrowable(new JiraTimeoutException('timed out'), 'custom error')
        );
    }
}
