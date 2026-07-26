<?php

/**
 * Renders the inline client script exactly as SnippetInjector emits it, so CI can parse it.
 *
 * A shipped build once concatenated Microsoft's loader snippet -- which ends with the opening of
 * its own invocation, `})({` -- with an invocation that opened again, `({`. The result was
 * `})({ ({`: a syntax error that killed the entire script block. Nothing ran, no browser
 * telemetry was produced, and every server-side check still passed, because the fault existed
 * only in the browser. It took a user pasting a console error to find it.
 *
 * PHP linting cannot catch this; the output is JavaScript. `node --check` on this file's output
 * can, and does so before release rather than after.
 */

declare(strict_types=1);

$snippet = file_get_contents(__DIR__ . '/../src/Client/snippet.js');

$configJson = json_encode([
    'connectionString' => 'InstrumentationKey=11111111-2222-3333-4444-555555555555;IngestionEndpoint=https://australiaeast-1.in.applicationinsights.azure.com/',
    'enableAutoRouteTracking' => true,
    'disableAjaxTracking' => false,
], JSON_UNESCAPED_SLASHES);

$context = json_encode([
    'operationId' => '4bf92f3577b34da6a3ce929d0e0e4736',
    'parentId'    => '00f067aa0ba902b7',
    'role'        => 'example-site',
], JSON_UNESCAPED_SLASHES);

$src = json_encode('https://js.monitor.azure.com/scripts/b/ai.3.gbl.min.js', JSON_UNESCAPED_SLASHES);

// Mirrors SnippetInjector::loaderInvocation()
$invocation = <<<JS
({
    src: {$src},
    crossOrigin: "anonymous",
    cfg: {$configJson},
    onInit: function (sdk) {
        var ctx = window.kloudstackObs || {};
        try {
            sdk.addTelemetryInitializer(function (item) {
                try {
                    if (item && item.tags) {
                        if (ctx.operationId) { item.tags["ai.operation.id"] = ctx.operationId; }
                        if (ctx.parentId) { item.tags["ai.operation.parentId"] = ctx.parentId; }
                        if (ctx.role) { item.tags["ai.cloud.role"] = ctx.role; }
                    }
                } catch (e) {
                    if (window.console && window.console.error) { window.console.error("[KloudStack Observability] initializer:", e); }
                }
                return true;
            });
            ctx.ready = true;
        } catch (e) {
            ctx.error = { stage: "add-initializer", message: e && e.message ? e.message : String(e) };
        }
    }
});
JS;

echo 'window.kloudstackObs = ' . $context . ';' . "\n";
echo $snippet . "\n";
echo $invocation;
