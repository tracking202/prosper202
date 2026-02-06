#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
# Prosper202 ClickServer - Zero-Friction Installation Script
# ═══════════════════════════════════════════════════════════════════════════════
#
# Usage: ./install.sh [options]
#
# Options:
#   --quick, -q     Use defaults, minimal prompts
#   --dev, -d       Include development dependencies
#   --ci            Non-interactive mode for CI/CD
#   --docker        Docker-optimized installation
#   --verbose, -v   Show detailed output
#   --help, -h      Show help message
#
# ═══════════════════════════════════════════════════════════════════════════════

# Exit on unhandled errors, but we'll handle most ourselves
set -o pipefail

# Ensure we have a minimum bash version (3.2+ for arrays)
if [ -z "$BASH_VERSION" ]; then
    echo "Error: This script requires bash"
    exit 1
fi

# Cleanup function for graceful exit
cleanup() {
    # Show cursor if hidden
    tput cnorm 2>/dev/null || true
    # Clear any spinner artifacts
    printf "\r%*s\r" "${COLUMNS:-80}" "" 2>/dev/null || true
}

# Trap for clean exit on Ctrl+C and other signals
trap cleanup EXIT INT TERM

# ═══════════════════════════════════════════════════════════════════════════════
# SECTION 1: Colors and UI Helpers
# ═══════════════════════════════════════════════════════════════════════════════

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

# Symbols
CHECK="${GREEN}✓${RESET}"
CROSS="${RED}✗${RESET}"
WARN="${YELLOW}!${RESET}"
INFO="${BLUE}ℹ${RESET}"
ARROW="${CYAN}→${RESET}"

# State tracking
STEP_COUNT=0
TOTAL_STEPS=5
ERRORS=()
WARNINGS=()

# Installation mode flags
INTERACTIVE=true
QUICK_MODE=false
DEV_MODE=false
CI_MODE=false
DOCKER_MODE=false
VERBOSE=false

# Detected environment
DETECTED_OS=""
DETECTED_PHP=""
DETECTED_COMPOSER=""
HAS_DOCKER=false
HAS_DOCKER_COMPOSE=false
IN_DOCKER=false

# Database configuration
DB_HOST="localhost"
DB_NAME="prosper202"
DB_USER="root"
DB_PASS=""
DB_HOST_RO=""
MC_HOST="localhost"

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Temp directory for log files (use TMPDIR if set, otherwise /tmp/claude or /tmp)
TMP_DIR="${TMPDIR:-/tmp/claude}"
mkdir -p "$TMP_DIR" 2>/dev/null || TMP_DIR="/tmp"

# ═══════════════════════════════════════════════════════════════════════════════
# UI Helper Functions
# ═══════════════════════════════════════════════════════════════════════════════

print_banner() {
    echo ""
    echo -e "${CYAN}"
    cat << 'EOF'
  ██████╗ ██████╗  ██████╗ ███████╗██████╗ ███████╗██████╗ ██████╗  ██████╗ ██████╗
  ██╔══██╗██╔══██╗██╔═══██╗██╔════╝██╔══██╗██╔════╝██╔══██╗╚════██╗██╔═████╗╚════██╗
  ██████╔╝██████╔╝██║   ██║███████╗██████╔╝█████╗  ██████╔╝ █████╔╝██║██╔██║ █████╔╝
  ██╔═══╝ ██╔══██╗██║   ██║╚════██║██╔═══╝ ██╔══╝  ██╔══██╗██╔═══╝ ████╔╝██║██╔═══╝
  ██║     ██║  ██║╚██████╔╝███████║██║     ███████╗██║  ██║███████╗╚██████╔╝███████╗
  ╚═╝     ╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚══════╝╚═╝  ╚═╝╚══════╝ ╚═════╝ ╚══════╝
EOF
    echo -e "${RESET}"
    echo -e "                        ${BOLD}ClickServer Installer${RESET}"
    echo ""
}

print_header() {
    local title="$1"
    echo ""
    echo -e "${BOLD}${BLUE}═══════════════════════════════════════════════════════════════${RESET}"
    echo -e "${BOLD}  $title${RESET}"
    echo -e "${BOLD}${BLUE}═══════════════════════════════════════════════════════════════${RESET}"
    echo ""
}

print_subheader() {
    local title="$1"
    echo ""
    echo -e "${BOLD}$title${RESET}"
    echo -e "${DIM}───────────────────────────────────────────${RESET}"
}

print_success() {
    echo -e "  ${CHECK} $1"
}

print_error() {
    echo -e "  ${CROSS} ${RED}$1${RESET}"
    ERRORS+=("$1")
}

print_warning() {
    echo -e "  ${WARN} ${YELLOW}$1${RESET}"
    WARNINGS+=("$1")
}

print_info() {
    echo -e "  ${INFO} $1"
}

