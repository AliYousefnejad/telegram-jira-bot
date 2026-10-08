# Telegram Jira Support Bot

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)

A self-hosted Telegram bot and Telegram Mini App for support teams that track their work in **Jira Data Center / Server**. File structured incident reports, manage open tasks and follow each issue's timeline without leaving Telegram.

Point it at your own Jira and your own bot token, and it works with your projects, workflow and custom fields. The interface ships in English and Persian.

## Features

- **Reply keyboard UI**: the main menu and every flow step are buttons on the Telegram reply keyboard, with Back and Cancel on every step. In groups the keyboard is shown only to the admin using it.
- **Jira account linking**: each Telegram user links their Jira account (by email or with their own Personal Access Token), so reports and comments are filed under their name.
- **Incident & bug reporting** (`/report`): user bug, admin-panel problem or urgent-requirement reports. User bug reports collect the affected user's details and a category read from your Jira.
- **Task management** (`/managetasks`):
  - Filter open tasks by board status, sorted by severity and then impact.
  - Change status, assignee, priority, impact, severity, owner, category or description, or add a comment.
  - Changes are staged and only written to Jira after you confirm them.
- **Issue timeline** (`/timeline`): the status journey with the time spent in each status, plus assignee and owner changes and comments.
- **Automatic owner assignment** (optional): the Owner field (`L1`, `L2`, `Reporter`, `CTO`) follows workflow status changes, using your own status → owner map.
- **Groups and forum topics**: the bot only works in the groups you allow, and can be limited to specific forum topics.
- **TechDesk Mini App**: the same work as an app inside Telegram — dashboard, status menu, task detail, reports, "send to this chat" with @mentions, Jira profile, Persian/English and light/dark themes.

> Emojis are Telegram-only decoration: issue summaries and descriptions stay emoji-free, so reports read naturally in Jira.

## How It Works

```
Telegram chat ─────▶ Bot (php artisan telegram:poll) ──┐
                                                       ├──▶ Laravel backend ──▶ Jira Data Center / Server (REST API v2)
Telegram Mini App ─▶ HTTPS /api/webapp ────────────────┘           │
  (Vue 3, served from backend/public/app)                          └──▶ Database (linked accounts, cache, state)
```

- The bot uses **long polling**, so it needs no public URL.
- The Mini App is a web page that Telegram opens, so it needs one public **HTTPS** URL. Telegram signs the launch data with your bot token, and the backend checks that signature on every request, so there are no passwords.
- Only Telegram users listed in `TELEGRAM_ADMIN_IDS` can use the bot and the app.

## Repository Layout

| Path | What |
|------|------|
| `backend/` | Laravel 13: the Telegram bot and the Mini App API (`/api/webapp`) |
| `frontend/` | TechDesk, the Telegram Mini App (Vue 3 + Vite); builds into `backend/public/app` |

## Requirements

