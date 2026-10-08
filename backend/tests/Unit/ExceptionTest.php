<?php

namespace Tests\Unit;

use App\Exceptions\AuthorizationException;
use App\Exceptions\FlowException;
use App\Exceptions\JiraApiException;
use App\Exceptions\JiraTimeoutException;
use App\Exceptions\TelegramApiException;
use Tests\TestCase;

class ExceptionTest extends TestCase
{
    public function test_jira_timeout_exception_from_connection(): void
    {
        $e = JiraTimeoutException::fromConnection('http://example.com', 'timeout');

        $this->assertStringContainsString('http://example.com', $e->getMessage());
        $this->assertStringContainsString('timeout', $e->getMessage());
    }

    public function test_jira_api_exception_from_response(): void
    {
        $e = JiraApiException::fromResponse('http://example.com', 404, 'Not Found');

        $this->assertStringContainsString('http://example.com', $e->getMessage());
        $this->assertStringContainsString('404', $e->getMessage());
        $this->assertStringContainsString('Not Found', $e->getMessage());
    }

    public function test_telegram_api_exception_from_response(): void
    {
        $e = TelegramApiException::fromResponse('sendMessage', 400, 'Bad Request');

        $this->assertStringContainsString('sendMessage', $e->getMessage());
        $this->assertStringContainsString('400', $e->getMessage());
        $this->assertStringContainsString('Bad Request', $e->getMessage());
    }

    public function test_flow_exception_invalid_step(): void
    {
        $e = FlowException::invalidStep('bad_step');

        $this->assertStringContainsString('bad_step', $e->getMessage());
    }

    public function test_flow_exception_conversation_expired(): void
    {
        $e = FlowException::conversationExpired(123);

        $this->assertStringContainsString('123', $e->getMessage());
    }

    public function test_authorization_exception_not_authorized(): void
    {
        $e = AuthorizationException::notAuthorized(456);

        $this->assertStringContainsString('456', $e->getMessage());
    }

    public function test_authorization_exception_not_linked(): void
    {
        $e = AuthorizationException::notLinked(789);

        $this->assertStringContainsString('789', $e->getMessage());
    }
}
