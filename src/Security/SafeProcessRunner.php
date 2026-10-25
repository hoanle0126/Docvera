<?php

declare(strict_types=1);

namespace Docvera\Security;

use RuntimeException;

/**
 * Executes external system binaries safely using proc_open() with an argument array.
 * Strictly prohibits shell string interpolation, enforces wall-clock timeouts,
 * and isolates child working directories.
 */
final class SafeProcessRunner
{
    private int $defaultTimeoutSeconds;

    public function __construct(int $defaultTimeoutSeconds = 60)
    {
        $this->defaultTimeoutSeconds = max(1, $defaultTimeoutSeconds);
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $env
     */
    public function run(
        array $command,
        ?string $workingDirectory = null,
        ?int $timeoutSeconds = null,
        ?array $env = null
    ): ProcessResult {
        if ($command === []) {
            throw new RuntimeException('Cannot execute an empty command array.');
        }

        // Ensure all elements are strings and contain no null bytes
        $cleanCommand = [];
        foreach ($command as $arg) {
            $str = (string) $arg;
            if (str_contains($str, "\0")) {
                throw new RuntimeException('Invalid null byte detected in process argument.');
            }
            $cleanCommand[] = $str;
        }

        $timeout = $timeoutSeconds !== null ? max(1, $timeoutSeconds) : $this->defaultTimeoutSeconds;
        $cwd = ($workingDirectory !== null && is_dir($workingDirectory)) ? $workingDirectory : sys_get_temp_dir();

        $cleanEnv = $env ?? [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => $cwd,
            'TMPDIR' => $cwd,
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $startTime = microtime(true);
        $process = @proc_open($cleanCommand, $descriptors, $pipes, $cwd, $cleanEnv, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            return new ProcessResult(127, '', 'Failed to spawn process.', false, 0.0);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $maxOutputBytes = 1048576; // 1 MB cap per stream to prevent memory exhaustion
        $timedOut = false;
        $capturedExitCode = null;
        $deadline = $startTime + $timeout;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running'] && $capturedExitCode === null && (int) $status['exitcode'] !== -1) {
                $capturedExitCode = (int) $status['exitcode'];
            }
            $readStreams = [];
            if (!feof($pipes[1])) {
                $readStreams[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $readStreams[] = $pipes[2];
            }

            if ($readStreams === [] && !$status['running']) {
                break;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                $pid = (int) ($status['pid'] ?? 0);
                if ($pid > 0 && function_exists('posix_kill')) {
                    @posix_kill(-$pid, 9);
                }
                @proc_terminate($process, 9);
                break;
            }

            if ($readStreams !== []) {
                $write = null;
                $except = null;
                $sec = 0;
                $usec = (int) min(200000, max(10000, $remaining * 1000000));
                $ready = @stream_select($readStreams, $write, $except, $sec, $usec);
                if ($ready !== false && $ready > 0) {
                    foreach ($readStreams as $stream) {
                        $chunk = fread($stream, 16384);
                        if ($chunk !== false && $chunk !== '') {
                            if ($stream === $pipes[1]) {
                                if (strlen($stdout) < $maxOutputBytes) {
                                    $stdout .= substr($chunk, 0, $maxOutputBytes - strlen($stdout));
                                }
                            } else {
                                if (strlen($stderr) < $maxOutputBytes) {
                                    $stderr .= substr($chunk, 0, $maxOutputBytes - strlen($stderr));
                                }
                            }
                        }
                    }
                }
            } else {
                usleep(20000);
            }
        }

        // Drain any remaining buffered bytes within memory cap
        if (!$timedOut) {
            $remOut = stream_get_contents($pipes[1], max(0, $maxOutputBytes - strlen($stdout)));
            if ($remOut !== false && $remOut !== '') {
                $stdout .= $remOut;
            }
            $remErr = stream_get_contents($pipes[2], max(0, $maxOutputBytes - strlen($stderr)));
            if ($remErr !== false && $remErr !== '') {
                $stderr .= $remErr;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $statusAfter = proc_get_status($process);
        if (!$statusAfter['running'] && $capturedExitCode === null && (int) $statusAfter['exitcode'] !== -1) {
            $capturedExitCode = (int) $statusAfter['exitcode'];
        }
        $closeCode = proc_close($process);
        $exitCode = $capturedExitCode ?? ($closeCode !== -1 ? $closeCode : 0);
        if ($timedOut) {
            $exitCode = 124;
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut, $durationMs);
    }
}

