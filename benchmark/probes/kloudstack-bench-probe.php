<?php
/**
 * Plugin Name: KloudStack benchmark probe
 * Description: Measurement support for the §6.1 latency benchmark. Never ships.
 *
 * A must-use plugin, so it is loaded identically in every scenario including the plugin-off
 * baseline. Its own cost therefore cancels out of the comparison instead of being attributed to
 * the plugin under test.
 */

defined('ABSPATH') || exit;

/*
 * No TLS handling here, deliberately.
 *
 * An earlier version filtered http_request_args to skip verification for the sink. It had no
 * effect and cost a debugging session: Transport does not use wp_remote_post at all, it calls
 * cURL directly with CURLOPT_SSL_VERIFYPEER on. Every send failed the handshake, the breaker
 * opened, and the benchmark measured a plugin that was sending nothing while reporting excellent
 * overhead. The sink's certificate is installed into the container trust store by run.sh instead.
 */

/*
 * Record peak memory for the §6.1 "added peak memory ≤ 1 MB" requirement.
 *
 * Registration is deferred until the shutdown action is already running. PHP runs shutdown
 * functions in registration order, and WordPress registers shutdown_action_hook before any
 * plugin loads -- so a function registered from inside it lands at the end of the queue, after
 * the plugin's own collectors have built and flushed their buffer. Registering at file scope
 * would sample memory before the work being measured had happened.
 */
add_action(
    'shutdown',
    static function () {
        register_shutdown_function(
            static function () {
                $tag = isset($_SERVER['HTTP_X_BENCH_TAG'])
                    ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_SERVER['HTTP_X_BENCH_TAG'])
                    : '';

                if ($tag === '') {
                    return;
                }

                // false, not true: real_usage reports memory in 8 MB allocator chunks, which
                // cannot resolve the 1 MB budget being tested -- every scenario came back as
                // exactly 8388608 bytes with a delta of zero.
                @file_put_contents(
                    '/tmp/bench-mem.log',
                    $tag . ' ' . memory_get_peak_usage(false) . "\n",
                    FILE_APPEND
                );
            }
        );
    },
    PHP_INT_MAX
);
