<?php

/**
 * Function signatures for server APIs that PHPStan does not know about.
 *
 * These exist only on specific server software and are always called behind function_exists().
 * Declaring them here lets static analysis check the call sites rather than being suppressed.
 */

declare(strict_types=1);

/**
 * Provided by LiteSpeed's PHP SAPI. Flushes the response and continues execution.
 */
function litespeed_finish_request(): void
{
}