print_step() {
    local description="$1"
    local status="$2"
    local extra="$3"

    # Pad description to align status
    local padding_length=$((45 - ${#description}))
    local padding=""
    for ((i=0; i<padding_length; i++)); do
        padding="${padding}."
    done

    if [ "$status" = "ok" ]; then
        echo -e "  [${STEP_COUNT}/${TOTAL_STEPS}] ${description} ${DIM}${padding}${RESET} ${CHECK} ${GREEN}${extra}${RESET}"
    elif [ "$status" = "fail" ]; then
        echo -e "  [${STEP_COUNT}/${TOTAL_STEPS}] ${description} ${DIM}${padding}${RESET} ${CROSS} ${RED}${extra}${RESET}"
    elif [ "$status" = "warn" ]; then
        echo -e "  [${STEP_COUNT}/${TOTAL_STEPS}] ${description} ${DIM}${padding}${RESET} ${WARN} ${YELLOW}${extra}${RESET}"
    elif [ "$status" = "skip" ]; then
        echo -e "  [${STEP_COUNT}/${TOTAL_STEPS}] ${description} ${DIM}${padding}${RESET} ${DIM}Skipped${RESET}"
    else
        echo -e "  [${STEP_COUNT}/${TOTAL_STEPS}] ${description} ${DIM}${padding}${RESET} ${status}"
    fi
}

print_box() {
    local title="$1"
    shift
    local lines=("$@")

    echo ""
    echo -e "${BOLD}┌─────────────────────────────────────────────────────────────────┐${RESET}"
    echo -e "${BOLD}│${RESET}  ${GREEN}${title}${RESET}"
    echo -e "${BOLD}│${RESET}"
    for line in "${lines[@]}"; do
        echo -e "${BOLD}│${RESET}  $line"
    done
    echo -e "${BOLD}└─────────────────────────────────────────────────────────────────┘${RESET}"
    echo ""
}

# Spinner for long operations
spinner() {
    local pid=$1
    local message="$2"
    local spin='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
    local i=0

    # Hide cursor
    tput civis 2>/dev/null || true

    while kill -0 $pid 2>/dev/null; do
        i=$(( (i+1) % 10 ))
        printf "\r  ${CYAN}${spin:$i:1}${RESET} %s" "$message"
        sleep 0.1
    done

    # Show cursor
    tput cnorm 2>/dev/null || true

    # Clear the spinner line
    printf "\r%*s\r" "${COLUMNS:-80}" ""
}

prompt_with_default() {
    local prompt="$1"
    local default="$2"
    local var_name="$3"
    local is_password="${4:-false}"
    local value=""
    local char=""

    if [ "$is_password" = "true" ]; then
        echo -ne "  ${prompt}: "
        while IFS= read -r -s -n1 char; do
            # Check for enter key (empty char)
            if [[ -z "$char" ]]; then
                echo ""
                break
            # Check for backspace
            elif [[ "$char" == $'\x7f' ]] || [[ "$char" == $'\b' ]]; then
                if [[ -n "$value" ]]; then
                    value="${value%?}"
                    echo -ne "\b \b"
                fi
            else
                value+="$char"
                echo -ne "*"
            fi
        done
    else
        echo -ne "  ${prompt} [${DIM}${default}${RESET}]: "
        read -r value
    fi

    # Use default if empty
    value="${value:-$default}"
    printf -v "$var_name" '%s' "$value"
}

prompt_choice() {
    local prompt="$1"
    local default="$2"
    shift 2
    local options=("$@")
    local choice=""

    echo ""
    echo -e "  ${BOLD}${prompt}${RESET}"
    echo ""

    local i=1
    for opt in "${options[@]}"; do
        if [ "$i" = "$default" ]; then
            echo -e "  ${CYAN}[${i}]${RESET} ${opt} ${GREEN}(recommended)${RESET}"
        else
            echo -e "  ${CYAN}[${i}]${RESET} ${opt}"
        fi
        ((i++))
    done

    echo ""
    echo -ne "  Choice [${default}]: "
    read -r choice
    choice="${choice:-$default}"
    echo "$choice"
}

confirm() {
    local prompt="$1"
    local default="${2:-y}"
    local answer=""

    if [ "$default" = "y" ]; then
        echo -ne "  ${prompt} [${GREEN}Y${RESET}/n]: "
    else
        echo -ne "  ${prompt} [y/${GREEN}N${RESET}]: "
    fi

    read -r answer
    answer="${answer:-$default}"

    [[ "$answer" =~ ^[Yy]$ ]]
}

escape_php_single_quote() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf '%s' "$value"
}

escape_sed_replacement() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//&/\\&}"
    value="${value//|/\\|}"
    printf '%s' "$value"
}

sed_in_place() {
    local expr="$1"
    local file="$2"

    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' -e "$expr" "$file"
    else
        sed -i -e "$expr" "$file"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# SECTION 2: Detection Functions
# ═══════════════════════════════════════════════════════════════════════════════

detect_os() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        DETECTED_OS="macOS"
    elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
        if grep -q Microsoft /proc/version 2>/dev/null; then
            DETECTED_OS="WSL"
        elif [ -f /etc/os-release ]; then
            DETECTED_OS=$(grep "^NAME=" /etc/os-release | cut -d'"' -f2)
        else
            DETECTED_OS="Linux"
        fi
    elif [[ "$OSTYPE" == "msys" ]] || [[ "$OSTYPE" == "cygwin" ]]; then
        DETECTED_OS="Windows (Git Bash)"
    else
        DETECTED_OS="Unknown ($OSTYPE)"
    fi
}

detect_php() {
    if command -v php &>/dev/null; then
        DETECTED_PHP=$(php -r "echo PHP_VERSION;" 2>/dev/null)
        return 0
    fi
    return 1
}

check_php_version() {
    if [ -z "$DETECTED_PHP" ]; then
        return 1
    fi

    local major=$(echo "$DETECTED_PHP" | cut -d. -f1)
    local minor=$(echo "$DETECTED_PHP" | cut -d. -f2)

    # Require PHP 8.3+
    if [ "$major" -lt 8 ]; then
        return 1
    elif [ "$major" -eq 8 ] && [ "$minor" -lt 3 ]; then
        return 1
    fi
    return 0
}

check_php_extensions() {
    local required_extensions=("mysqli" "pdo" "curl" "json" "mbstring")
    local missing=()

    for ext in "${required_extensions[@]}"; do
        if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
            missing+=("$ext")
        fi
    done

    if [ ${#missing[@]} -gt 0 ]; then
        echo "${missing[*]}"
        return 1
    fi
    return 0
}

detect_composer() {
    if command -v composer &>/dev/null; then
        DETECTED_COMPOSER=$(composer --version 2>/dev/null | head -1)
        return 0
    elif [ -f "$SCRIPT_DIR/composer.phar" ]; then
        DETECTED_COMPOSER="composer.phar (local)"
        return 0
    fi
    return 1
}

detect_docker() {
    if command -v docker &>/dev/null; then
        if docker info &>/dev/null; then
            HAS_DOCKER=true
        fi
    fi

    if command -v docker-compose &>/dev/null; then
        HAS_DOCKER_COMPOSE=true
    elif command -v docker &>/dev/null && docker compose version &>/dev/null 2>&1; then
        HAS_DOCKER_COMPOSE=true
    fi
}

is_in_docker() {
    if [ -f /.dockerenv ]; then
        IN_DOCKER=true
        return 0
    fi
    if grep -q docker /proc/1/cgroup 2>/dev/null; then
        IN_DOCKER=true
        return 0
    fi
    return 1
}

detect_mysql() {
    # Try to detect common MySQL setups
    if command -v mysql &>/dev/null; then
        return 0
    fi
    return 1
}

check_directory_permissions() {
    local dirs_to_check=("202-config" "tracking202" ".")
    local issues=()

    for dir in "${dirs_to_check[@]}"; do
        if [ -d "$SCRIPT_DIR/$dir" ] && [ ! -w "$SCRIPT_DIR/$dir" ]; then
            issues+=("$dir")
        fi
    done

    if [ ${#issues[@]} -gt 0 ]; then
        echo "${issues[*]}"
        return 1
    fi
    return 0
}

detect_environment_type() {
    # Detect common development environments
    if [ -d "/Applications/MAMP" ] || [ -d "/usr/local/mamp" ]; then
        echo "MAMP"
    elif [ -d "/Applications/XAMPP" ] || [ -d "/opt/lampp" ]; then
        echo "XAMPP"
    elif [ -f "/etc/apache2/apache2.conf" ] || [ -f "/etc/httpd/httpd.conf" ]; then
        echo "Apache"
    elif [ -f "/etc/nginx/nginx.conf" ]; then
        echo "Nginx"
    elif [ "$IN_DOCKER" = true ]; then
        echo "Docker"
    else
        echo "Unknown"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# SECTION 3: Installation Functions
# ═══════════════════════════════════════════════════════════════════════════════

show_extension_install_instructions() {
    local missing="$1"

    case "$DETECTED_OS" in
        macOS)
            print_info "On macOS with Homebrew, extensions are typically included with PHP."
            print_info "If missing, try: brew reinstall php"
            ;;
        Ubuntu*|Debian*)
            local php_version=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
            local packages=""
            for ext in $missing; do
                packages="$packages php${php_version}-${ext}"
            done
            print_info "Install with: sudo apt install$packages"
            ;;
        *Fedora*|*CentOS*|*RHEL*|*Red\ Hat*)
            local packages=""
            for ext in $missing; do
                packages="$packages php-${ext}"
            done
            print_info "Install with: sudo dnf install$packages"
            ;;
        *Arch*)
            print_info "Install with: sudo pacman -S php-mysqli php-pdo"
            ;;
        *)
            print_info "Please install the missing PHP extensions for your system."
            ;;
    esac
}

install_php_extensions() {
    local missing="$1"
    local success=true

    case "$DETECTED_OS" in
        macOS)
            # On macOS with Homebrew, try reinstalling PHP
            if command -v brew &>/dev/null; then
                print_info "Attempting to reinstall PHP via Homebrew..."
                brew reinstall php > "$TMP_DIR/php_install.log" 2>&1 &
                spinner $! "Reinstalling PHP..."
                wait $!
                if [ $? -ne 0 ]; then
                    print_warning "Homebrew reinstall had issues. Check: ${TMP_DIR}/php_install.log"
                    success=false
                fi
            else
                print_warning "Homebrew not found. Please install extensions manually."
                success=false
            fi
            ;;
        Ubuntu*|Debian*)
            local php_version=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
            local packages=""
            for ext in $missing; do
                packages="$packages php${php_version}-${ext}"
            done

            print_info "Installing:$packages"
            echo ""

            # Check if we can use sudo
            if command -v sudo &>/dev/null; then
                sudo apt-get update -qq > "$TMP_DIR/apt_update.log" 2>&1 &
                spinner $! "Updating package lists..."
                wait $!

                sudo apt-get install -y $packages > "$TMP_DIR/php_install.log" 2>&1 &
                spinner $! "Installing PHP extensions..."
                wait $!
                if [ $? -ne 0 ]; then
                    print_warning "Installation had issues. Check: ${TMP_DIR}/php_install.log"
                    success=false
                fi
            else
                print_warning "sudo not available. Please run as root or install manually."
                success=false
            fi
            ;;
        *Fedora*|*CentOS*|*RHEL*|*Red\ Hat*)
            local packages=""
            for ext in $missing; do
                packages="$packages php-${ext}"
            done

            print_info "Installing:$packages"

            if command -v sudo &>/dev/null; then
                sudo dnf install -y $packages > "$TMP_DIR/php_install.log" 2>&1 &
                spinner $! "Installing PHP extensions..."
                wait $!
                if [ $? -ne 0 ]; then
                    print_warning "Installation had issues. Check: ${TMP_DIR}/php_install.log"
                    success=false
                fi
            else
                print_warning "sudo not available. Please run as root or install manually."
                success=false
            fi
            ;;
        *)
            print_warning "Automatic installation not supported for $DETECTED_OS"
            print_info "Please install the extensions manually and re-run this script."
            success=false
            ;;
    esac

    if [ "$success" = true ]; then
        print_success "Extension installation completed"
        return 0
    fi
    return 1
}

