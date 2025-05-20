#!/usr/bin/env bash

# Lint all PHP files except those in the vendor directory.
# Exits with non-zero status if any syntax errors are detected.

status=0

# Find PHP files excluding vendor directory
while IFS= read -r -d '' file; do
    if ! php -l "$file" > /dev/null; then
        echo "Syntax error detected in $file"
        status=1
    fi
done < <(find . -name '*.php' -not -path './vendor/*' -print0)

exit $status
