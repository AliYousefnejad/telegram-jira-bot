<?php

namespace Tests\Unit;

use App\Exceptions\JiraTimeoutException;
use App\Services\Jira\JiraService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JiraServiceTest extends TestCase
{
    public function test_user_id_prefers_name_on_data_center(): void
    {
        $this->assertSame('jsmith', JiraService::userId(['name' => 'jsmith', 'key' => 'jsmith', 'accountId' => 'hash-123']));
        $this->assertSame('jsmith', JiraService::userId(['key' => 'jsmith', 'accountId' => 'hash-123']));
        $this->assertSame('hash-123', JiraService::userId(['accountId' => 'hash-123']));
        $this->assertSame('', JiraService::userId([]));
    }

    public function test_get_users_searches_by_username_on_data_center(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/user/search*' => Http::response([
                ['name' => 'jsmith', 'key' => 'JIRAUSER100', 'displayName' => 'John Smith', 'emailAddress' => 'jsmith@example.com'],
            ]),
        ]);

        $users = (new JiraService)->getUsers('jsmith@example.com');

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://jira.example.com/rest/api/2/user/search')
                && $request['username'] === 'jsmith@example.com'
                && $request['maxResults'] == 50
                && ! array_key_exists('query', $request->data());
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/groups/picker'));
        $this->assertCount(1, $users);
        $this->assertSame('jsmith', $users[0]['name']);
    }

    public function test_get_users_without_query_lists_unique_members_from_all_groups(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/groups/picker*' => Http::response([
                'groups' => [
                    ['name' => 'jira-software-users'],
                    ['name' => 'jira-servicedesk-users'],
                ],
            ]),
            'jira.example.com/rest/api/2/group/member*' => function (Request $request) {
                $group = $request['groupname'];
                $startAt = (int) $request['startAt'];

                if ($group === 'jira-software-users') {
                    return Http::response([
                        'isLast' => true,
                        'startAt' => $startAt,
                        'total' => 2,
                        'values' => [
                            ['name' => 'jsmith', 'displayName' => 'John Smith'],
                            ['name' => 'alice', 'displayName' => 'Alice'],
                        ],
                    ]);
                }

                if ($startAt === 0) {
                    return Http::response([
                        'isLast' => false,
                        'startAt' => 0,
                        'total' => 3,
                        'values' => [
                            ['name' => 'jsmith', 'displayName' => 'John Smith'],
                            ['name' => 'Frank', 'displayName' => 'Frank'],
                        ],
                    ]);
                }

                return Http::response([
                    'isLast' => true,
                    'startAt' => $startAt,
                    'total' => 3,
                    'values' => [
                        ['name' => 'Mallory', 'displayName' => 'Mallory'],
                    ],
                ]);
            },
        ]);

        $users = (new JiraService)->getUsers();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/user/search'));
        $this->assertEqualsCanonicalizing(
            ['jsmith', 'alice', 'Frank', 'Mallory'],
            array_map(fn (array $user): string => $user['name'], $users)
        );
    }

    public function test_is_valid_key_segment_accepts_uppercase_letters_and_digits(): void
    {
        $this->assertTrue(JiraService::isValidKeySegment('DEMO'));
        $this->assertTrue(JiraService::isValidKeySegment('TEST'));
        $this->assertTrue(JiraService::isValidKeySegment('A1B2'));
    }

    public function test_is_valid_key_segment_rejects_jql_injection_attempts(): void
    {
        $this->assertFalse(JiraService::isValidKeySegment(''));
        $this->assertFalse(JiraService::isValidKeySegment('demo'));
        $this->assertFalse(JiraService::isValidKeySegment('DEMO; DROP TABLE issues'));
        $this->assertFalse(JiraService::isValidKeySegment('DEMO" OR 1=1'));
        $this->assertFalse(JiraService::isValidKeySegment('DEMO ORDER BY'));
        $this->assertFalse(JiraService::isValidKeySegment('PAY S'));
    }

    public function test_connection_timeouts_are_converted_to_the_domain_timeout_exception(): void
    {
        $this->configureJira();

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(JiraTimeoutException::class);

        (new JiraService)->getProjects();
    }

    public function test_get_board_configuration_resolves_data_center_status_ids_and_project(): void
    {
        $this->configureJira();

        // Data Center format: statuses carry only id + self, the board
        // resource has no `location`, so the project comes from the filter.
        Http::fake([
            'jira.example.com/rest/agile/1.0/board/42/configuration*' => Http::response([
                'id' => 42,
                'filter' => ['id' => '55'],
                'columnConfig' => [
                    'columns' => [
                        ['name' => 'Backlog', 'statuses' => [['id' => '10306']]],
                        ['name' => 'Done', 'statuses' => [
                            ['id' => '10400', 'name' => 'Under Triage'],
                            ['id' => '10306'],
                            ['id' => '10301', 'name' => 'Closed'],
                        ]],
                    ],
                ],
            ]),
            'jira.example.com/rest/api/2/status/10306*' => Http::response(['id' => '10306', 'name' => 'Raised']),
            'jira.example.com/rest/agile/1.0/board/42' => Http::response([
                'id' => 42,
                'name' => 'Back-end',
                'type' => 'scrum',
            ]),
            'jira.example.com/rest/api/2/filter/55*' => Http::response([
                'id' => '55',
                'jql' => 'project = OPS AND assignee in (j.doe) ORDER BY key ASC',
            ]),
        ]);

        $config = (new JiraService)->getBoardConfiguration(42);

        $this->assertSame(['Raised', 'Under Triage', 'Closed'], $config['statuses']);
        $this->assertSame('OPS', $config['project_key']);

        // Second call must be served from the cache, not from Jira again.
        (new JiraService)->getBoardConfiguration(42);

        Http::assertSentCount(4);
    }

    public function test_get_board_configuration_uses_location_when_present(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/agile/1.0/board/42/configuration*' => Http::response([
                'id' => 42,
                'filter' => ['id' => '55'],
                'columnConfig' => ['columns' => [
                    ['name' => 'Done', 'statuses' => [['id' => '1', 'name' => 'Raised']]],
                ]],
            ]),
            'jira.example.com/rest/agile/1.0/board/42' => Http::response([
                'id' => 42,
                'location' => ['projectKey' => 'DEMO'],
            ]),
        ]);

        $config = (new JiraService)->getBoardConfiguration(42);

        $this->assertSame(['Raised'], $config['statuses']);
        $this->assertSame('DEMO', $config['project_key']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/filter/'));
    }

    public function test_get_project_board_returns_the_first_board_of_a_project(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/agile/1.0/board?*' => Http::response([
                'values' => [['id' => 54, 'name' => 'Back-end']],
            ]),
        ]);

        $this->assertSame(54, (new JiraService)->getProjectBoard('demo'));

        Http::assertSent(function (Request $request): bool {
            return $request['projectKeyOrId'] === 'DEMO';
        });
    }

    public function test_get_project_board_returns_null_for_invalid_keys(): void
    {
        $this->configureJira();
        Http::fake();

        $this->assertNull((new JiraService)->getProjectBoard('not valid!'));
        Http::assertNothingSent();
    }

    public function test_get_retries_once_after_a_transport_failure_and_succeeds(): void
    {
        $this->configureJira();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('cURL error 28: SSL connection timeout');
            }

            return Http::response([['name' => 'jsmith', 'displayName' => 'John Smith']]);
        });

        $users = (new JiraService)->getUsers('jsmith@example.com');

        $this->assertSame(['jsmith'], array_map(fn (array $u): string => $u['name'], $users));
        $this->assertSame(2, $attempts);
    }

    public function test_get_project_statuses_falls_back_to_instance_statuses_on_older_data_center(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/status/project/OPS*' => Http::response(
                ['message' => 'null for uri', 'status-code' => 404],
                404,
            ),
            'jira.example.com/rest/api/2/status*' => Http::response([
                ['id' => '10306', 'name' => 'Raised', 'statusCategory' => ['key' => 'new']],
                ['id' => '10000', 'name' => 'Done', 'statusCategory' => ['key' => 'done']],
                ['id' => 'x', 'name' => ''],
            ]),
        ]);

        $statuses = (new JiraService)->getProjectStatuses('OPS');

        $this->assertSame([
            ['id' => '10306', 'name' => 'Raised', 'category' => 'new'],
            ['id' => '10000', 'name' => 'Done', 'category' => 'done'],
        ], $statuses);
    }

    public function test_http_errors_are_not_reported_as_timeouts(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/project*' => Http::response(['errorMessages' => ['boom']], 500),
        ]);

        try {
            (new JiraService)->getProjects();
            $this->fail('Expected a RuntimeException for the HTTP failure.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertNotInstanceOf(JiraTimeoutException::class, $e);
        }
    }

    public function test_add_comment_posts_the_body_to_the_data_center_comment_endpoint(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/issue/TS-42/comment' => Http::response(['id' => '10500', 'body' => 'Looks fixed'], 201),
        ]);

        $comment = (new JiraService)->addComment('TS-42', 'Looks fixed');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://jira.example.com/rest/api/2/issue/TS-42/comment'
            && $request->data() === ['body' => 'Looks fixed']);
        $this->assertSame('10500', $comment['id']);
    }

    public function test_add_comment_failure_raises(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/issue/TS-42/comment' => Http::response(['errorMessages' => ['nope']], 403),
        ]);

        $this->expectException(RuntimeException::class);

        (new JiraService)->addComment('TS-42', 'Looks fixed');
    }

    public function test_get_create_field_allowed_options_parses_createmeta(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/issue/createmeta*' => Http::response([
                'projects' => [
                    [
                        'key' => 'TS',
                        'issuetypes' => [
                            [
                                'name' => 'Bug',
                                'fields' => [
                                    'customfield_10200' => [
                                        'allowedValues' => [
                                            ['id' => '1001', 'value' => 'Backend'],
                                            ['id' => '1002', 'value' => 'Frontend'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $options = (new JiraService)->getCreateFieldAllowedOptions('TS', 'customfield_10200');

        $this->assertSame([
            ['id' => '1001', 'value' => 'Backend'],
            ['id' => '1002', 'value' => 'Frontend'],
        ], $options);
    }

    public function test_get_create_field_allowed_options_parses_data_center_createmeta(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/issue/createmeta/TS/issuetypes' => Http::response([
                'values' => [
                    ['id' => '10004', 'name' => 'Bug'],
                    ['id' => '10002', 'name' => 'Task'],
                ],
            ]),
            'jira.example.com/rest/api/2/issue/createmeta/TS/issuetypes/10004*' => Http::response([
                'values' => [
                    [
                        'fieldId' => 'customfield_10700',
                        'name' => 'Ticket Category',
                        'allowedValues' => [
                            ['id' => '10300', 'value' => 'Transaction'],
                            ['id' => '10301', 'value' => 'Settlement'],
                        ],
                    ],
                ],
            ]),
        ]);

        $options = (new JiraService)->getCreateFieldAllowedOptions('TS', 'customfield_10700', 'Bug');

        $this->assertSame([
            ['id' => '10300', 'value' => 'Transaction'],
            ['id' => '10301', 'value' => 'Settlement'],
        ], $options);
    }

    public function test_get_create_field_allowed_options_falls_back_to_editmeta(): void
    {
        $this->configureJira();

        Http::fake([
            'jira.example.com/rest/api/2/issue/createmeta*' => Http::response([], 500),
            'jira.example.com/rest/api/2/search*' => Http::response([
                'issues' => [
                    ['key' => 'TS-10'],
                ],
            ]),
            'jira.example.com/rest/api/2/issue/TS-10/editmeta*' => Http::response([
                'fields' => [
                    'customfield_10200' => [
                        'allowedValues' => [
                            ['id' => '1003', 'value' => 'Infrastructure'],
                        ],
                    ],
                ],
            ]),
        ]);

        $options = (new JiraService)->getCreateFieldAllowedOptions('TS', 'customfield_10200');

        $this->assertSame([
            ['id' => '1003', 'value' => 'Infrastructure'],
        ], $options);
    }

    private function configureJira(): void
    {
        config()->set('services.jira.base_url', 'https://jira.example.com');
        config()->set('services.jira.pat', 'test-pat');
    }
}
