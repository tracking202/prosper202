#!/bin/bash
# PHP-FPM healthcheck — verifies the FPM process is responding.
# Used by Docker HEALTHCHECK directive.

SCRIPT_FILENAME=/var/www/html/health/index.php \
REQUEST_METHOD=GET \
cgi-fcgi -bind -connect 127.0.0.1:9000 > /dev/null 2>&1

exit $?