install_composer() {
    echo ""
    print_info "Installing Composer..."

    # Check if curl is available
    if ! command -v curl &>/dev/null; then
        print_error "curl is required to install Composer"
        case "$DETECTED_OS" in
            macOS)
                print_info "curl should be pre-installed on macOS. Check your PATH."
                ;;
            Ubuntu*|Debian*)
                print_info "Install with: sudo apt install curl"
                ;;
            *)
                print_info "Please install curl and try again."
                ;;
        esac
        return 1
    fi

    # Download installer to temp file for verification
    local installer_file="${TMP_DIR}/composer-setup.php"

    if ! curl -sS https://getcomposer.org/installer -o "$installer_file" 2>/dev/null; then
        print_error "Failed to download Composer installer"
        return 1
    fi

    # Try global install first (requires sudo)
    if php "$installer_file" --install-dir=/usr/local/bin --filename=composer 2>/dev/null; then
        print_success "Composer installed globally"
        DETECTED_COMPOSER="composer"
        rm -f "$installer_file"
        return 0
    fi

    # Fall back to local install
    if php "$installer_file" --install-dir="$SCRIPT_DIR" 2>/dev/null; then
        print_success "Composer installed locally (composer.phar)"
        DETECTED_COMPOSER="php composer.phar"
        rm -f "$installer_file"
        return 0
    fi

    print_error "Failed to install Composer"
    rm -f "$installer_file"
    return 1
}

