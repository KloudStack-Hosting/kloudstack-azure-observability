#!/usr/bin/env bash
#
# Build the benchmark environment and run the §6.1 latency gate (C4).
#
#   ./run.sh              full run, recorded to results/
#   ./run.sh --quick      2 rounds instead of 8, for iterating on the harness
#   ./run.sh --down       tear everything down
#
set -euo pipefail

cd "$(dirname "$0")"

SLUG="kloudstack-azure-observability"

if [ "${1:-}" = "--down" ]; then
    docker compose down -v
    exit 0
fi

ROUNDS=8
BLOCK=25
if [ "${1:-}" = "--quick" ]; then
    ROUNDS=2
    BLOCK=10
    shift || true
fi

# --- TLS for the ingestion sink --------------------------------------------------------------
# Config only accepts an https ingestion endpoint. The certificate is generated here rather than
# committed, so nothing that looks like a key is ever in the repository.
if [ ! -f sink/certs/sink.crt ]; then
    echo "==> generating self-signed certificate for the ingestion sink"
    mkdir -p sink/certs
    MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes \
        -keyout sink/certs/sink.key -out sink/certs/sink.crt -days 365 \
        -subj "/CN=sink" -addext "subjectAltName=DNS:sink" 2>/dev/null
fi

echo "==> starting containers"
docker compose up -d --build --wait db sink wp web

# The plugin's transport uses cURL directly with peer verification on, so the sink's certificate
# must be in the container's trust store. Filtering it away at the WordPress layer is not an
# option -- wp_remote_post is not involved -- and disabling verification would mean the benchmark
# no longer measured the code that actually ships.
echo "==> trusting the sink certificate inside the WordPress container"
docker compose exec -T wp sh -c '
    cp /certs/sink.crt /usr/local/share/ca-certificates/ksobs-sink.crt &&
    update-ca-certificates >/dev/null 2>&1 &&
    echo "trusted"
'

echo "==> waiting for WordPress to answer"
for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "http://127.0.0.1:8099/" 2>/dev/null; then break; fi
    sleep 2
done

# --- WordPress ---------------------------------------------------------------------------------
if ! docker compose run --rm cli core is-installed >/dev/null 2>&1; then
    echo "==> installing WordPress"
    docker compose run --rm cli core install \
        --url="http://127.0.0.1:8099" \
        --title="Benchmark" \
        --admin_user=bench \
        --admin_password=bench-only-never-exposed \
        --admin_email=bench@example.invalid \
        --skip-email

    # A bare install renders an almost empty page, which under-represents the work a real request
    # does and makes any fixed overhead look proportionally worse than it is.
    echo "==> generating content"
    docker compose run --rm cli post generate --count=40 --post_type=post
    docker compose run --rm cli option update posts_per_page 10
fi

# Written into wp-config.php rather than passed through the environment. See the note in
# docker-compose.yml: PHP-FPM does not pass the container environment to workers, so an
# env-var-based connection string is invisible to the plugin and the whole run measures an
# unconfigured no-op.
echo "==> configuring the plugin's ingestion endpoint"
docker compose run --rm cli config set KLOUDSTACK_OBS_CONNECTION_STRING \
    'InstrumentationKey=00000000-0000-4000-8000-000000000001;IngestionEndpoint=https://sink:8443/' \
    --type=constant

echo "==> installing the plugin under test and the measurement probe"
docker compose run --rm cli eval '
    $src = "/plugin-src";
    $dst = WP_PLUGIN_DIR . "/'"$SLUG"'";
    // Rebuilt from source on every run so the benchmark can never measure a stale copy.
    if (is_dir($dst)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($dst);
    }
    mkdir($dst, 0755, true);
    // Only what the distributed package contains.
    foreach (["kloudstack-azure-observability.php", "readme.txt", "uninstall.php", "LICENSE"] as $file) {
        if (file_exists("$src/$file")) { copy("$src/$file", "$dst/$file"); }
    }
    $copy = function ($from, $to) use (&$copy) {
        @mkdir($to, 0755, true);
        foreach (scandir($from) as $e) {
            if ($e === "." || $e === "..") { continue; }
            is_dir("$from/$e") ? $copy("$from/$e", "$to/$e") : copy("$from/$e", "$to/$e");
        }
    };
    $copy("$src/src", "$dst/src");
    echo "copied plugin\n";
'

docker compose run --rm cli eval '
    $dir = WP_CONTENT_DIR . "/mu-plugins";
    @mkdir($dir, 0755, true);
    copy("/probes/kloudstack-bench-probe.php", "$dir/kloudstack-bench-probe.php");
    echo "installed probe\n";
'

echo "==> environment"
docker compose run --rm cli --info | grep -i "php version" || true
docker compose exec -T wp sh -c 'php -r "echo \"fastcgi_finish_request: \", function_exists(\"fastcgi_finish_request\") ? \"present\" : \"ABSENT\", PHP_EOL;"'
docker compose exec -T wp sh -c 'grep -E "^pm(\.|_)|^pm = " /usr/local/etc/php-fpm.d/*.conf 2>/dev/null | head -5' || true

echo
echo "==> running benchmark (${ROUNDS} rounds x ${BLOCK} requests per scenario)"
python bench.py --rounds "$ROUNDS" --block "$BLOCK" "$@"
