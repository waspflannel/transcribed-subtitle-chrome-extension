<?php

namespace App\Services\Codex;

use App\Exceptions\SubtitleProcessingException;
use Closure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/** One bounded stdio session. Raw process failures never escape. */
class CodexProcess
{
    private Process $process;

    private InputStream $input;

    private string $buffer = '';

    private array $messages = [];

    private int $sequence = 0;

    private int $bytes = 0;

    private float $deadline;

    public function __construct(string $home, int $timeout)
    {
        $this->deadline = microtime(true) + $timeout;
        $command = [...(array) config('codex.command'), 'app-server'];
        foreach (self::configuration() as $key => $value) {
            $command[] = '-c';
            $command[] = $key.'='.json_encode($value, JSON_THROW_ON_ERROR);
        }
        $this->input = new InputStream;
        $environment = array_fill_keys(array_keys($_ENV + $_SERVER + getenv()), false);
        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL', 'SSL_CERT_FILE', 'SSL_CERT_DIR'] as $name) {
            $environment[$name] = getenv($name);
        }
        $this->process = new Process($command, $home, [...$environment,
            'CODEX_HOME' => $home,
            'HOME' => $home,
            'USERPROFILE' => $home,
            'RUST_LOG' => 'off',
        ], $this->input, $timeout);
        try {
            $this->process->start();
            $this->request('initialize', [
                'clientInfo' => ['name' => 'transcribe', 'version' => '1.0.0'],
                'capabilities' => ['experimentalApi' => true],
            ]);
            $this->send(['method' => 'initialized']);
        } catch (Throwable) {
            $this->close();
            throw self::failure();
        }
    }

    public static function available(): bool
    {
        try {
            $process = new Process([...(array) config('codex.command'), '--version'], timeout: 5);
            $process->run();

            return $process->isSuccessful()
                && preg_match('/codex-cli (\d+\.\d+\.\d+)/', $process->getOutput(), $matches) === 1
                && version_compare($matches[1], '0.161.0', '>=');
        } catch (Throwable) {
            return false;
        }
    }

    /** CLI configuration names verified against the 0.161.0 schema. */
    private static function configuration(): array
    {
        return [
            'forced_login_method' => 'chatgpt',
            'cli_auth_credentials_store' => 'file',
            'model_provider' => 'openai',
            'history.persistence' => 'none',
            'analytics.enabled' => false,
            'feedback.enabled' => false,
            'otel.log_user_prompt' => false,
            'otel.exporter' => 'none',
            'otel.trace_exporter' => 'none',
            'otel.metrics_exporter' => 'none',
            'notify' => [],
            'project_doc_max_bytes' => 0,
            'skills.include_instructions' => false,
            'include_environment_context' => false,
            'include_apps_instructions' => false,
            'memories.use_memories' => false,
            'memories.generate_memories' => false,
            'web_search' => 'disabled',
            'features.shell_tool' => false,
            'features.unified_exec' => false,
            'features.apply_patch_freeform' => false,
            'features.apps' => false,
            'features.plugins' => false,
            'features.multi_agent' => false,
            'features.multi_agent_v2' => false,
            'features.js_repl' => false,
            'features.code_mode' => false,
            'features.codex_hooks' => false,
            'features.image_generation' => false,
            'features.request_permissions' => false,
            'features.request_permissions_tool' => false,
            'features.fast_mode' => true,
            'tools.view_image' => false,
        ];
    }

    public function request(string $method, array $params = []): array
    {
        $id = ++$this->sequence;
        $this->send(['id' => $id, 'method' => $method, 'params' => (object) $params]);
        $response = $this->until(fn (array $message): bool => ($message['id'] ?? null) === $id);
        if (isset($response['error'])) {
            throw self::failure($response['error']['data']['codexErrorInfo'] ?? null);
        }
        if (! is_array($response['result'] ?? null)) {
            throw self::failure();
        }

        return $response['result'];
    }

    public function until(Closure $matches, ?Closure $tick = null): array
    {
        $nextTick = 0;
        try {
            while (microtime(true) < $this->deadline) {
                if (microtime(true) >= $nextTick) {
                    $tick?->__invoke();
                    $nextTick = microtime(true) + 0.25;
                }
                foreach ($this->messages as $key => $message) {
                    if (array_key_exists('id', $message) && isset($message['method'])) {
                        // Never approve execution, credential refresh, or interactive requests.
                        $this->send(['id' => $message['id'], 'error' => ['code' => -32601, 'message' => 'Unsupported request.']]);
                        throw self::failure();
                    }
                    if ($matches($message)) {
                        unset($this->messages[$key]);

                        return $message;
                    }
                }
                $running = $this->process->isRunning();
                $chunk = $this->process->getOutput();
                $this->process->clearOutput();
                $this->process->clearErrorOutput();
                $this->bytes += strlen($chunk);
                $this->buffer .= $chunk;
                if ($this->bytes > 32 * 1024 * 1024 || strlen($this->buffer) > 8 * 1024 * 1024) {
                    throw self::failure();
                }
                while (($newline = strpos($this->buffer, "\n")) !== false) {
                    $line = substr($this->buffer, 0, $newline);
                    $this->buffer = substr($this->buffer, $newline + 1);
                    $message = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                    if (! is_array($message)) {
                        throw self::failure();
                    }
                    $this->messages[] = $message;
                }
                if (! $running && $chunk === '') {
                    throw self::failure();
                }
                usleep(10000);
            }
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }

        throw self::failure();
    }

    public function close(): void
    {
        $this->input->close();
        try {
            $this->process->stop(0.2);
        } catch (Throwable) {
            // Process diagnostics may contain private provider payloads.
        }
    }

    private function send(array $message): void
    {
        try {
            $this->input->write(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        } catch (Throwable) {
            throw self::failure();
        }
    }

    public static function failure(mixed $info = null): SubtitleProcessingException
    {
        if ($info === 'usageLimitExceeded') {
            return SubtitleProcessingException::enrichmentFailed('Codex usage limit or credits are exhausted.', ['provider' => 'codex', 'reason' => 'provider_quota_exhausted']);
        }
        $status = null;
        foreach (['httpConnectionFailed', 'responseStreamConnectionFailed', 'responseStreamDisconnected', 'responseTooManyFailedAttempts'] as $key) {
            if (is_array($info) && is_int($info[$key]['httpStatusCode'] ?? null)) {
                $status = $info[$key]['httpStatusCode'];
            }
        }
        if ($info === 'unauthorized' || $status === 401) {
            return new SubtitleProcessingException('provider_not_configured', 'Reconnect Codex in Settings before using Codex AI.', 422, ['provider' => 'codex']);
        }
        if ($status === 429) {
            return SubtitleProcessingException::rateLimited('Codex is temporarily rate limited. Try again later.', ['provider' => 'codex']);
        }
        if (in_array($info, ['contextWindowExceeded', 'badRequest', 'sandboxError'], true)) {
            return SubtitleProcessingException::enrichmentFailed('Codex could not process this subtitle input.', ['provider' => 'codex']);
        }

        return SubtitleProcessingException::providerUnavailable('Codex could not complete the request. Check the connection and try again.', ['provider' => 'codex']);
    }
}
