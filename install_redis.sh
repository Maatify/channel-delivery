#!/usr/bin/env bash

# ============================================================
# scripts/setup/install_redis.sh
#
# Installs and configures Redis for channel-delivery on VPS.
# Supports: Ubuntu 20.04/22.04/24.04 + CentOS/AlmaLinux/Rocky 7/8/9
#
# What this script does:
#   1. Detects OS and installs Redis via the correct package manager
#   2. Configures Redis: password, bind, maxmemory, persistence
#   3. Enables and starts Redis as a systemd service
#   4. Verifies the connection
#
# Usage:
#   sudo bash scripts/setup/install_redis.sh
#   sudo bash scripts/setup/install_redis.sh --password=yourpassword
#   sudo bash scripts/setup/install_redis.sh --port=6379 --password=yourpassword --maxmemory=256mb
#
# After running, add to your .env:
#   REDIS_HOST=127.0.0.1
#   REDIS_PORT=6379
#   REDIS_PASSWORD=yourpassword
# ============================================================

set -euo pipefail

# ── Defaults ──────────────────────────────────────────────────
REDIS_PORT=6379
REDIS_PASSWORD=""
REDIS_MAXMEMORY="256mb"
REDIS_MAXMEMORY_POLICY="allkeys-lru"
REDIS_BIND="127.0.0.1"   # local only — not exposed to internet

# ── Parse args ────────────────────────────────────────────────
for arg in "$@"; do
    case $arg in
        --password=*)  REDIS_PASSWORD="${arg#*=}" ;;
        --port=*)      REDIS_PORT="${arg#*=}"      ;;
        --maxmemory=*) REDIS_MAXMEMORY="${arg#*=}" ;;
    esac
done

# ── Colors ────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ── Must run as root ──────────────────────────────────────────
[[ $EUID -ne 0 ]] && error "Run as root: sudo bash $0"

# ── Detect OS ─────────────────────────────────────────────────
detect_os() {
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        OS_ID="${ID,,}"
        OS_VERSION="${VERSION_ID%%.*}"
    else
        error "Cannot detect OS. /etc/os-release not found."
    fi
}

# ── Install Redis ─────────────────────────────────────────────
install_redis() {
    case "$OS_ID" in
        ubuntu|debian)
            info "Installing Redis on Ubuntu/Debian..."
            apt-get update -qq
            apt-get install -y redis-server
            REDIS_CONFIG="/etc/redis/redis.conf"
            REDIS_SERVICE="redis-server"
            ;;
        centos|rhel|almalinux|rocky)
            info "Installing Redis on CentOS/RHEL/AlmaLinux/Rocky..."
            if [[ "$OS_VERSION" -ge 8 ]]; then
                dnf install -y epel-release
                dnf install -y redis
            else
                yum install -y epel-release
                yum install -y redis
            fi
            REDIS_CONFIG="/etc/redis/redis.conf"
            # CentOS 7 uses /etc/redis.conf
            [[ -f /etc/redis.conf && ! -f /etc/redis/redis.conf ]] && REDIS_CONFIG="/etc/redis.conf"
            REDIS_SERVICE="redis"
            ;;
        *)
            error "Unsupported OS: $OS_ID. Install Redis manually."
            ;;
    esac
}

