<?php

namespace App\Services\ClaudeCode;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\ConnectClaudeAccount;
use App\Support\ChildProcessEnvironment;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Claude subscription sign-in through the real `claude auth login` command.
 * The CLI prints a sign-in URL and reads the code the Claude page shows from stdin.
 * Each attempt gets its own config directory; the signed-in one becomes the runtime directory.
 */
class ClaudeAccountService
{
    private const LOGIN_SECONDS = 600;

    public function summary(): array
    {
        $state = $this->state();
        $summary = [
            'available' => ClaudeCodeService::available(),
            'connected' => isset($state['active']),
            'email' => $state['email'] ?? null,
            'login' => $state['login'] ?? null,
        ];
        if (! $summary['available']) {
            $summary['error'] = 'Install Claude Code CLI '.ClaudeCodeService::MINIMUM_VERSION.' or newer on the backend and set CLAUDE_BINARY.';
        }

        return $summary;
    }

    public function connected(): bool
    {
        return isset($this->state()['active']);
    }

    /** The signed-in config directory used by `claude -p`, or null when only a pasted token exists. */
    public function activeDirectory(): ?string
    {
        $active = $this->state()['active'] ?? null;

        return is_string($active) ? $this->directory($active) : null;
    }

    public function startLogin(): array
    {
        if (! ClaudeCodeService::available()) {
            throw self::failure();
        }
        $attempt = null;
        $this->state(function (array $state) use (&$attempt): array {
            if (isset($state['attempt'])) {
                return $state;
            }
            $attempt = (string) Str::uuid();

            return [...$state, 'attempt' => $attempt, 'expiresAt' => time() + self::LOGIN_SECONDS, 'login' => ['status' => 'pending']];
        });
        if ($attempt !== null) {
            try {
                Bus::dispatch(new ConnectClaudeAccount($attempt));
            } catch (Throwable) {
                $this->failLogin($attempt);
                throw SubtitleProcessingException::queuePublicationFailed();
            }
        }

        return $this->summary();
    }

    public function submitCode(string $code): array
    {
        $accepted = false;
        $this->state(function (array $state) use ($code, &$accepted): array {
            if (($state['login']['status'] ?? null) !== 'awaiting_code') {
                return $state;
            }
            $accepted = true;

            return [...$state, 'code' => $code, 'login' => ['status' => 'verifying']];
        });
        if (! $accepted) {
            throw new SubtitleProcessingException('validation_failed', 'Start Claude sign-in again, then paste the new code.', 422);
        }

        return $this->summary();
    }

    public function connect(string $attempt): void
    {
        if (($this->state()['attempt'] ?? null) !== $attempt) {
            return;
        }
        $directory = $this->directory($attempt);
        $input = new InputStream;
        $process = new Process([...(array) config('claude-code.command'), 'auth', 'login', '--claudeai'], $directory, $this->environment($directory), $input, self::LOGIN_SECONDS);
        try {
            $process->start();
            $url = $this->waitFor($attempt, function () use ($process): ?string {
                return preg_match('~https://\S+~', $process->getOutput(), $matches) === 1 ? $matches[0] : null;
            }, $process);
            if (! $this->trustedUrl($url)) {
                throw self::failure();
            }
            $this->update($attempt, fn (array $state): array => [...$state, 'login' => ['status' => 'awaiting_code', 'authUrl' => $url]]);

            $code = $this->waitFor($attempt, fn (): ?string => $this->state()['code'] ?? null, $process);
            $this->update($attempt, function (array $state): array {
                unset($state['code']);

                return $state;
            });
            $input->write($code."\n");
            $input->close();
            $process->wait();

            $status = new Process([...(array) config('claude-code.command'), 'auth', 'status', '--json'], $directory, $this->environment($directory), timeout: 30);
            $status->run();
            $account = json_decode($status->getOutput(), true);
            if (! is_array($account) || ($account['loggedIn'] ?? false) !== true || ($account['authMethod'] ?? null) !== 'claude.ai') {
                throw self::failure();
            }
            $email = is_string($account['email'] ?? null) && strlen($account['email']) <= 320 ? $account['email'] : null;
            $previous = null;
            $this->update($attempt, function (array $state) use ($attempt, $email, &$previous): array {
                $previous = $state['active'] ?? null;

                return ['active' => $attempt, 'email' => $email, 'login' => null];
            });
            if (is_string($previous) && $previous !== $attempt) {
                File::deleteDirectory($this->directory($previous));
            }
        } catch (Throwable) {
            $this->failLogin($attempt);
        } finally {
            $process->stop(0);
        }
    }

