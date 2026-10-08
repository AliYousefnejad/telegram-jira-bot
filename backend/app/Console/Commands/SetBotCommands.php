<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class SetBotCommands extends Command
{
    protected $signature = 'telegram:set-commands';

    protected $description = 'Register bot commands with Telegram for "/" suggestions';

    public function handle(): int
    {
        $commands = [
            ['command' => 'start', 'description' => __('commands.start')],
            ['command' => 'menu', 'description' => __('commands.menu')],
            ['command' => 'help', 'description' => __('commands.help')],
            // About command removed — uncomment to restore.
            // ['command' => 'about', 'description' => __('commands.about')],
            ['command' => 'link', 'description' => __('commands.link')],
            // Users section disabled — uncomment to restore.
            // ['command' => 'users', 'description' => __('commands.users')],
            // Projects command removed — uncomment to restore.
            // ['command' => 'projects', 'description' => __('commands.projects')],
            // Tasks flow disabled — uncomment to restore.
            // ['command' => 'tasks', 'description' => __('commands.tasks')],
            // Task details disabled — uncomment to restore.
            // ['command' => 'task', 'description' => __('commands.task')],
            // Assign disabled — uncomment to restore.
            // ['command' => 'assign', 'description' => __('commands.assign')],
            // Unassign disabled — uncomment to restore.
            // ['command' => 'unassign', 'description' => __('commands.unassign')],
            // Create task disabled — uncomment to restore.
            // ['command' => 'create', 'description' => __('commands.create')],
            // Boards command removed — uncomment to restore.
            // ['command' => 'boards', 'description' => __('commands.boards')],
            // Sprints disabled — uncomment to restore.
            // ['command' => 'sprints', 'description' => __('commands.sprints')],
            // Push to sprint disabled — uncomment to restore.
            // ['command' => 'push', 'description' => __('commands.push')],
            // Change status disabled — uncomment to restore.
            // ['command' => 'status', 'description' => __('commands.status')],
            ['command' => 'report', 'description' => __('commands.report')],
            ['command' => 'managetasks', 'description' => __('commands.managetasks')],
            ['command' => 'timeline', 'description' => __('commands.timeline')],
            ['command' => 'allow_topic', 'description' => __('commands.allow_topic')],
            ['command' => 'disallow_topic', 'description' => __('commands.disallow_topic')],
            ['command' => 'list_allowed_topics', 'description' => __('commands.list_allowed_topics')],
            ['command' => 'set_topic_name', 'description' => __('commands.set_topic_name')],
        ];

        try {
            Telegram::setMyCommands([
                'commands' => $commands,
            ]);

            $this->info('Bot commands registered successfully!');
            $this->newLine();

            $table = [];
            foreach ($commands as $cmd) {
                $table[] = ['/'.$cmd['command'], $cmd['description']];
            }

            $this->table(['Command', 'Description'], $table);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to register commands: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
