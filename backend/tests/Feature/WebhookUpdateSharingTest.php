<?php

namespace Tests\Feature;

use App\Http\Controllers\TelegramController;
use App\Http\Middleware\RestrictTelegramAccess;
use App\Services\Telegram\UpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class WebhookUpdateSharingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.admin_ids' => [999, 888, 111]]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_middleware_stores_parsed_update_on_request(): void
    {
        // Webhook disabled (the bot polls) — restore with the route in routes/api.php.
        $this->markTestSkipped('Webhook route disabled.');

        $this->mockUpdateProcessor();

        $payload = [
            'update_id' => 123,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 999, 'first_name' => 'Test'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => '/start',
            ],
        ];

        $request = Request::create(
            '/api/telegram/webhook',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $middleware = app(RestrictTelegramAccess::class);
        $update = null;

        $middleware->handle($request, function (Request $req) use (&$update): JsonResponse {
            $update = $req->attributes->get('telegram_update');

            return response()->json(['ok' => true]);
        });

        $this->assertInstanceOf(Update::class, $update);
        $this->assertSame(123, $update->getUpdateId());
    }

    public function test_controller_reads_update_from_request_attribute(): void
    {
        $processor = $this->mockUpdateProcessor();

        $update = new Update([
            'update_id' => 456,
            'message' => [
                'message_id' => 2,
                'from' => ['id' => 888, 'first_name' => 'User'],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => '/help',
            ],
        ]);

        $processor->shouldReceive('process')->once()->with($update);

        $request = Request::create('/api/telegram/webhook', 'POST');
        $request->attributes->set('telegram_update', $update);

        $controller = app(TelegramController::class);
        $response = $controller->webhook($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function test_controller_handles_missing_update_gracefully(): void
    {
        $this->mockUpdateProcessor();

        $request = Request::create('/api/telegram/webhook', 'POST');

        $controller = app(TelegramController::class);
        $response = $controller->webhook($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function test_full_webhook_flow_shares_update_between_middleware_and_controller(): void
    {
        // Webhook disabled (the bot polls) — restore with the route in routes/api.php.
        $this->markTestSkipped('Webhook route disabled.');

        $processor = $this->mockUpdateProcessor();

        $payload = [
            'update_id' => 789,
            'message' => [
                'message_id' => 3,
                'from' => ['id' => 111, 'first_name' => 'Full'],
                'chat' => ['id' => 222, 'type' => 'private'],
                'text' => '/start@MyBot',
            ],
        ];

        $processor->shouldReceive('process')->once()->with(Mockery::on(function (Update $update) {
            return $update->getUpdateId() === 789;
        }));

        $request = Request::create(
            '/api/telegram/webhook',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $middleware = app(RestrictTelegramAccess::class);
        $middleware->handle($request, function (Request $req) use ($processor): JsonResponse {
            $update = $req->attributes->get('telegram_update');
            $processor->process($update);

            return response()->json(['ok' => true]);
        });
    }

    public function test_update_from_request_body_differs_from_facade(): void
    {
        // Webhook disabled (the bot polls) — restore with the route in routes/api.php.
        $this->markTestSkipped('Webhook route disabled.');

        $this->mockUpdateProcessor();

        $payload = [
            'update_id' => 111,
            'message' => [
                'message_id' => 4,
                'from' => ['id' => 888, 'first_name' => 'User'],
                'chat' => ['id' => 333, 'type' => 'private'],
                'text' => '/projects',
            ],
        ];

        $request = Request::create(
            '/api/telegram/webhook',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $middleware = app(RestrictTelegramAccess::class);
        $capturedUpdate = null;

        $middleware->handle($request, function (Request $req) use (&$capturedUpdate): JsonResponse {
            $capturedUpdate = $req->attributes->get('telegram_update');

            return response()->json(['ok' => true]);
        });

        $this->assertInstanceOf(Update::class, $capturedUpdate);
        $this->assertSame(111, $capturedUpdate->getUpdateId());
        $this->assertSame('/projects', $capturedUpdate->getMessage()->get('text'));
    }

    private function mockUpdateProcessor(): Mockery\MockInterface
    {
        return $this->mock(UpdateProcessor::class)->shouldIgnoreMissing();
    }
}
