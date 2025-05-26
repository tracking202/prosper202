#!/usr/bin/env bash

# Lint all PHP files except those in the vendor directory.
# Works in both Docker and non-Docker environments (like GitHub Actions).
# Exits with non-zero status if any syntax errors are detected.

status=0

# Function to check if Docker container is running
is_docker_running() {
    # Try docker compose (v2) first, then docker-compose (v1)
    if command -v docker &> /dev/null && docker compose version &> /dev/null; then
        # Docker Compose V2
        docker compose ps web 2>/dev/null | grep -q "Up\|running"
        return $?
    elif command -v docker-compose &> /dev/null; then
        # Docker Compose V1
        docker-compose ps web 2>/dev/null | grep -q "Up"
        return $?
    fi
    return 1
}

# Function to get the correct docker compose command
get_docker_compose_cmd() {
    if command -v docker &> /dev/null && docker compose version &> /dev/null; then
        echo "docker compose"
    elif command -v docker-compose &> /dev/null; then
        echo "docker-compose"
    else
        echo ""
    fi
}

# Function to run PHP lint command
run_php_lint() {
    local file="$1"
    
    if is_docker_running; then
        # Run inside Docker container
        # Convert relative path to container path
        docker_path="/var/www/html/${file#./}"
        docker_cmd=$(get_docker_compose_cmd)
        $docker_cmd exec -T web php -l "$docker_path" > /dev/null 2>&1
    else
        # Run directly (for GitHub Actions or local PHP)
        php -l "$file" > /dev/null 2>&1
    fi
    
    return $?
}

# Determine environment for user feedback
if is_docker_running; then
    echo "Running PHP lint in Docker container..."
else
    echo "Running PHP lint locally..."
fi

# Find PHP files excluding vendor directory
file_count=0
error_count=0

while IFS= read -r -d '' file; do
    ((file_count++))
    if ! run_php_lint "$file"; then
        echo "Syntax error detected in $file"
        status=1
        ((error_count++))
    fi
done < <(find . -name '*.php' -not -path './vendor/*' -print0)

# Summary
echo "Checked $file_count PHP files"
if [ $error_count -gt 0 ]; then
    echo "Found $error_count file(s) with syntax errors"
else
    echo "No syntax errors found!"
fi

exit $status