get_composer_command() {
    if command -v composer &>/dev/null; then
        echo "composer"
    elif [ -f "$SCRIPT_DIR/composer.phar" ]; then
        echo "php composer.phar"
    else
        echo ""
    fi
}

install_dependencies() {
    local composer_cmd=$(get_composer_command)

    if [ -z "$composer_cmd" ]; then
        print_error "Composer not available"
        return 1
    fi

    cd "$SCRIPT_DIR"

    local composer_args="--no-interaction"

    if [ "$DEV_MODE" = true ]; then
        print_info "Installing with dev dependencies..."
    else
        composer_args="$composer_args --no-dev --optimize-autoloader"
    fi

    # Run composer in background and show spinner
    if [ "$VERBOSE" = true ]; then
        $composer_cmd install $composer_args
        local result=$?
        if [ $result -ne 0 ]; then
            print_error "Composer install failed."
            return 1
        fi
    else
        $composer_cmd install $composer_args > "$TMP_DIR/composer_output.log" 2>&1 &
        local pid=$!
        spinner $pid "Running composer install..."
        wait $pid
        local result=$?

        if [ $result -ne 0 ]; then
            print_error "Composer install failed. See details:"
            cat "$TMP_DIR/composer_output.log"
            return 1
        fi
    fi

    # Count installed packages
    local package_count=$(grep -c '"name"' "$SCRIPT_DIR/vendor/composer/installed.json" 2>/dev/null || echo "0")
    print_success "Dependencies installed (${package_count} packages)"

    return 0
}