    public function failLogin(string $attempt): void
    {
        $this->state(function (array $state) use ($attempt): array {
            if (($state['attempt'] ?? null) !== $attempt) {
                return $state;
            }
            unset($state['attempt'], $state['code'], $state['expiresAt']);

            return [...$state, 'login' => ['status' => 'failed']];
        });
        if (($this->state()['active'] ?? null) !== $attempt) {
            File::deleteDirectory($this->directory($attempt));
        }
    }

    public function disconnect(): array
    {
        $previous = [];
        $this->state(function (array $state) use (&$previous): array {
            $previous = $state;

            return [];
        });
        foreach (array_filter([$previous['active'] ?? null, $previous['attempt'] ?? null]) as $attempt) {
            $directory = $this->directory($attempt);
            try {
                (new Process([...(array) config('claude-code.command'), 'auth', 'logout'], $directory, $this->environment($directory), timeout: 30))->run();
            } catch (Throwable) {
                // Deleting the directory still removes the stored login.
            }
            File::deleteDirectory($directory);
        }

        return $this->summary();
    }

    /** Polls until $read returns a value; stops when the attempt is cancelled, expires or the CLI exits. */
    private function waitFor(string $attempt, Closure $read, Process $process): string
    {
        while (true) {
            $value = $read();
            if (is_string($value) && $value !== '') {
                return $value;
            }
            $state = $this->state();
            if (($state['attempt'] ?? null) !== $attempt || ! $process->isRunning()) {
                throw self::failure();
            }
            usleep(250_000);
        }
    }

    private function trustedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strlen($url) <= 4096
            && preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 1
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ['claude.com', 'claude.ai'], true)
            && ! isset($parts['user'], $parts['pass'], $parts['port']);
    }

    private function environment(string $directory): array
    {
        return ChildProcessEnvironment::isolated($directory.'/tmp', [
            'HOME' => $directory,
            'USERPROFILE' => $directory,
            'CLAUDE_CONFIG_DIR' => $directory,
        ]);
    }

    private function update(string $attempt, Closure $change): void
    {
        $this->state(function (array $state) use ($attempt, $change): array {
            if (($state['attempt'] ?? null) !== $attempt) {
                throw self::failure();
            }

            return $change($state);
        });
    }

    /** A small JSON file under a file lock; the queue worker and HTTP requests share it. */
    private function state(?Closure $update = null): array
    {
        $root = $this->root();
        $handle = @fopen($root.'/account.lock', 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw self::failure();
        }
        try {
            $path = $root.'/account.json';
            $original = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
            $state = $original;
            if (isset($state['attempt']) && ($state['expiresAt'] ?? 0) <= time()) {
                unset($state['attempt'], $state['code'], $state['expiresAt']);
                $state['login'] = ['status' => 'failed'];
            }
            $state = $update ? $update($state) : $state;
            if ($state !== $original) {
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                if (file_put_contents($path.'.tmp', $json) !== strlen($json) || ! rename($path.'.tmp', $path)) {
                    throw self::failure();
                }
                @chmod($path, 0600);
            }

            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function root(): string
    {
        $root = rtrim((string) config('claude-code.config_dir'), '/\\').'/accounts';
        File::ensureDirectoryExists($root, 0700);

        return $root;
    }

    private function directory(string $attempt): string
    {
        if (! Str::isUuid($attempt)) {
            throw self::failure();
        }
        $path = $this->root().'/'.$attempt;
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    private static function failure(): SubtitleProcessingException
    {
        return SubtitleProcessingException::providerUnavailable('Claude sign-in could not finish. Try again.', ['provider' => 'claude']);
    }
}
