<?php

// A local subprocess fixture: never calls a provider or reads real Codex credentials.
if (in_array('--version', $argv, true)) {
    echo "codex-cli 0.123.0\n";
    exit;
}
$scenario = $argv[1];
$home = getenv('CODEX_HOME');
$send = function (array $message): void {
    $json = json_encode($message)."\n";
    echo substr($json, 0, 7);
    fflush(STDOUT);
    echo substr($json, 7);
    fflush(STDOUT);
};
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    file_put_contents(dirname($home).'/requests.jsonl', $line, FILE_APPEND);
    $id = $request['id'] ?? null;
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];
    $result = (object) [];
    if ($method === 'initialize') {
        if ($scenario === 'stall') {
            echo '{"partial":';
            fflush(STDOUT);
            sleep(10);
        }
        file_put_contents(dirname($home).'/environment.json', json_encode([
            'HOME' => getenv('HOME'),
            'CODEX_HOME' => getenv('CODEX_HOME'),
            'OPENAI_BASE_URL' => getenv('OPENAI_BASE_URL'),
            'TRANSCRIBE_TEST_SECRET' => getenv('TRANSCRIBE_TEST_SECRET'),
            'TRANSCRIBE_TEST_ENV_SECRET' => getenv('TRANSCRIBE_TEST_ENV_SECRET'),
            'OPENAI_API_KEY' => getenv('OPENAI_API_KEY'),
            'CODEX_ACCESS_TOKEN' => getenv('CODEX_ACCESS_TOKEN'),
        ]));
        $result = ['userAgent' => 'fake'];
    } elseif ($method === 'account/login/start') {
        $send(['id' => $id, 'result' => ['loginId' => 'login-1', 'verificationUrl' => 'https://auth.openai.com/codex/device', 'userCode' => 'ABCD-1234']]);
        if ($scenario === 'cancel') {
            file_put_contents(dirname($home).'/connection.json', '{}');
        }
        if ($scenario !== 'login-failure') {
            file_put_contents($home.'/authenticated', 'fixture-account');
        }
        $send(['method' => 'account/login/completed', 'params' => ['loginId' => 'login-1', 'success' => $scenario !== 'login-failure', 'error' => 'secret-provider-error']]);

        continue;
    } elseif ($method === 'account/read') {
        $result = ['account' => is_file($home.'/authenticated') ? ['type' => $scenario === 'api-key' ? 'apiKey' : 'chatgpt', 'email' => 'not-returned@example.invalid'] : null];
    } elseif ($method === 'model/list') {
        $result = ($params['cursor'] ?? null) === null
            ? ['data' => [['id' => 'preset-fast', 'model' => 'gpt-test', 'displayName' => 'Test Model', 'hidden' => false, 'additionalSpeedTiers' => ['fast']]], 'nextCursor' => 'page-2']
            : ['data' => [['id' => 'preset-slow', 'model' => 'gpt-slow', 'displayName' => 'Slow Model', 'hidden' => false, 'additionalSpeedTiers' => []]], 'nextCursor' => null];
    } elseif ($method === 'account/logout') {
        @unlink($home.'/authenticated');
    } elseif ($method === 'thread/start') {
        if ($scenario === 'disconnect-before-turn') {
            file_put_contents(dirname($home).'/connection.json', '{}');
        }
        $result = ['thread' => ['id' => 'thread-1']];
    } elseif ($method === 'config/read') {
        $result = ['config' => ['mcp_servers' => ['unsafe' => ['command' => 'do-not-run']]]];
    } elseif ($method === 'turn/start') {
        if ($scenario === 'process-failure') {
            fwrite(STDERR, 'secret-provider-error prompt-private');
            $send(['id' => $id, 'error' => ['message' => 'secret-provider-error prompt-private']]);

            continue;
        }
        $send(['id' => $id, 'result' => ['turn' => ['id' => 'turn-1']]]);
        if ($scenario === 'tool-request') {
            $send(['id' => 'server-request', 'method' => 'item/commandExecution/requestApproval', 'params' => ['command' => 'secret-provider-error']]);

            continue;
        }
        $send(['method' => 'item/completed', 'params' => ['threadId' => 'thread-1', 'turnId' => 'turn-1', 'item' => [
            'type' => 'agentMessage', 'text' => $scenario === 'invalid-json' ? 'plain text' : '{"answer":"ok"}',
        ]]]);
        $info = match ($scenario) {
            'quota' => 'usageLimitExceeded',
            'unauthorized' => 'unauthorized',
            'rate-limit' => ['httpConnectionFailed' => ['httpStatusCode' => 429]],
            default => null,
        };
        $send(['method' => 'turn/completed', 'params' => ['threadId' => 'thread-1', 'turn' => [
            'id' => 'turn-1',
            'status' => $scenario === 'turn-failure' || $info !== null ? 'failed' : 'completed',
            'error' => ['codexErrorInfo' => $info, 'message' => 'secret-provider-error'],
        ]]]);

        continue;
    } else {
        if ($id === null) {
            continue;
        }
    }
    if ($id !== null) {
        $send(['id' => $id, 'result' => $result]);
    }
}