test_db_connection() {
    local host="$1"
    local user="$2"
    local pass="$3"
    local name="$4"

    # Escape single quotes in credentials for PHP
    host="${host//\'/\\\'}"
    user="${user//\'/\\\'}"
    pass="${pass//\'/\\\'}"
    name="${name//\'/\\\'}"

    # Build PHP test script with exception handling for PHP 8+
    local test_script="error_reporting(0); mysqli_report(MYSQLI_REPORT_OFF);
try {
    \$conn = @new mysqli('$host', '$user', '$pass', '$name');
    if (\$conn->connect_error) {
        throw new Exception(\$conn->connect_error);
    }
    echo 'OK';
} catch (Exception \$e) {
    try {
        \$conn = @new mysqli('$host', '$user', '$pass');
        if (\$conn->connect_error) {
            throw new Exception(\$conn->connect_error);
        }
        echo 'NO_DB';
    } catch (Exception \$e2) {
        echo 'ERROR:' . \$e2->getMessage();
        exit(1);
    }
}"

    local result=$(php -r "$test_script" 2>/dev/null)

    if [[ "$result" == "OK" ]]; then
        return 0
    elif [[ "$result" == "NO_DB" ]]; then
        return 2  # Connected but database doesn't exist
    else
        echo "${result#ERROR:}"
        return 1
    fi
}

