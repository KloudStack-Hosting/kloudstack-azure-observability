FROM wordpress:php8.3-fpm

# The stock pool is `pm = dynamic` with max_children = 5 and start_servers = 2.
#
# That is far too small to measure this plugin honestly. Telemetry is sent after the response is
# released, but the worker stays busy until the send completes -- so with only a few workers the
# pool saturates and requests queue, and the queueing shows up as "plugin overhead" in every
# scenario including the healthy one. The first run attributed ~29 ms to the plugin that was
# actually a request waiting for a free worker.
#
# A static pool also removes worker spawning, which otherwise fires unpredictably mid-block and
# lands entirely on whichever scenario happened to trigger it.
RUN sed -i \
        -e 's/^pm = dynamic/pm = static/' \
        -e 's/^pm.max_children = .*/pm.max_children = 16/' \
        -e '/^pm.start_servers/d' \
        -e '/^pm.min_spare_servers/d' \
        -e '/^pm.max_spare_servers/d' \
        /usr/local/etc/php-fpm.d/www.conf \
    && grep -E '^pm' /usr/local/etc/php-fpm.d/www.conf
