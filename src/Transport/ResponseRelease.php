<?php

declare(strict_types=1);

namespace KloudStack\Observability\Transport;

defined('ABSPATH') || exit;

/**
 * Release the HTTP response to the visitor before doing any further work.
 *
 * This is the single most important class in the plugin for site performance, and the fix for
 * 1.x's worst defect. That version POSTed telemetry synchronously inside its shutdown handler
 * with a 2s connect and 3s total timeout. Under PHP-FPM the response is not flushed to the client
 * until shutdown completes, so every page view on every site could pay up to five seconds of
 * added latency whenever Azure ingestion was slow — for telemetry the visitor does not care
 * about.
 *
 * Three mechanisms, tried in order of reliability. The active one is reported in diagnostics,
 * because on a configuration where only the fallback is available the latency guarantee is
 * weaker and support needs to know that without guessing.
 */
final class ResponseRelease
{
    public const MECHANISM_FASTCGI  = 'fastcgi_finish_request';
    public const MECHANISM_LITESPEED = 'litespeed_finish_request';
    public const MECHANISM_FLUSH    = 'flush';
    public const MECHANISM_NONE     = 'none';

    /** @var bool */
    private $released = false;

    /**
     * Which mechanism is available in this environment.
     *
     * fastcgi_finish_request is present on App Service Linux, which covers the KloudStack fleet
     * and the large majority of Azure WordPress.
     */
    public static function availableMechanism(): string
    {
        if (function_exists('fastcgi_finish_request')) {
            return self::MECHANISM_FASTCGI;
        }

        if (function_exists('litespeed_finish_request')) {
            return self::MECHANISM_LITESPEED;
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return self::MECHANISM_NONE;
        }

        return self::MECHANISM_FLUSH;
    }

    /**
     * Whether the response can be released without the visitor waiting for what follows.
     *
     * The flush fallback is deliberately not counted as a real guarantee: it depends on no
     * output buffering upstream and on the proxy honouring Content-Length, neither of which can
     * be verified from here.
     */
    public static function isReliable(): bool
    {
        $mechanism = self::availableMechanism();

        return $mechanism === self::MECHANISM_FASTCGI || $mechanism === self::MECHANISM_LITESPEED;
    }

    public function hasReleased(): bool
    {
        return $this->released;
    }

    /**
     * Release the response.
     *
     * Safe to call more than once; only the first call does anything.
     *
     * @return string The mechanism used.
     */
    public function release(): string
    {
        if ($this->released) {
            return self::MECHANISM_NONE;
        }

        $this->released = true;
        $mechanism      = self::availableMechanism();

        switch ($mechanism) {
            case self::MECHANISM_FASTCGI:
                fastcgi_finish_request();
                break;

            case self::MECHANISM_LITESPEED:
                litespeed_finish_request();
                break;

            case self::MECHANISM_FLUSH:
                $this->flushResponse();
                break;

            case self::MECHANISM_NONE:
            default:
                // CLI and phpdbg have no response to release.
                break;
        }

        return $mechanism;
    }

    /**
     * Best-effort release for servers without a finish-request function.
     *
     * Sets Content-Length and Connection: close so the client knows the response is complete,
     * then drains every output buffer. This works on mod_php behind most proxies but is not
     * guaranteed, which is why isReliable() returns false for it.
     */
    private function flushResponse(): void
    {
        if (headers_sent()) {
            // Headers already went out, so Content-Length cannot be added. Drain what is buffered
            // and accept that the connection may stay open.
            $this->drainBuffers();

            return;
        }

        $length = 0;

        // ob_get_length() reports only the innermost buffer, so total across the stack.
        $status = ob_get_status(true);

        if (is_array($status)) {
            foreach ($status as $level) {
                $length += (int) ($level['buffer_used'] ?? 0);
            }
        }

        header('Connection: close');
        header('Content-Length: ' . $length);

        $this->drainBuffers();
    }

    private function drainBuffers(): void
    {
        while (ob_get_level() > 0) {
            // Errors are ignored: a buffer installed by another plugin may refuse to close, and
            // failing to flush must never escalate into a visible error.
            if (!@ob_end_flush()) {
                break;
            }
        }

        if (function_exists('flush')) {
            flush();
        }
    }
}