create_database() {
    local host="$1"
    local user="$2"
    local pass="$3"
    local name="$4"

    # Escape single quotes in credentials for PHP
    host="${host//\'/\\\'}"
    user="${user//\'/\\\'}"
    pass="${pass//\'/\\\'}"
    name="${name//\'/\\\'}"

    local create_script="error_reporting(0); mysqli_report(MYSQLI_REPORT_OFF);
try {
    \$conn = @new mysqli('$host', '$user', '$pass');
    if (\$conn->connect_error) {
        throw new Exception(\$conn->connect_error);
    }
    if (\$conn->query('CREATE DATABASE IF NOT EXISTS \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
        echo 'OK';
    } else {
        echo 'ERROR:' . \$conn->error;
        exit(1);
    }
} catch (Exception \$e) {
    echo 'ERROR:' . \$e->getMessage();
    exit(1);
}"

    local result=$(php -r "$create_script" 2>/dev/null)

    if [[ "$result" == "OK" ]]; then
        return 0
    else
        echo "${result#ERROR:}"
        return 1
    fi
}

create_config_file() {
    local config_file="$SCRIPT_DIR/202-config.php"
    local sample_file="$SCRIPT_DIR/202-config-sample.php"
    local db_host_ro_value=""

    if [ ! -f "$sample_file" ]; then
        print_error "Sample config file not found: 202-config-sample.php"
        return 1
    fi

    # Create config from sample with our values
    cp "$sample_file" "$config_file"

    # Escape values for PHP single-quoted strings and sed replacement
    local db_name_php db_user_php db_pass_php db_host_php db_host_ro_php mc_host_php
    local db_name_sed db_user_sed db_pass_sed db_host_sed db_host_ro_sed mc_host_sed
    local dbname_pattern dbuser_pattern dbpass_pattern dbhost_pattern dbhostro_pattern mchost_pattern
    local dbname_replacement dbuser_replacement dbpass_replacement dbhost_replacement dbhostro_replacement mchost_replacement

    db_name_php=$(escape_php_single_quote "$DB_NAME")
    db_user_php=$(escape_php_single_quote "$DB_USER")
    db_pass_php=$(escape_php_single_quote "$DB_PASS")
    db_host_php=$(escape_php_single_quote "$DB_HOST")

    db_host_ro_value="$DB_HOST_RO"
    if [ -z "$db_host_ro_value" ]; then
        db_host_ro_value="$DB_HOST"
    fi
    db_host_ro_php=$(escape_php_single_quote "$db_host_ro_value")
    mc_host_php=$(escape_php_single_quote "$MC_HOST")

    db_name_sed=$(escape_sed_replacement "$db_name_php")
    db_user_sed=$(escape_sed_replacement "$db_user_php")
    db_pass_sed=$(escape_sed_replacement "$db_pass_php")
    db_host_sed=$(escape_sed_replacement "$db_host_php")
    db_host_ro_sed=$(escape_sed_replacement "$db_host_ro_php")
    mc_host_sed=$(escape_sed_replacement "$mc_host_php")

    dbname_pattern="\\\$dbname = 'putyourdbnamehere'"
    dbuser_pattern="\\\$dbuser = 'usernamehere'"
    dbpass_pattern="\\\$dbpass = 'yourpasswordhere'"
    dbhost_pattern="\\\$dbhost = 'localhosthere'"
    dbhostro_pattern="\\\$dbhostro = 'localhostreplica'"
    mchost_pattern="\\\$mchost = 'localhostmemcache'"

    dbname_replacement="\$dbname = '$db_name_sed'"
    dbuser_replacement="\$dbuser = '$db_user_sed'"
    dbpass_replacement="\$dbpass = '$db_pass_sed'"
    dbhost_replacement="\$dbhost = '$db_host_sed'"
    dbhostro_replacement="\$dbhostro = '$db_host_ro_sed'"
    mchost_replacement="\$mchost = '$mc_host_sed'"

    sed_in_place "s|$dbname_pattern|$dbname_replacement|" "$config_file"
    sed_in_place "s|$dbuser_pattern|$dbuser_replacement|" "$config_file"
    sed_in_place "s|$dbpass_pattern|$dbpass_replacement|" "$config_file"
    sed_in_place "s|$dbhost_pattern|$dbhost_replacement|" "$config_file"
    sed_in_place "s|$dbhostro_pattern|$dbhostro_replacement|" "$config_file"
    sed_in_place "s|$mchost_pattern|$mchost_replacement|" "$config_file"

    print_success "Config file created: 202-config.php"
    return 0
}

configure_database_interactive() {
    print_subheader "Database Configuration"
    echo ""

    prompt_with_default "Database host" "$DB_HOST" "DB_HOST"
    prompt_with_default "Database name" "$DB_NAME" "DB_NAME"
    prompt_with_default "Database user" "$DB_USER" "DB_USER"
    prompt_with_default "Database password" "" "DB_PASS" "true"

    echo ""
    echo -ne "  Testing connection... "

    local result
    result=$(test_db_connection "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_NAME")
    local status=$?

    if [ $status -eq 0 ]; then
        echo -e "${CHECK} ${GREEN}Connected!${RESET}"
        return 0
    elif [ $status -eq 2 ]; then
        echo -e "${WARN} ${YELLOW}Connected, but database '${DB_NAME}' doesn't exist${RESET}"
        echo ""
        if confirm "Create database '${DB_NAME}'?" "y"; then
            local create_result
            create_result=$(create_database "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_NAME")
            if [ $? -eq 0 ]; then
                print_success "Database created: ${DB_NAME}"
                return 0
            else
                print_error "Failed to create database: $create_result"
                return 1
            fi
        else
            print_warning "Database not created. You'll need to create it manually."
            return 0
        fi
    else
        echo -e "${CROSS} ${RED}Failed${RESET}"
        print_error "Connection error: $result"
        echo ""

        if confirm "Try again?" "y"; then
            configure_database_interactive
            return $?
        fi
        return 1
    fi
}

start_docker_containers() {
    print_subheader "Starting Docker Containers"
    echo ""

    cd "$SCRIPT_DIR"

    # Determine docker compose command
    local compose_cmd
    if docker compose version &>/dev/null 2>&1; then
        compose_cmd="docker compose"
    else
        compose_cmd="docker-compose"
    fi

    # Build and start containers (build handles pulling base images)
    print_info "Building and starting containers..."
    $compose_cmd up -d --build > "$TMP_DIR/docker_output.log" 2>&1 &
    local pid=$!
    spinner $pid "Building and starting containers..."
    wait $pid
    local build_result=$?
    if [ $build_result -ne 0 ]; then
        print_error "Failed to start containers. Check docker-compose.yaml"
        if [ "$VERBOSE" = true ]; then
            cat "$TMP_DIR/docker_output.log"
        fi
        return 1
    fi

    print_success "Containers started"

    # Wait for database to be ready
    print_info "Waiting for database to be ready..."
    local max_attempts=60
    local attempt=0
    local spin='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'

    # Hide cursor
    tput civis 2>/dev/null || true

    while [ $attempt -lt $max_attempts ]; do
        # Try to connect to MySQL
        if $compose_cmd exec -T db mysqladmin ping -h localhost -u root -proot_password &>/dev/null 2>&1; then
            break
        fi
        ((attempt++))
        local i=$((attempt % 10))
        printf "\r  ${CYAN}${spin:$i:1}${RESET} Waiting for database... (%d/%d)" "$attempt" "$max_attempts"
        sleep 1
    done

    # Show cursor
    tput cnorm 2>/dev/null || true
    printf "\r%*s\r" "${COLUMNS:-80}" ""

    if [ $attempt -ge $max_attempts ]; then
        print_error "Database did not become ready in time"
        print_info "Check container status with: docker compose ps"
        print_info "Check logs with: docker compose logs db"
        return 1
    fi

    print_success "Database is ready"

    # Install dependencies inside container
    print_info "Installing dependencies in container..."
    $compose_cmd exec -T web composer install --no-dev --no-interaction --optimize-autoloader > "$TMP_DIR/docker_output.log" 2>&1 &
    local pid=$!
    spinner $pid "Installing dependencies..."
    wait $pid
    local composer_result=$?

    if [ $composer_result -ne 0 ]; then
        print_warning "Dependency installation had issues. Check container logs."
        if [ "$VERBOSE" = true ]; then
            cat "$TMP_DIR/docker_output.log"
        fi
    else
        print_success "Dependencies installed"
    fi

    # Set Docker-specific database config
    DB_HOST="db"
    DB_NAME="prosper202"
    DB_USER="root"
    DB_PASS="root_password"
    DB_HOST_RO="db"
    MC_HOST="memcached"

    return 0
}

# ═══════════════════════════════════════════════════════════════════════════════
# SECTION 4: Main Flow
# ═══════════════════════════════════════════════════════════════════════════════

parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --quick|-q)
                QUICK_MODE=true
                INTERACTIVE=false
                shift
                ;;
            --dev|-d)
                DEV_MODE=true
                shift
                ;;
            --ci)
                CI_MODE=true
                INTERACTIVE=false
                shift
                ;;
            --docker)
                DOCKER_MODE=true
                shift
                ;;
            --verbose|-v)
                VERBOSE=true
                shift
                ;;
            --help|-h)
                show_help
                exit 0
                ;;
            *)
                print_error "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
}

