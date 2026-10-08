<?php

$runtime = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($runtime['ok'] ?? false) !== true) {
    fwrite(STDERR, "Backend runtime configuration is invalid.\n");
    exit(1);
}

echo <<<'CONFIG'
[unix_http_server]
file=/tmp/supervisor.sock
[supervisord]
nodaemon=true
logfile=/dev/null
pidfile=/tmp/supervisord.pid
[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface
[supervisorctl]
serverurl=unix:///tmp/supervisor.sock

CONFIG;

$programs = [
    // Several PHP server workers so a slow AI request cannot block polling or the healthcheck.
    // Laravel ignores PHP_CLI_SERVER_WORKERS without --no-reload.
    'backend' => ['env PHP_CLI_SERVER_WORKERS=6 php artisan serve --host=0.0.0.0 --port=8001 --no-reload', 1],
    'scheduler' => ['php artisan schedule:work --no-ansi', 1],
];
foreach ($runtime['summary']['subtitleWorkerGroups'] as $group) {
    $queue = implode(',', $group['queues']);
    $connection = $group['connection'];
    $timeout = (int) $group['timeout_seconds'];
    if (! preg_match('/\A[a-z0-9_,-]+\z/D', $queue) || ! preg_match('/\A[a-z0-9_-]+\z/D', $group['name'])
        || ! preg_match('/\A[a-z0-9_-]+\z/D', $connection)) {
        throw new RuntimeException('Invalid worker configuration.');
    }
    $programs[$group['name']] = [
        "php artisan queue:work {$connection} --queue={$queue} --tries=0 --timeout={$timeout} --sleep=1 --memory=256 --no-ansi",
        max(1, (int) $group['worker_count']),
    ];
}
foreach ($programs as $name => [$command, $count]) {
    echo "\n[program:{$name}]\ncommand={$command}\nnumprocs={$count}\n";
    echo "process_name=%(program_name)s_%(process_num)02d\nautostart=true\nautorestart=true\n";
    echo "stopasgroup=true\nkillasgroup=true\nstopwaitsecs=30\n";
    echo "stdout_logfile=/dev/stdout\nstdout_logfile_maxbytes=0\nstderr_logfile=/dev/stderr\nstderr_logfile_maxbytes=0\n";
}
