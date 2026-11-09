<?php

// A local subprocess fixture: never calls a provider or reads real Claude credentials.
$scenario = $argv[1];
if (in_array('--version', $argv, true)) {
    echo $scenario === 'old-version' ? "2.1.200 (Claude Code)\n" : "2.1.280 (Claude Code)\n";
    exit;
}
if (($argv[2] ?? null) === 'auth') {
    // Mirrors `claude auth login`: print the sign-in URL, then read the pasted code from stdin.
    $credentials = getenv('CLAUDE_CONFIG_DIR').'/.credentials.json';
    switch ($argv[3]) {
        case 'login':
            echo "Opening browser to sign in…\nIf the browser didn't open, visit: ".($scenario === 'untrusted-url' ? 'https://claude.com.evil.test/x' : 'https://claude.com/cai/oauth/authorize?code=true&state=s')."\nPaste code here if prompted > ";
            if (trim((string) fgets(STDIN)) !== 'good-code#s') {
                echo "Invalid code.\n";
                exit(1);
            }
            file_put_contents($credentials, '{"claudeAiOauth":{}}');
            echo "Login successful.\n";
            exit;
        case 'status':
            echo json_encode(is_file($credentials) ? ['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'me@example.com'] : ['loggedIn' => false, 'authMethod' => 'none']);
            exit(is_file($credentials) ? 0 : 1);
        case 'logout':
            @unlink($credentials);
            exit;
    }
}
$stdin = stream_get_contents(STDIN);
$env = [];
foreach (['HOME', 'USERPROFILE', 'CLAUDE_CONFIG_DIR', 'CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CODE_DISABLE_FAST_MODE', 'ANTHROPIC_DEFAULT_OPUS_MODEL', 'ANTHROPIC_DEFAULT_SONNET_MODEL', 'ANTHROPIC_DEFAULT_HAIKU_MODEL', 'ANTHROPIC_API_KEY', 'APP_KEY', 'DB_PASSWORD', 'OPENAI_API_KEY', 'TRANSCRIBE_TEST_SECRET'] as $name) {
    $env[$name] = getenv($name);
}
file_put_contents(getenv('CLAUDE_CONFIG_DIR').'/invocation.json', json_encode([
    'argv' => array_slice($argv, 2),
    'cwd' => getcwd(),
    'stdin' => $stdin,
    'env' => $env,
]));

$error = fn (int $status): string => json_encode(['is_error' => true, 'api_error_status' => $status, 'result' => 'raw-provider-secret', 'terminal_reason' => 'error']);
$success = fn (array $extra): string => json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '', ...$extra]);

switch ($scenario) {
    case 'unauthorized':
        echo $error(401);
        exit(1);
    case 'rate-limited':
        echo $error(429);
        exit(1);
    case 'server-error':
        echo $error(500);
        exit(1);
    case 'list-output':
        echo $success(['structured_output' => ['a']]);
        break;
    case 'missing-output':
        echo $success([]);
        break;
    case 'oversized':
        // Valid success JSON, so only the output byte cap can reject it.
        echo $success(['structured_output' => ['answer' => str_repeat('x', 9 * 1024 * 1024)]]);
        break;
    case 'hang':
        sleep(10);
        break;
    case 'crash':
        fwrite(STDERR, 'raw-provider-secret');
        exit(3);
    default:
        echo $success(['structured_output' => ['answer' => 'ok']]);
}