show_help() {
    echo ""
    echo "Prosper202 ClickServer Installer"
    echo ""
    echo "Usage: ./install.sh [options]"
    echo ""
    echo "Options:"
    echo "  --quick, -q     Use defaults, minimal prompts"
    echo "  --dev, -d       Include development dependencies"
    echo "  --ci            Non-interactive mode for CI/CD"
    echo "  --docker        Docker-optimized installation"
    echo "  --verbose, -v   Show detailed output"
    echo "  --help, -h      Show this help message"
    echo ""
    echo "Examples:"
    echo "  ./install.sh              Interactive installation"
    echo "  ./install.sh --quick      Quick install with defaults"
    echo "  ./install.sh --dev        Install with dev dependencies"
    echo "  ./install.sh --docker     Docker-based installation"
    echo ""
}

run_preflight_checks() {
    print_header "Checking Requirements"

    # Step 1: PHP Version
    STEP_COUNT=1
    if [ "$DOCKER_MODE" = true ] || [ "$IN_DOCKER" = true ]; then
        print_step "PHP Version" "skip" ""
    elif detect_php; then
        if check_php_version; then
            print_step "PHP Version" "ok" "$DETECTED_PHP"
        else
            print_step "PHP Version" "fail" "$DETECTED_PHP (8.3+ required)"
            if [ "$CI_MODE" = true ]; then
                return 1
            fi
        fi
    else
        print_step "PHP Version" "fail" "Not found"
        print_error "PHP is required. Install PHP 8.3+ and try again."

        # Suggest installation based on OS
        case "$DETECTED_OS" in
            macOS)
                print_info "Install with: brew install php@8.3"
                ;;
            Ubuntu*|Debian*)
                print_info "Install with: sudo apt install php8.3-cli php8.3-mysqli php8.3-curl php8.3-mbstring"
                ;;
        esac

        if [ "$CI_MODE" = true ]; then
            return 1
        fi
    fi

    # Step 2: PHP Extensions
    STEP_COUNT=2
    if [ "$DOCKER_MODE" = true ] || [ "$IN_DOCKER" = true ]; then
        print_step "PHP Extensions" "skip" ""
    elif [ -n "$DETECTED_PHP" ]; then
        local missing
        missing=$(check_php_extensions)
        if [ $? -eq 0 ]; then
            print_step "PHP Extensions" "ok" "All found"
        else
            print_step "PHP Extensions" "warn" "Missing: $missing"

            # Offer to install missing extensions
            if [ "$INTERACTIVE" = true ]; then
                echo ""
                show_extension_install_instructions "$missing"
                echo ""
                if confirm "Would you like to try installing them now?" "y"; then
                    if install_php_extensions "$missing"; then
                        # Re-check after installation
                        missing=$(check_php_extensions)
                        if [ $? -eq 0 ]; then
                            print_success "All PHP extensions now installed"
                        else
                            WARNINGS+=("Still missing PHP extensions: $missing")
                        fi
                    else
                        WARNINGS+=("Could not install PHP extensions: $missing")
                    fi
                else
                    WARNINGS+=("Missing PHP extensions: $missing")
                fi
            else
                WARNINGS+=("Missing PHP extensions: $missing")
            fi
        fi
    else
        print_step "PHP Extensions" "skip" ""
    fi

    # Step 3: Composer
    STEP_COUNT=3
    if [ "$DOCKER_MODE" = true ] || [ "$IN_DOCKER" = true ]; then
        print_step "Composer" "skip" ""
    elif detect_composer; then
        print_step "Composer" "ok" "Found"
    else
        print_step "Composer" "warn" "Not found"

        if [ "$INTERACTIVE" = true ]; then
            if confirm "Install Composer now?" "y"; then
                install_composer
            fi
        else
            install_composer
        fi
    fi

    # Step 4: Write Permissions
    STEP_COUNT=4
    local perm_issues
    perm_issues=$(check_directory_permissions)
    if [ $? -eq 0 ]; then
        print_step "Write Permissions" "ok" "OK"
    else
        print_step "Write Permissions" "fail" "Issues: $perm_issues"
        print_error "Cannot write to: $perm_issues"
    fi

    # Step 5: Directory Structure
    STEP_COUNT=5
    if [ -f "$SCRIPT_DIR/202-config-sample.php" ] && [ -d "$SCRIPT_DIR/tracking202" ]; then
        print_step "Directory Structure" "ok" "Valid"
    else
        print_step "Directory Structure" "fail" "Invalid"
        print_error "Not a valid Prosper202 installation directory"
        return 1
    fi

    return 0
}

offer_docker_install() {
    # Check for Docker
    detect_docker

    if [ "$HAS_DOCKER" = true ] && [ "$HAS_DOCKER_COMPOSE" = true ] && [ -f "$SCRIPT_DIR/docker-compose.yaml" ]; then
        echo ""
        print_success "Docker detected and running"
        print_success "docker-compose.yaml found"
        echo ""

        local choice
        choice=$(prompt_choice "How would you like to install?" "1" \
            "Docker - Automatic setup with containers" \
            "Manual - Install on this machine directly")

        if [ "$choice" = "1" ]; then
            return 0  # Use Docker
        fi
    fi

    return 1  # Use manual install
}

