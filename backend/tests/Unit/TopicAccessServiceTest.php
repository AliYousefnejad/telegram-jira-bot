<?php

namespace Tests\Unit;

use App\Models\TelegramGroup;
use App\Services\Telegram\TopicAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TopicAccessServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const GROUP_CHAT_ID = -1001234567890;

    private const THREAD_ID_1 = 42;

    private const THREAD_ID_2 = 99;

    private TopicAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TopicAccessService::class);
    }

    public function test_no_restriction_allows_any_topic(): void
    {
        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_1));
    }

    public function test_no_restriction_allows_null_thread_id(): void
    {
        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, null));
    }

    public function test_one_allowed_topic_permits_only_that_topic(): void
    {
        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_1, 'topic_name' => 'Bug Reports']);

        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_1));
        $this->assertFalse($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_2));
        $this->assertFalse($this->service->isTopicAllowed(self::GROUP_CHAT_ID, null));
    }

    public function test_multiple_allowed_topics_permit_any_of_them(): void
    {
        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_1]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_2]);

        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_1));
        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_2));
        $this->assertFalse($this->service->isTopicAllowed(self::GROUP_CHAT_ID, 777));
    }

    public function test_empty_allowed_topics_after_removal_restores_unrestricted(): void
    {
        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_1]);

        $this->assertFalse($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_2));

        $this->service->removeTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1);

        $this->assertTrue($this->service->isTopicAllowed(self::GROUP_CHAT_ID, self::THREAD_ID_2));
        $this->assertNull(TelegramGroup::where('chat_id', self::GROUP_CHAT_ID)->first());
    }

    public function test_add_topic_creates_group_and_topic(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'Bug Reports');

        $group = TelegramGroup::where('chat_id', self::GROUP_CHAT_ID)->first();
        $this->assertNotNull($group);
        $this->assertTrue($group->is_forum);

        $topic = $group->allowedTopics()->where('message_thread_id', self::THREAD_ID_1)->first();
        $this->assertNotNull($topic);
        $this->assertSame('Bug Reports', $topic->topic_name);
    }

    public function test_add_topic_updates_existing_topic_name(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'Old Name');
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'New Name');

        $group = TelegramGroup::where('chat_id', self::GROUP_CHAT_ID)->first();
        $topic = $group->allowedTopics()->where('message_thread_id', self::THREAD_ID_1)->first();

        $this->assertSame('New Name', $topic->topic_name);
        $this->assertSame(1, $group->allowedTopics()->count());
    }

    public function test_remove_topic_deletes_only_that_topic(): void
    {
        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_1]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_2]);

        $this->service->removeTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1);

        $this->assertFalse($group->allowedTopics()->where('message_thread_id', self::THREAD_ID_1)->exists());
        $this->assertTrue($group->allowedTopics()->where('message_thread_id', self::THREAD_ID_2)->exists());
    }

    public function test_remove_topic_from_nonexistent_group_does_not_fail(): void
    {
        $this->service->removeTopic(99999, self::THREAD_ID_1);

        $this->assertNull(TelegramGroup::where('chat_id', 99999)->first());
    }

    public function test_get_allowed_topics_returns_empty_for_unknown_group(): void
    {
        $topics = $this->service->getAllowedTopics(99999);

        $this->assertCount(0, $topics);
    }

    public function test_get_allowed_topics_returns_all_topics(): void
    {
        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_1, 'topic_name' => 'Topic A']);
        $group->allowedTopics()->create(['message_thread_id' => self::THREAD_ID_2, 'topic_name' => 'Topic B']);

        $topics = $this->service->getAllowedTopics(self::GROUP_CHAT_ID);

        $this->assertCount(2, $topics);
        $this->assertTrue($topics->contains('message_thread_id', self::THREAD_ID_1));
        $this->assertTrue($topics->contains('message_thread_id', self::THREAD_ID_2));
    }

    public function test_null_topic_name_stored_correctly(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1);

        $group = TelegramGroup::where('chat_id', self::GROUP_CHAT_ID)->first();
        $topic = $group->allowedTopics()->first();

        $this->assertNull($topic->topic_name);
    }

    public function test_get_cached_topic_name_returns_name(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'Bug Reports');

        $name = $this->service->getCachedTopicName(self::GROUP_CHAT_ID, self::THREAD_ID_1);

        $this->assertSame('Bug Reports', $name);
    }

    public function test_get_cached_topic_name_returns_null_for_unknown_group(): void
    {
        $name = $this->service->getCachedTopicName(99999, self::THREAD_ID_1);

        $this->assertNull($name);
    }

    public function test_get_cached_topic_name_returns_null_for_unknown_topic(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'Bug Reports');

        $name = $this->service->getCachedTopicName(self::GROUP_CHAT_ID, self::THREAD_ID_2);

        $this->assertNull($name);
    }

    public function test_update_topic_name_updates_stored_value(): void
    {
        $this->service->addTopic(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'Old Name');

        $this->service->updateTopicName(self::GROUP_CHAT_ID, self::THREAD_ID_1, 'New Name');

        $group = TelegramGroup::where('chat_id', self::GROUP_CHAT_ID)->first();
        $topic = $group->allowedTopics()->where('message_thread_id', self::THREAD_ID_1)->first();

        $this->assertSame('New Name', $topic->topic_name);
    }

    public function test_update_topic_name_for_nonexistent_group_does_not_fail(): void
    {
        $this->service->updateTopicName(99999, self::THREAD_ID_1, 'New Name');

        // No error thrown, no side effects.
        $this->assertNull(TelegramGroup::where('chat_id', 99999)->first());
    }
}
