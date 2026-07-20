<?php

declare(strict_types=1);

namespace KloudStack\Observability\Collector;

use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Telemetry\Envelope;
use KloudStack\Observability\Telemetry\Reporter;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Uncaught exception and fatal error telemetry.
 *
 * Two mechanisms with different reach. set_exception_handler catches uncaught Throwables and
 * gives a full stack trace; the shutdown handler catches fatals — parse errors, memory
 * exhaustion, calls to undefined functions — which never reach an exception handler at all and
 * are usually the more urgent problem.
 *
 * The previous exception handler is captured at install time and re-invoked afterwards. 1.x did
 * this with a read-clear-double-restore sequence that was hard to reason about and easy to get
 * wrong; capturing the return value of set_exception_handler() is what that sequence was trying
 * to approximate.
 */
final class ExceptionCollector
{
    /**
     * Fatal error types. E_ERROR covers the common case; the rest are compile-time failures that
     * can still occur when a plugin or theme file is edited in place.
     */
    private const FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    /** @var Reporter */
    private $reporter;

    /** @var callable|null */
    private $previousHandler;

    /** @var bool */
    private $registered = false;

    /** @var bool */
    private $reportedFatal = false;

    public function __construct(Reporter $reporter)
    {
        $this->reporter = $reporter;
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        // set_exception_handler returns the handler being replaced. Keeping it is what allows
        // WordPress's own handler (wp_die and friends) to still run after we report.
        $previous = set_exception_handler([$this, 'onException']);

        $this->previousHandler = is_callable($previous) ? $previous : null;

        // Registered after the exception handler so that a fatal raised inside an exception
        // handler is still captured.
        register_shutdown_function(Guard::wrap([$this, 'onShutdown'], 'exception.shutdown'));
    }

    /**
     * Report an uncaught Throwable, then hand back to whoever was handling them before.
     */
    public function onException(Throwable $exception): void
    {
        Guard::run(function () use ($exception): void {
            $this->reporter->trackException(
                get_class($exception),
                $exception->getMessage(),
                self::parseStack($exception),
                Envelope::SEVERITY_CRITICAL,
                [
                    'exception_file' => $exception->getFile(),
                    'exception_line' => (string) $exception->getLine(),
                ]
            );

            // An uncaught exception ends the request, and the shutdown sequence that would
            // normally flush may not run predictably after the previous handler calls exit().
            $this->reporter->flush();
        }, 'exception.handler');

        if ($this->previousHandler !== null) {
            ($this->previousHandler)($exception);

            return;
        }

        // No previous handler. Re-throwing restores PHP's default behaviour, which respects
        // display_errors and WP_DEBUG_DISPLAY. 1.x echoed the message and called exit(255),
        // printing uncaught exception text to visitors regardless of configuration.
        throw $exception;
    }

    /**
     * Capture a fatal error, if one ended this request.
     */
    public function onShutdown(): void
    {
        if ($this->reportedFatal) {
            return;
        }

        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }

        $this->reportedFatal = true;

        $this->reporter->trackException(
            self::fatalTypeName($error['type']),
            (string) $error['message'],
            [[
                'level'    => 0,
                'method'   => 'n/a',
                'assembly' => '',
                'fileName' => (string) $error['file'],
                'line'     => (int) $error['line'],
            ]],
            Envelope::SEVERITY_CRITICAL,
            [
                'exception_file' => (string) $error['file'],
                'exception_line' => (string) $error['line'],
            ]
        );
    }

    /**
     * Convert a Throwable's trace into the parsedStack shape Application Insights expects.
     *
     * Frame arguments are deliberately not included. They routinely contain passwords, API keys
     * and personal data, and a stack trace is the least visible place for that to leak.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function parseStack(Throwable $exception): array
    {
        $frames = [];
        $level  = 0;

        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'level'    => $level,
                'method'   => (string) ($frame['class'] ?? '')
                    . (string) ($frame['type'] ?? '')
                    . (string) ($frame['function'] ?? 'unknown'),
                'assembly' => (string) ($frame['class'] ?? ''),
                'fileName' => (string) ($frame['file'] ?? ''),
                'line'     => (int) ($frame['line'] ?? 0),
            ];

            ++$level;

            // Deep recursion produces enormous traces. The top frames are where the cause is.
            if ($level >= 50) {
                break;
            }
        }

        return $frames;
    }

    private static function fatalTypeName(int $type): string
    {
        switch ($type) {
            case E_PARSE:
                return 'PHP Parse Error';

            case E_CORE_ERROR:
                return 'PHP Core Error';

            case E_COMPILE_ERROR:
                return 'PHP Compile Error';

            case E_USER_ERROR:
                return 'PHP User Error';

            case E_ERROR:
            default:
                return 'PHP Fatal Error';
        }
    }
}