- PHP 8.3+ with `pdo_mysql` (or `pdo_sqlite`), `bcmath`, `intl`, `zip`
- Composer, and Node.js 20+ for building the Mini App
- MySQL 8 or SQLite
- A Telegram bot token from [@BotFather](https://t.me/BotFather)
- A Jira **Data Center / Server** instance with REST API v2 access (Jira Cloud is not supported)

## Installation

```bash
git clone https://github.com/AliYousefnejad/telegram-jira-bot.git
cd telegram-jira-bot

# the bot
cd backend
composer install
cp .env.example .env
php artisan key:generate
# edit .env: Telegram, Jira and database settings (see Configuration below)
php artisan migrate
php artisan telegram:set-commands     # register the "/" command suggestions
php artisan telegram:poll             # run the bot

# the Mini App (optional)
cd ../frontend
npm ci
npm run build                         # builds into backend/public/app
```

## Configuration

Everything specific to your team lives in `backend/.env`.

### Telegram

| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Bot token from @BotFather |
| `TELEGRAM_ADMIN_IDS` | Comma-separated Telegram user IDs allowed to use the bot (ask [@userinfobot](https://t.me/userinfobot) for yours) |
| `TELEGRAM_ALLOWED_GROUP_IDS` | *Optional.* Comma-separated group IDs the bot works in, e.g. `-1001234567890`. Empty means private chats only |
| `TELEGRAM_WEBAPP_URL` | *Mini App.* HTTPS URL of the app, e.g. `https://support.example.com/app/index.html` |
| `TELEGRAM_WEBAPP_MAX_AGE` | *Mini App.* How long (seconds) Telegram's signed launch data stays valid. Default `86400` |

For groups, turn privacy mode off in @BotFather (`/setprivacy`) or make the bot a group admin, so it receives the reply keyboard taps.

### Jira

| Variable | Description |
|----------|-------------|
| `JIRA_BASE_URL` | Your Jira URL, including the context path if any, e.g. `https://jira.example.com/jira/` |
| `JIRA_PAT` | Personal Access Token (recommended): avatar → Profile → Personal access tokens |
| `JIRA_EMAIL`, `JIRA_API_TOKEN` | Basic auth fallback, used only when `JIRA_PAT` is empty |
| `JIRA_DEFAULT_PROJECT` | Key of your support project, e.g. `SUP`. Empty means the first of `JIRA_PROJECT_KEYS` |
| `JIRA_PROJECT_KEYS` | *Optional.* Comma-separated allowlist of project keys. Empty allows every project the account can see |
| `JIRA_DEFAULT_ISSUE_TYPE` | Issue type for new tasks and reports. Default `Task` |
| `JIRA_STATUS_OWNER_MAP` | *Optional.* JSON map from your workflow's status names to an owner. Empty turns automatic owner assignment off |

Example owner map (status names are matched case-insensitively):

```env
JIRA_STATUS_OWNER_MAP={"OPEN":"L1","IN PROGRESS":"L1","ESCALATED":"L2","WAITING FOR REPORTER":"Reporter","DONE":"Reporter"}
```

### Custom fields (all optional)

Each picker stays hidden while its field does not exist in your Jira.

| Field (select list) | Variable | Options the bot expects |
|---|---|---|
| Severity | `JIRA_SEVERITY_FIELD` | options containing `S1` … `S4`, e.g. "S1 - Critical" |
| Impact | `JIRA_IMPACT_FIELD` | options containing `I1` … `I4`, e.g. "I1 - Extensive" |
| Owner | `JIRA_OWNER_FIELD` | `L1`, `L2`, `Reporter`, `CTO` |
| Category | `JIRA_CATEGORY_FIELD` | anything — the options are read from your Jira |

### Language and dates

| Variable | Description |
|----------|-------------|
| `APP_LOCALE` | `en` or `fa` (Persian) |
| `REPORTS_TIMEZONE` | Timezone of displayed dates. Default `UTC` |
| `REPORTS_CALENDAR` | `gregorian` (default) or `jalali` (Persian calendar) |

## Bot Commands

The main menu buttons start the same flows as these commands.

| Command | Description |
|---------|-------------|
| `/start`, `/menu` | Welcome message and main menu |
| `/help` | Help |
| `/link [EMAIL]` | Link your Jira account |
| `/report` | Report a user bug, an admin-panel problem or an urgent requirement |
| `/managetasks` | Browse and edit open tasks |
| `/timeline [KEY]` | Show an issue's timeline, or pick one by status or key |
| `/cancel` | Cancel the current flow |
| `/allow_topic`, `/disallow_topic`, `/list_allowed_topics`, `/set_topic_name <name>` | Forum topic management (group admins) |

### Artisan commands

| Command | Description |
|---------|-------------|
| `php artisan telegram:poll` | Run the bot (long polling) |
| `php artisan telegram:set-commands` | Register the bot's "/" command suggestions |
| `php artisan telegram:menu-button [URL] [--reset]` | Put the Mini App on the bot's menu button (or restore the command menu) |
| `php artisan telegram:backfill-usernames` | Backfill Telegram usernames for linked Jira accounts |
| `php artisan jira:sync-owners [--jql=] [--limit=100]` | Batch-sync the Owner field from workflow statuses |

## Running in Production

Keep the poller running with a process manager such as Supervisor, and restart it after every deploy (it keeps the old code in memory):

```ini
[program:telegram-jira-bot]
command=php /path/to/telegram-jira-bot/backend/artisan telegram:poll
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/telegram-jira-bot/backend/storage/logs/poller.log
```

To serve the Mini App, point a web server with a valid HTTPS certificate at `backend/public` (a standard Laravel setup), set `APP_URL`, `APP_DOMAIN` and `TELEGRAM_WEBAPP_URL`, then run:

```bash
php artisan telegram:menu-button
```

You can also set the same URL in @BotFather → Bot Settings → Configure Mini App to get a `t.me/<bot>?startapp` link. The Mini App only opens from the private chat with the bot.

### Trying the Mini App locally

```bash
(cd frontend && npm run build)
cd backend
php artisan serve --port=8010
ngrok http 8010                                          # temporary HTTPS URL
php artisan telegram:menu-button https://<ngrok-url>/app/index.html
php artisan telegram:menu-button --reset                 # when you are done
```

Opened in a normal browser (outside Telegram), the Mini App runs on built-in sample data, which is handy for UI work: `cd frontend && npm run dev`.

## Development

```bash
# backend/
php artisan test      # test suite (needs a MySQL database named "telegrambot", see phpunit.xml)
vendor/bin/pint       # code style
composer phpstan      # static analysis

# frontend/
npm run dev           # dev server with sample data
npm run test:e2e      # end-to-end tests (Playwright, uses the installed Chrome)
```

The tests never call the real Jira or Telegram APIs.

## License

[MIT](LICENSE) © Ali Yousefnejad
