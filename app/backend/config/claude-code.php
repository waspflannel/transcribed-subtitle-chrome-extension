<?php

return [
    // Local/private instances only. The CLI keeps its own state in this private directory.
    'command' => [env('CLAUDE_BINARY', 'claude')],
    'config_dir' => storage_path('app/private/claude-code'),
    // Set only from Settings (InstanceSettings::apply); never read from the environment.
    'token' => null,
];
