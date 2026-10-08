<?php

return [
    'usage' => 'Usage: :command',
    'already_linked' => '❌ You are already linked to account ":name" and cannot link another account.',
    'success' => '✅ Your account was linked successfully.'."\n".'Name: :name'."\n".'You can now use the bot.',
    'not_found' => '❌ No Jira user with email ":email" was found.',
    'error' => '❌ Error linking the account.',
    'picker_header' => 'Multiple users were found with email ":email".'."\n".'Select your account:',
    'duplicate' => '❌ More than one active Jira account uses ":email". Ask your Jira admin to deactivate the duplicates.',
    'linked_elsewhere' => '❌ This Jira account is linked to another Telegram account. If it is yours, sign in with a personal access token (PAT) in TechDesk.',
    'token_hint' => 'To act as your own Jira account, sign in with a personal access token (PAT) in TechDesk.',
    'taken_over' => 'ℹ️ The Jira account ":name" was linked to another Telegram account with a personal access token, so it was unlinked from this one.',
    'token_rejected' => '⚠️ Your personal Jira access token no longer works (expired or revoked). Open TechDesk to sign in with a new token or switch to the bot token.',
];
