<?php

return [
    // Local/private instances only. Codex owns OAuth credentials in this private directory.
    'command' => [env('CODEX_BINARY', 'codex')],
    'home' => storage_path('app/private/codex'),
    'login_timeout_seconds' => 600,
    'request_timeout_seconds' => 15,
];