# ── Configure Redis ───────────────────────────────────────────
configure_redis() {
    info "Configuring Redis at $REDIS_CONFIG ..."

    # Backup original config
    cp "$REDIS_CONFIG" "${REDIS_CONFIG}.bak.$(date +%s)"

    # ── Bind to localhost only (security) ─────────────────────
    sed -i "s/^bind .*/bind ${REDIS_BIND}/" "$REDIS_CONFIG"

    # ── Port ──────────────────────────────────────────────────
    sed -i "s/^port .*/port ${REDIS_PORT}/" "$REDIS_CONFIG"

    # ── Password ──────────────────────────────────────────────
    if [[ -n "$REDIS_PASSWORD" ]]; then
        # Remove existing requirepass lines first
        sed -i '/^requirepass/d' "$REDIS_CONFIG"
        echo "requirepass ${REDIS_PASSWORD}" >> "$REDIS_CONFIG"
        info "Redis password set."
    else
        warn "No password set. Recommended for production: --password=yourpassword"
    fi

    # ── Memory ────────────────────────────────────────────────
    sed -i '/^maxmemory /d'        "$REDIS_CONFIG"
    sed -i '/^maxmemory-policy/d'  "$REDIS_CONFIG"
    echo "maxmemory ${REDIS_MAXMEMORY}"              >> "$REDIS_CONFIG"
    echo "maxmemory-policy ${REDIS_MAXMEMORY_POLICY}" >> "$REDIS_CONFIG"

    # ── Persistence: RDB snapshot every 5 min if 1+ key changed ──
    # Rate limit counters are short-lived — no need for AOF.
    # RDB is enough to survive a Redis restart.
    sed -i '/^save /d' "$REDIS_CONFIG"
    echo "save 300 1"   >> "$REDIS_CONFIG"
    echo "save 60 1000" >> "$REDIS_CONFIG"

    # ── Disable dangerous commands ─────────────────────────────
    echo "rename-command FLUSHALL \"\""  >> "$REDIS_CONFIG"
    echo "rename-command FLUSHDB  \"\""  >> "$REDIS_CONFIG"
    echo "rename-command CONFIG   \"\""  >> "$REDIS_CONFIG"
    echo "rename-command DEBUG    \"\""  >> "$REDIS_CONFIG"

    # ── Log to syslog ─────────────────────────────────────────
    sed -i 's/^loglevel .*/loglevel notice/' "$REDIS_CONFIG"

    info "Redis configuration applied."
}

# ── Enable & start ────────────────────────────────────────────
start_redis() {
    info "Enabling Redis service: $REDIS_SERVICE ..."
    systemctl enable "$REDIS_SERVICE"
    systemctl restart "$REDIS_SERVICE"
    sleep 1

    if systemctl is-active --quiet "$REDIS_SERVICE"; then
        info "Redis is running. ✓"
    else
        error "Redis failed to start. Check: journalctl -u $REDIS_SERVICE -n 30"
    fi
}

# ── Verify connection ─────────────────────────────────────────
verify_redis() {
    info "Verifying Redis connection on port $REDIS_PORT ..."

    if [[ -n "$REDIS_PASSWORD" ]]; then
        PING=$(redis-cli -p "$REDIS_PORT" -a "$REDIS_PASSWORD" --no-auth-warning PING 2>&1)
    else
        PING=$(redis-cli -p "$REDIS_PORT" PING 2>&1)
    fi

    if [[ "$PING" == "PONG" ]]; then
        info "Redis responded: PONG ✓"
    else
        error "Redis not responding. Got: $PING"
    fi
}

# ── Print .env snippet ────────────────────────────────────────
print_env() {
    echo ""
    echo "══════════════════════════════════════════"
    echo "  Add to your .env file:"
    echo "══════════════════════════════════════════"
    echo "REDIS_HOST=127.0.0.1"
    echo "REDIS_PORT=${REDIS_PORT}"
    echo "REDIS_PASSWORD=${REDIS_PASSWORD}"
    echo "REDIS_DB=0"
    echo "RATE_LIMIT_MAX_REQUESTS=100"
    echo "RATE_LIMIT_WINDOW_SECONDS=60"
    echo "══════════════════════════════════════════"
    echo ""
}

# ── Main ──────────────────────────────────────────────────────
info "channel-delivery Redis Setup"
info "=============================="
detect_os
info "Detected OS: $OS_ID $OS_VERSION"
install_redis
configure_redis
start_redis
verify_redis
print_env

info "Done. Redis is ready for channel-delivery."
