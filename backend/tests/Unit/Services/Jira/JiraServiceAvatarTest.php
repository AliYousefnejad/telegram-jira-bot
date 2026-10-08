<?php

namespace Tests\Unit\Services\Jira;

use App\Services\Jira\JiraService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JiraServiceAvatarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.base_url' => 'https://jira.example.com', 'services.jira.pat' => 'pat-token']);
    }

    #[Test]
    public function it_downloads_an_avatar_from_the_jira_host_with_the_jira_token(): void
    {
        Http::fake(['jira.example.com/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $image = app(JiraService::class)->fetchAvatar('https://jira.example.com/secure/useravatar?ownerId=ali');

        $this->assertSame(['body' => 'PNGDATA', 'type' => 'image/png'], $image);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer pat-token'));
    }

    #[Test]
    #[Group('security')]
    public function it_never_fetches_from_another_host(): void
    {
        Http::fake();

        $this->assertNull(app(JiraService::class)->fetchAvatar('https://evil.example.net/steal'));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_non_image_response_is_ignored(): void
    {
        Http::fake(['jira.example.com/*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertNull(app(JiraService::class)->fetchAvatar('https://jira.example.com/secure/useravatar'));
    }
}
