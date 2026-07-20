<?php
/**
 * Plugin Name: KloudStack Application Insights
 * Plugin URI:  https://kloudstack.com
 * Description: Azure Application Insights for WordPress on Azure App Service.
 *              Injects the JS SDK v3 (client-side page/AJAX/dependency tracking)
 *              and sends server-side Request and Exception telemetry directly to
 *              the ingestion endpoint — no Composer, no dependencies.
 * Version:     1.2.3
 * Author:      KloudStack
 * Author URI:  https://kloudstack.com
 *
 * Env vars read from App Service Application Settings (checked in order):
 *   APPLICATIONINSIGHTS_CONNECTION_STRING  (preferred — full connection string)
 *   APPINSIGHTS_INSTRUMENTATIONKEY         (legacy — instrumentation key only)
 *
 * If neither variable is set the plugin exits silently with no output.
 *
 * @package KloudStack
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

// ─────────────────────────────────────────────────────────────────────────────
// 1. Credential resolution
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse an App Insights connection string into key/value pairs.
 * e.g. "InstrumentationKey=abc;IngestionEndpoint=https://..."
 */
function kloudstack_ai_parse_connection_string(string $conn_str): array {
    $parts = [];
    foreach (explode(';', $conn_str) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        $pos = strpos($segment, '=');
        if ($pos === false) {
            continue;
        }
        $parts[substr($segment, 0, $pos)] = substr($segment, $pos + 1);
    }
    return $parts;
}

/**
 * Returns ['ikey' => '...', 'endpoint' => 'https://.../v2/track', 'conn_str' => '...'] or null.
 * Result is cached in a static variable after first call.
 */