show_summary() {
    local install_type="$1"

    local lines=()

    if [ "$install_type" = "docker" ]; then
        lines+=("${ARROW} Application:  ${BOLD}http://localhost:8000${RESET}")
        lines+=("${ARROW} phpMyAdmin:   ${BOLD}http://localhost:8080${RESET}")
        lines+=("")
        lines+=("Default credentials for phpMyAdmin:")
        lines+=("  User: root | Password: root_password")
        lines+=("")
        lines+=("Useful commands:")
        lines+=("  docker compose logs -f    ${DIM}# View logs${RESET}")
        lines+=("  docker compose down       ${DIM}# Stop containers${RESET}")
        lines+=("  docker compose restart    ${DIM}# Restart services${RESET}")
        lines+=("  docker compose ps         ${DIM}# Check status${RESET}")
    else
        lines+=("Next steps:")
        lines+=("  1. Configure your web server to point to:")
        lines+=("     ${BOLD}${SCRIPT_DIR}${RESET}")
        lines+=("")
        lines+=("  2. Open the application in your browser")
        lines+=("     Complete the setup wizard to create your admin account")
        lines+=("")
        if [ -f "$SCRIPT_DIR/202-config.php" ]; then
            lines+=("  ${CHECK} Config file: 202-config.php")
        fi
        lines+=("")
        lines+=("Documentation: ${CYAN}https://github.com/tracking202/prosper202/blob/master/documentation/README.md${RESET}")
    fi

    # Show any warnings
    if [ ${#WARNINGS[@]} -gt 0 ]; then
        lines+=("")
        lines+=("${YELLOW}Warnings:${RESET}")
        for warning in "${WARNINGS[@]}"; do
            lines+=("  ${WARN} ${warning}")
        done
    fi

    print_box "Installation Complete!" "${lines[@]}"
}

show_error_summary() {
    echo ""
    echo -e "${RED}${BOLD}Installation Failed${RESET}"
    echo ""
    echo "The following errors occurred:"
    for error in "${ERRORS[@]}"; do
        echo -e "  ${CROSS} ${error}"
    done
    echo ""
    echo "Please fix these issues and try again."
    echo ""
}

main() {
    # Change to script directory
    cd "$SCRIPT_DIR"

    # Parse command line arguments
    parse_arguments "$@"

    # Detect environment
    detect_os
    is_in_docker

    # Clear screen and show banner (unless in CI mode)
    if [ "$CI_MODE" != true ]; then
        clear 2>/dev/null || true
    fi

    print_banner

    # Show environment info
    if [ "$VERBOSE" = true ]; then
        print_info "Operating System: ${DETECTED_OS}"
        print_info "Script Directory: ${SCRIPT_DIR}"
        if [ "$IN_DOCKER" = true ]; then
            print_info "Running inside Docker container"
        fi
    fi

    # Check if Docker install is preferred (interactive mode only)
    local use_docker=false
    if [ "$DOCKER_MODE" = true ]; then
        use_docker=true
    elif [ "$INTERACTIVE" = true ] && [ "$IN_DOCKER" != true ]; then
        if offer_docker_install; then
            use_docker=true
        fi
    fi

    # Docker installation path
    if [ "$use_docker" = true ]; then
        if start_docker_containers; then
            create_config_file
            show_summary "docker"
            exit 0
        else
            show_error_summary
            exit 1
        fi
    fi

    # Manual installation path

    # Run preflight checks
    if ! run_preflight_checks; then
        if [ "$CI_MODE" = true ]; then
            exit 1
        fi
    fi

    # Install dependencies
    print_header "Installing Dependencies"

    if ! install_dependencies; then
        if [ "$CI_MODE" = true ]; then
            exit 1
        fi

        if [ "$INTERACTIVE" = true ]; then
            if confirm "Retry installation?" "y"; then
                install_dependencies
            fi
        fi
    fi

    # Configure database
    if [ "$CI_MODE" != true ]; then
        print_header "Configuration"

        # Check if config already exists
        if [ -f "$SCRIPT_DIR/202-config.php" ]; then
            print_info "Config file already exists: 202-config.php"
            if [ "$INTERACTIVE" = true ]; then
                if confirm "Overwrite existing config?" "n"; then
                    configure_database_interactive
                    create_config_file
                fi
            fi
        else
            if [ "$QUICK_MODE" = true ]; then
                # Use defaults and try to connect
                print_info "Using default database settings..."
                local result
                result=$(test_db_connection "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_NAME" 2>&1)
                local status=$?

                if [ $status -eq 0 ] || [ $status -eq 2 ]; then
                    if [ $status -eq 2 ]; then
                        create_database "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_NAME"
                    fi
                    create_config_file
                else
                    print_warning "Could not connect with defaults. Manual configuration needed."
                    configure_database_interactive
                    create_config_file
                fi
            else
                configure_database_interactive
                create_config_file
            fi
        fi
    else
        # CI mode - just create config with defaults
        if [ ! -f "$SCRIPT_DIR/202-config.php" ]; then
            create_config_file
        fi
    fi

    # Show summary
    if [ ${#ERRORS[@]} -gt 0 ] && [ "$CI_MODE" = true ]; then
        show_error_summary
        exit 1
    fi

    show_summary "manual"
    exit 0
}

# Run main function with all arguments
main "$@"