function kloudstack_ai_credentials(): ?array {
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    $conn_str = getenv('APPLICATIONINSIGHTS_CONNECTION_STRING') ?: '';
    $ikey_env = getenv('APPINSIGHTS_INSTRUMENTATIONKEY') ?: '';

    if (!empty($conn_str)) {
        $parsed   = kloudstack_ai_parse_connection_string($conn_str);
        $ikey     = $parsed['InstrumentationKey'] ?? '';
        $base_url = rtrim($parsed['IngestionEndpoint'] ?? 'https://dc.services.visualstudio.com/', '/');
        $endpoint = $base_url . '/v2/track';
        return $cache = ['ikey' => $ikey, 'endpoint' => $endpoint, 'conn_str' => $conn_str];
    }

    if (!empty($ikey_env)) {
        return $cache = [
            'ikey'     => $ikey_env,
            'endpoint' => 'https://dc.services.visualstudio.com/v2/track',
            'conn_str' => '',
        ];
    }

    return $cache = null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Client-side: JS SDK v3 injection
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_head', 'kloudstack_inject_appinsights_snippet', 1);

function kloudstack_inject_appinsights_snippet(): void {
    $creds = kloudstack_ai_credentials();
    if ($creds === null) {
        return;
    }

    // Build the JS SDK cfg object. wp_json_encode() produces valid JSON (all values
    // properly quoted and escaped) — safe for use directly in an inline <script>.
    // Do NOT use esc_js() here: it HTML-encodes & → &amp; which breaks JS strings.
    if (!empty($creds['conn_str'])) {
        $cfg = ['connectionString' => $creds['conn_str']];
    } else {
        $cfg = ['instrumentationKey' => $creds['ikey']];
    }
    $cfg['enableAutoRouteTracking']      = true;
    $cfg['disableAjaxTracking']          = false;
    $cfg['enableCorsCorrelation']        = true;
    $cfg['enableRequestHeaderTracking']  = true;
    $cfg['enableResponseHeaderTracking'] = true;

    $cfg_json = wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // SDK snippet — Azure Application Insights JavaScript SDK v3.
    // https://github.com/microsoft/ApplicationInsights-JS
    ?>
<!-- KloudStack: Azure Application Insights -->
<script type="text/javascript">
!(function (cfg){var k,x,D,E,L,C,b,U,O,A,e,t="track",n="TrackPage",i="TrackEvent",I=[t+"Event",t+"Exception",t+"PageView",t+"PageViewPerformance","addTelemetryInitializer",t+"Trace",t+"DependencyData",t+"Metric","start"+n,"stop"+n,"start"+i,"stop"+i,"setAuthenticatedUserContext","clearAuthenticatedUserContext","flush"];function a(){cfg.onInit&&cfg.onInit(e)}k=window,x=document,D=k.location,E="script",L="ingestionendpoint",C="disableExceptionTracking",b="crossOrigin",U="POST",O=cfg.pn||"aiPolicy",t="appInsightsSDK",A=cfg.name||"appInsights",(cfg.name||k[t])&&(k[t]=A),e=k[A]||function(u){var n=u.url||cfg.src,s=!1,p=!1,l={initialize:!0,queue:[],sv:"10",config:u,version:2,extensions:void 0};function d(e){var t,n,i,a,r,o,c,s;!0!==cfg.dle&&(o=(t=function(){var e,t={},n=u.connectionString;if("string"==typeof n&&n)for(var i=n.split(";"),a=0;a<i.length;a++){var r=i[a].split("=");2===r.length&&(t[r[0].toLowerCase()]=r[1])}return t[L]||(e=(n=t.endpointsuffix)?t.location:null,t[L]="https://"+(e?e+".":"")+"dc."+(n||"services.visualstudio.com")),t}()).instrumentationkey||u.instrumentationKey||"",t=(t=(t=t[L])&&"/"===t.slice(-1)?t.slice(0,-1):t)?t+"/v2/track":u.endpointUrl,t=u.userOverrideEndpointUrl||t,(n=[]).push((i="SDK LOAD Failure: Failed to load Application Insights SDK script (See stack for details)",a=e,c=t,(s=(r=f(o,"Exception")).data).baseType="ExceptionData",s.baseData.exceptions=[{typeName:"SDKLoadFailed",message:i.replace(/\./g,"-"),hasFullStack:!1,stack:i+"\nSnippet failed to load ["+a+"] -- Telemetry is disabled\nHelp Link: https://go.microsoft.com/fwlink/?linkid=2128109\nHost: "+(D&&D.pathname||"_unknown_")+"\nEndpoint: "+c,parsedStack:[]}],r)),n.push((s=e,i=t,(c=(a=f(o,"Message")).data).baseType="MessageData",(r=c.baseData).message='AI (Internal): 99 message:"'+("SDK LOAD Failure: Failed to load Application Insights SDK script (See stack for details) ("+s+")").replace(/\"/g,"")+'"',r.properties={endpoint:i},a)),e=n,o=t,JSON&&((c=k.fetch)&&!cfg.useXhr?c(o,{method:U,body:JSON.stringify(e),mode:"cors"}):XMLHttpRequest&&((s=new XMLHttpRequest).open(U,o),s.setRequestHeader("Content-type","application/json"),s.send(JSON.stringify(e)))))}function f(e,t){return e=e,t=t,i=l.sv,a=l.version,r=D,(o={})["ai.device."+"id"]="browser",o["ai.device.type"]="Browser",o["ai.operation.name"]=r&&r.pathname||"_unknown_",o["ai.internal.sdkVersion"]="javascript:snippet_"+(i||a),{time:(r=new Date).getUTCFullYear()+"-"+n(1+r.getUTCMonth())+"-"+n(r.getUTCDate())+"T"+n(r.getUTCHours())+":"+n(r.getUTCMinutes())+":"+n(r.getUTCSeconds())+"."+(r.getUTCMilliseconds()/1e3).toFixed(3).slice(2,5)+"Z",iKey:e,name:"Microsoft.ApplicationInsights."+e.replace(/-/g,"")+"."+t,sampleRate:100,tags:o,data:{baseData:{ver:2}},ver:undefined,seq:"1",aiDataContract:undefined};function n(e){e=""+e;return 1===e.length?"0"+e:e}var i,a,r,o}var i,a,t,r,g=-1,h=0,m=["js.monitor.azure.com","js.cdn.applicationinsights.io","js.cdn.monitor.azure.com","js0.cdn.applicationinsights.io","js0.cdn.monitor.azure.com","js2.cdn.applicationinsights.io","js2.cdn.monitor.azure.com","az416426.vo.msecnd.net"],o=function(){return c(n,null)};function c(t,r){if((n=navigator)&&(~(n=(n.userAgent||"").toLowerCase()).indexOf("msie")||~n.indexOf("trident/"))&&~t.indexOf("ai.3")&&(t=t.replace(/(\/)(ai\.3\.)([^\d]*)$/,function(e,t,n){return t+"ai.2"+n})),!1!==cfg.cr)for(var e=0;e<m.length;e++)if(0<t.indexOf(m[e])){g=e;break}var n,o=function(e){var a;l.queue=[],p||(0<=g&&h+1<m.length?(a=(g+h+1)%m.length,i(t.replace(/^(.*\/\/)([\w\.]*)(\/.*)$/,function(e,t,n,i){return t+m[a]+i})),h+=1):(s=p=!0,d(t)))},c=function(e,t){p||setTimeout(function(){t&&!l.core&&o()},500),s=!1},i=function(e){var n,i=x.createElement(E),e=(cfg.pl?cfg.ttp&&cfg.ttp.createScript?i.src=cfg.ttp.createScriptURL(e):i.src=(null==(n=window.trustedTypes)?void 0:n.createPolicy(O,{createScriptURL:function(e){try{var t=new URL(e);if(t.host&&"js.monitor.azure.com"===t.host)return e;a(e)}catch(n){a(e)}}})).createScriptURL(e):i.src=e,cfg.nt&&i.setAttribute("nonce",cfg.nt),r&&(i.integrity=r),i.setAttribute("data-ai-name",A),cfg[b]);function a(e){d("AI policy blocked URL: "+e)}return!e&&""!==e||"undefined"==i[b]||(i[b]=e),i.onload=c,i.onerror=o,i.onreadystatechange=function(e,t){"loaded"!==i.readyState&&"complete"!==i.readyState||c(0,t)},cfg.ld&&cfg.ld<0?x.getElementsByTagName("head")[0].appendChild(i):setTimeout(function(){x.getElementsByTagName(E)[0].parentNode.appendChild(i)},cfg.ld||0),i};i(t)}cfg.sri&&(i=n.match(/^((http[s]?:\/\/.*\/)\w+(\.\d+){1,5})\.(([\w]+\.){0,2}js)$/))&&6===i.length?(T="".concat(i[1],".integrity.json"),a="@".concat(i[4]),S=window.fetch,t=function(e){if(!e.ext||!e.ext[a]||!e.ext[a].file)throw Error("Error Loading JSON response");var t=e.ext[a].integrity||null;c(n=i[2]+e.ext[a].file,t)},S&&!cfg.useXhr?S(T,{method:"GET",mode:"cors"}).then(function(e){return e.json()["catch"](function(){return{}})}).then(t)["catch"](o):XMLHttpRequest&&((r=new XMLHttpRequest).open("GET",T),r.onreadystatechange=function(){if(r.readyState===XMLHttpRequest.DONE)if(200===r.status)try{t(JSON.parse(r.responseText))}catch(e){o()}else o()},r.send())):n&&o();try{l.cookie=x.cookie}catch(w){}function e(e){for(;e.length;)!function(t){l[t]=function(){var e=arguments;s||l.queue.push(function(){l[t].apply(l,e)})}}(e.pop())}e(I);var v,y,S=!(l.SeverityLevel={Verbose:0,Information:1,Warning:2,Error:3,Critical:4}),T=(u.extensionConfig||{}).ApplicationInsightsAnalytics||{};return(S=!0!==u[C]&&!0!==T[C]||S)&&(e(["_"+(v="onerror")]),y=k[v],k[v]=function(e,t,n,i,a){var r=y&&y(e,t,n,i,a);return!0!==r&&l["_"+v]({message:e,url:t,lineNumber:n,columnNumber:i,error:a,evt:k.event}),r},u.autoExceptionInstrumented=!0),l}(cfg.cfg),(k[A]=e).queue&&0===e.queue.length?(e.queue.push(a),e.trackPageView({})):a();})({
    src: "https://js.monitor.azure.com/scripts/b/ai.3.gbl.min.js",
    crossOrigin: "anonymous",
    cfg: <?php echo $cfg_json; ?>
});
</script>
<!-- /KloudStack: Azure Application Insights -->
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Server-side telemetry helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Format a duration in milliseconds as the "D.HH:MM:SS.fffffff" string
 * expected by the App Insights RequestData schema.
 */
function kloudstack_ai_format_duration(float $ms): string {
    $total_s  = (int) ($ms / 1000);
    $frac_str = sprintf('%07d', (int) (fmod($ms, 1000) * 10000));
    $days     = intdiv($total_s, 86400);
    $hours    = intdiv($total_s % 86400, 3600);
    $mins     = intdiv($total_s % 3600, 60);
    $secs     = $total_s % 60;
    return sprintf('%d.%02d:%02d:%02d.%s', $days, $hours, $mins, $secs, $frac_str);
}

/**
 * Returns the current UTC timestamp in App Insights format: "2026-03-30T12:00:00.000Z"
 */
function kloudstack_ai_timestamp(): string {
    $t = microtime(true);
    return gmdate('Y-m-d\TH:i:s', (int) $t)
        . '.' . sprintf('%03d', (int) (fmod($t, 1) * 1000))
        . 'Z';
}

/**
 * Builds a complete telemetry envelope ready to POST to the ingestion endpoint.
 *
 * @param string $type_name  Short type name used in the envelope "name" field (e.g. "Request").
 * @param string $base_type  Full baseType string (e.g. "RequestData").
 * @param array  $base_data  The baseData payload (merged with {"ver": 2}).
 */
function kloudstack_ai_envelope(string $type_name, string $base_type, array $base_data): array {
    $creds   = kloudstack_ai_credentials();
    $ikey    = $creds['ikey'] ?? '';
    $ikey_nd = str_replace('-', '', $ikey);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'unknown';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $url    = $scheme . '://' . $host . $uri;
    $path   = parse_url($url, PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    return [
        'name' => "Microsoft.ApplicationInsights.{$ikey_nd}.{$type_name}",
        'time' => kloudstack_ai_timestamp(),
        'iKey' => $ikey,
        'tags' => [
            'ai.internal.sdkVersion' => 'php:kloudstack_1.1.0',
            'ai.operation.name'      => $method . ' ' . $path,
            'ai.cloud.roleInstance'  => gethostname() ?: 'unknown',
        ],
        'data' => [
            'baseType' => $base_type,
            'baseData' => array_merge(['ver' => 2], $base_data),
        ],
    ];
}

/**
 * POSTs one or more telemetry envelopes to the App Insights ingestion endpoint.
 * Uses a short curl timeout so it never blocks the HTTP response.
 * Fails silently — telemetry loss is acceptable; site availability is not.
 */
function kloudstack_ai_post(array $items): void {
    $creds = kloudstack_ai_credentials();
    if ($creds === null || !function_exists('curl_init')) {
        return;
    }

    $payload = json_encode(count($items) === 1 ? $items[0] : $items);
    if ($payload === false) {
        return;
    }

    $ch = curl_init($creds['endpoint']);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    curl_exec($ch);
    curl_close($ch);
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Server-side: Request tracking (fires on every PHP-rendered request)
// ─────────────────────────────────────────────────────────────────────────────

// Register the shutdown handler at file load time so it fires for every request,
// including those that end before WordPress finishes initialising.
register_shutdown_function('kloudstack_ai_shutdown');

function kloudstack_ai_shutdown(): void {
    // Skip WP-CLI and other non-web contexts.
    if (php_sapi_name() === 'cli') {
        return;
    }

    $creds = kloudstack_ai_credentials();
    if ($creds === null) {
        return;
    }

    $items = [];

    // ── 4a. Fatal error telemetry ────────────────────────────────────────────
    $last_error = error_get_last();
    if ($last_error !== null && in_array($last_error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        $items[] = kloudstack_ai_envelope('Exception', 'ExceptionData', [
            'exceptions' => [[
                'id'           => 1,
                'typeName'     => 'PHP Fatal Error',
                'message'      => $last_error['message'],
                'hasFullStack' => true,
                'parsedStack'  => [[
                    'level'    => 0,
                    'method'   => 'n/a',
                    'fileName' => $last_error['file'],
                    'line'     => $last_error['line'],
                ]],
            ]],
            'severityLevel' => 3,
        ]);
    }

    // ── 4b. Request telemetry ────────────────────────────────────────────────
    // Use PHP's own REQUEST_TIME_FLOAT for the most accurate start time.
    $start_float = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $duration_ms = (microtime(true) - $start_float) * 1000;

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'unknown';
    $uri     = $_SERVER['REQUEST_URI'] ?? '/';
    $url     = $scheme . '://' . $host . $uri;
    $path    = parse_url($url, PHP_URL_PATH) ?: '/';
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $status  = http_response_code() ?: 200;

    $items[] = kloudstack_ai_envelope('Request', 'RequestData', [
        'id'           => substr(md5(uniqid('', true)), 0, 16),
        'name'         => $method . ' ' . $path,
        'url'          => $url,
        'duration'     => kloudstack_ai_format_duration($duration_ms),
        'responseCode' => (string) $status,
        'success'      => $status < 400,
        'source'       => $_SERVER['REMOTE_ADDR'] ?? '',
        'properties'   => [
            'wp-is-admin' => (defined('WP_ADMIN') && WP_ADMIN) ? 'true' : 'false',
        ],
    ]);

    kloudstack_ai_post($items);
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Server-side: Exception tracking (uncaught Throwables)
// ─────────────────────────────────────────────────────────────────────────────

// Wrap the existing exception handler so we report to App Insights then let
// WordPress's own handler continue (wp_die, etc.).
set_exception_handler('kloudstack_ai_exception_handler');

function kloudstack_ai_exception_handler(\Throwable $ex): void {
    $creds = kloudstack_ai_credentials();
    if ($creds !== null) {
        $frames = [];
        foreach ($ex->getTrace() as $i => $frame) {
            $frames[] = [
                'level'    => $i,
                'method'   => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
                'assembly' => $frame['class'] ?? '',
                'fileName' => $frame['file'] ?? '',
                'line'     => $frame['line'] ?? 0,
            ];
        }

        $item = kloudstack_ai_envelope('Exception', 'ExceptionData', [
            'exceptions' => [[
                'id'           => 1,
                'typeName'     => get_class($ex),
                'message'      => $ex->getMessage(),
                'hasFullStack' => !empty($frames),
                'parsedStack'  => $frames,
            ]],
            'severityLevel' => 3,
        ]);

        kloudstack_ai_post([$item]);
    }

    // Re-invoke the previous handler if one existed (WordPress's handler).
    // If none existed, replicate the default PHP behaviour.
    $prev = set_exception_handler(null);  // read & clear
    restore_exception_handler();          // restore to previous (now null)
    restore_exception_handler();          // restore to the one before our install

    if (is_callable($prev)) {
        $prev($ex);
    } else {
        // Default PHP behaviour: print message and exit.
        echo 'Fatal error: Uncaught ' . get_class($ex) . ': ' . $ex->getMessage();
        exit(255);
    }
}
