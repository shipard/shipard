#!/usr/bin/env bash
# install-packages.sh — installs system dependencies for Shipard on Ubuntu LTS (24.04 / 26.04)
#
# Idempotent. Sets up /opt/shipard and /etc/shipard with correct ownership,
# generates a dedicated shipard PHP-FPM pool, and activates the nginx site.
#
# Usage:
#   sudo bash scripts/install-packages.sh --mode=development
#   sudo bash scripts/install-packages.sh --mode=production
#
# In development mode the shipard user is the developer (taken from $SUDO_USER).
# In production mode a dedicated 'shipard' system user is created.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# ─── 1. Parse arguments ──────────────────────────────────────────────────────
MODE=""
FORCE_NGINX=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode=*) MODE="${1#*=}" ;;
        --mode)   MODE="${2:-}"; shift ;;
        --force-nginx) FORCE_NGINX=1 ;;
        -h|--help)
            cat <<EOF
Usage: sudo bash scripts/install-packages.sh --mode=<development|production> [options]

Options:
  --force-nginx   Regenerate nginx site file even if it exists
EOF
            exit 0
            ;;
        *)
            echo "Error: unknown option '$1'" >&2
            exit 1
            ;;
    esac
    shift
done

if [ -z "$MODE" ]; then
    echo "Select mode:"
    echo "  1) development (single developer; shipard user = current dev)"
    echo "  2) production  (dedicated system 'shipard' user)"
    read -rp "Mode [1/2]: " choice
    case "$choice" in
        1) MODE="development" ;;
        2) MODE="production" ;;
        *) echo "Error: invalid choice" >&2; exit 1 ;;
    esac
fi

if [ "$MODE" != "development" ] && [ "$MODE" != "production" ]; then
    echo "Error: --mode must be 'development' or 'production' (got '$MODE')" >&2
    exit 1
fi

# ─── 2. Require root ─────────────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: this script must be run as root (use sudo bash scripts/install-packages.sh --mode=$MODE)" >&2
    exit 1
fi

# ─── 3. Detect Ubuntu version ────────────────────────────────────────────────
# Supported: Ubuntu 24.04 LTS (PHP 8.5 from the ondrej/php PPA) and 26.04 LTS
# (PHP 8.5 from the standard repositories). Checked before anything is changed.
SUPPORTED_OS="Ubuntu 24.04 LTS and 26.04 LTS"
if [ ! -r /etc/os-release ]; then
    echo "Error: cannot detect the OS version (/etc/os-release is not readable)." >&2
    echo "       Supported: $SUPPORTED_OS." >&2
    exit 1
fi
# Read in a subshell so the os-release variables do not leak into this script.
OS_ID="$(. /etc/os-release && echo "${ID:-}")"
OS_VERSION_ID="$(. /etc/os-release && echo "${VERSION_ID:-}")"

case "$OS_ID:$OS_VERSION_ID" in
    ubuntu:24.04) USE_PHP_PPA=1 ;;
    ubuntu:26.04) USE_PHP_PPA=0 ;;
    *)
        echo "Error: unsupported OS '${OS_ID:-unknown} ${OS_VERSION_ID:-unknown}'." >&2
        echo "       Supported: $SUPPORTED_OS." >&2
        exit 1
        ;;
esac

# ─── 4. Determine shipard user ───────────────────────────────────────────────
if [ "$MODE" = "production" ]; then
    SHIPARD_USER="shipard"
    if ! id "$SHIPARD_USER" >/dev/null 2>&1; then
        echo "==> Creating system user '$SHIPARD_USER'..."
        useradd --system --shell /usr/sbin/nologin --home-dir /opt/shipard "$SHIPARD_USER"
    fi
else
    SHIPARD_USER="${SUDO_USER:-$(logname 2>/dev/null || true)}"
    if [ -z "$SHIPARD_USER" ] || [ "$SHIPARD_USER" = "root" ]; then
        echo "Error: cannot determine developer user. Run via sudo from your user account," >&2
        echo "       or set SUDO_USER explicitly." >&2
        exit 1
    fi
    if ! id "$SHIPARD_USER" >/dev/null 2>&1; then
        echo "Error: user '$SHIPARD_USER' does not exist." >&2
        exit 1
    fi
fi

echo "==> Mode:         $MODE"
echo "==> OS:           $OS_ID $OS_VERSION_ID"
echo "==> Shipard user: $SHIPARD_USER"
echo ""

# ─── 5. apt packages ─────────────────────────────────────────────────────────
# Fresh images (cloud, WSL) ship stale package lists — refresh them before the
# first install, otherwise it fails on 404.
echo "==> Updating package lists..."
apt-get update

echo "==> Installing prerequisites..."
apt-get install -y ca-certificates curl

if [ "$USE_PHP_PPA" = "1" ]; then
    echo "==> Adding PHP PPA (ondrej/php)..."
    apt-get install -y software-properties-common
    add-apt-repository --yes ppa:ondrej/php
    apt-get update
else
    echo "==> PHP 8.5 comes from the standard Ubuntu repositories, no PPA needed."
fi

echo "==> Installing PHP 8.5, MariaDB, nginx, composer and tools..."
apt-get install -y \
    php8.5-cli php8.5-fpm \
    php8.5-mysql php8.5-xml php8.5-mbstring php8.5-curl php8.5-zip php8.5-intl \
    poppler-utils librsvg2-bin libvips-tools \
    podman \
    mariadb-server nginx composer git unzip

# No pipeline in the substitution: with `set -euo pipefail` a missing `node`
# would abort the script silently with exit 127.
NODE_VERSION=""
NODE_MAJOR=""
if command -v node >/dev/null 2>&1; then
    NODE_VERSION="$(node -v 2>/dev/null || true)"
    NODE_MAJOR="${NODE_VERSION#v}"
    NODE_MAJOR="${NODE_MAJOR%%.*}"
fi
if [[ "$NODE_MAJOR" =~ ^[0-9]+$ ]] && [ "$NODE_MAJOR" -ge 22 ]; then
    echo "==> Node $NODE_VERSION already present (>=22), skipping."
else
    echo "==> Installing Node.js 24 LTS (NodeSource)..."
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
    apt-get install -y nodejs
fi

# ─── 6. CLI utility symlinks ─────────────────────────────────────────────────
echo "==> Creating symlinks for CLI utilities..."
for util in shpd-server shpd-ds; do
    target="/usr/bin/$util"
    source="$PROJECT_DIR/bin/$util"
    if [ -L "$target" ] && [ "$(readlink "$target")" = "$source" ]; then
        echo "    $target already correct, skipping."
    elif [ -e "$target" ] || [ -L "$target" ]; then
        ln -sfn "$source" "$target"
        echo "    Updated: $target -> $source"
    else
        ln -s "$source" "$target"
        echo "    Created: $target -> $source"
    fi
done

# ─── 7. Filesystem layout ────────────────────────────────────────────────────
echo "==> Creating /opt/shipard and /etc/shipard..."
mkdir -p /opt/shipard/data-sources /opt/shipard/log /etc/shipard

chown "$SHIPARD_USER:$SHIPARD_USER" /opt/shipard /opt/shipard/data-sources /opt/shipard/log
# /opt/shipard is 0751 so nginx (www-data) can traverse into /opt/shipard/shpd
# for SPA static asset serving. Contents (data-sources, log) stay 0750.
# The path to the checkout behind the symlink is handled below.
chmod 0751 /opt/shipard
chmod 0750 /opt/shipard/data-sources /opt/shipard/log

chown "root:$SHIPARD_USER" /etc/shipard
chmod 0750 /etc/shipard
if [ -f /etc/shipard/server.json ]; then
    chown "root:$SHIPARD_USER" /etc/shipard/server.json
    chmod 0640 /etc/shipard/server.json
fi

# /opt/shipard/shpd → project clone (idempotent). nginx root references this path.
SHPD_LINK="/opt/shipard/shpd"
if [ -L "$SHPD_LINK" ]; then
    current="$(readlink "$SHPD_LINK")"
    if [ "$current" != "$PROJECT_DIR" ]; then
        echo "    Updating $SHPD_LINK: $current -> $PROJECT_DIR"
        ln -sfn "$PROJECT_DIR" "$SHPD_LINK"
    fi
    chown -h "$SHIPARD_USER:$SHIPARD_USER" "$SHPD_LINK"
elif [ -e "$SHPD_LINK" ]; then
    echo "    Note: $SHPD_LINK exists and is not a symlink — leaving as-is."
else
    ln -s "$PROJECT_DIR" "$SHPD_LINK"
    chown -h "$SHIPARD_USER:$SHIPARD_USER" "$SHPD_LINK"
    echo "    Created $SHPD_LINK -> $PROJECT_DIR"
fi

# In development the checkout lives under /home/<user>, which Ubuntu >= 21.04
# creates as 0750 (HOME_MODE) — nginx (www-data) cannot follow the symlink
# above. Grant traverse (o+x: pass through, no listing) on the parent
# directories of the checkout, same as 0751 on /opt/shipard. Only directories
# owned by the shipard user are changed; the checkout itself stays as git
# made it. In production the checkout is a real directory in /opt/shipard.
if [ "$MODE" = "development" ]; then
    dir="$(dirname "$(realpath "$PROJECT_DIR")")"
    while [ "$dir" != "/" ]; do
        dir_mode="$(stat -c '%a' "$dir")"
        if (( (8#$dir_mode & 1) == 0 )); then
            dir_owner="$(stat -c '%U' "$dir")"
            if [ "$dir_owner" != "$SHIPARD_USER" ]; then
                echo "Error: nginx (www-data) cannot reach the checkout in $PROJECT_DIR:" >&2
                echo "       $dir (owner $dir_owner, mode $dir_mode) has no 'x' for others." >&2
                echo "       This script does not change directories of other owners. Move the" >&2
                echo "       checkout, or fix it by hand (chmod o+x $dir), and run the script again." >&2
                exit 1
            fi
            chmod o+x "$dir"
            echo "    Granted traverse (o+x): $dir"
        fi
        dir="$(dirname "$dir")"
    done
fi

# ─── 8. PHP-FPM pool (shipard) ───────────────────────────────────────────────
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/shipard.conf"
POOL_SOCKET="/run/php/php${PHP_VERSION}-fpm-shipard.sock"

# NOTE (D5): the pool file is fully derived (user, socket, include path) and is
# intentionally REGENERATED on every run. Manual edits do not belong here —
# versioned parameters live in docs/php/shipard-fpm-common.conf. A PHP version
# upgrade changes the socket here but not in the (server-owned) nginx site
# file — that mismatch is reported by `shpd-server doctor`.
echo "==> Writing PHP-FPM pool: $POOL_FILE"
cat > "$POOL_FILE" <<EOF
[shipard]
user = $SHIPARD_USER
group = $SHIPARD_USER

listen = $POOL_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

php_admin_value[error_log] = /var/log/php${PHP_VERSION}-fpm-shipard.log
php_admin_flag[log_errors] = on

; Versioned system parameters — updated via git pull + FPM reload
include=$PROJECT_DIR/docs/php/shipard-fpm-common.conf
EOF
chmod 0644 "$POOL_FILE"

echo "==> Restarting php${PHP_VERSION}-fpm..."
systemctl restart "php${PHP_VERSION}-fpm"

# ─── 9. nginx site ───────────────────────────────────────────────────────────
TEMPLATE="$PROJECT_DIR/docs/nginx/${MODE}.conf"
SITE_FILE="/etc/nginx/sites-available/shipard.conf"
SITE_LINK="/etc/nginx/sites-enabled/shipard.conf"

if [ ! -f "$TEMPLATE" ]; then
    echo "Error: nginx template not found: $TEMPLATE" >&2
    exit 1
fi

if [ -f "$SITE_FILE" ] && [ "$FORCE_NGINX" != "1" ]; then
    echo "==> nginx site exists: $SITE_FILE — leaving as-is."
    echo "    (Server-owned file. Regenerate explicitly with --force-nginx.)"
else
    echo "==> Installing nginx site: $SITE_FILE"
    # Substitute the default fastcgi_pass socket with the shipard pool socket.
    {
        echo "# Generated by install-packages.sh on $(date -Iseconds) from docs/nginx/${MODE}.conf"
        echo "# Server-owned file: re-running install-packages.sh will NOT overwrite it."
        echo "# Regenerate explicitly: sudo bash scripts/install-packages.sh --mode=${MODE} --force-nginx"
        echo "# Versioned parameters live in docs/nginx/shipard-*.conf includes (git pull + reload)."
        sed "s|fastcgi_pass unix:/run/php/[^;]*;|fastcgi_pass unix:${POOL_SOCKET};|g" "$TEMPLATE"
    } > "$SITE_FILE"
    chmod 0644 "$SITE_FILE"
fi

ln -sfn "$SITE_FILE" "$SITE_LINK"

# Disable conflicting sites so the new shipard.conf wins on port 80.
#
# IMPORTANT: nginx includes `sites-enabled/*` regardless of file extension,
# so a rename in place (e.g. development.conf → development.conf.disabled-X)
# does NOT deactivate the site — nginx still loads it. We have to MOVE the
# file out of sites-enabled entirely:
#   - symlinks → rm (originál v sites-available zůstane nedotčený)
#   - regular files → mv do sites-available/$name.disabled-TIMESTAMP
for stale in development.conf production.conf default; do
    file="/etc/nginx/sites-enabled/$stale"
    if [ -L "$file" ]; then
        echo "    Removing symlink: $file (original preserved in sites-available)"
        rm -f "$file"
    elif [ -f "$file" ]; then
        timestamp=$(date +%Y%m%d-%H%M%S)
        target="/etc/nginx/sites-available/${stale}.disabled-${timestamp}"
        echo "    Moving: $file → $target"
        mv "$file" "$target"
    fi
done

echo "==> Validating nginx config..."
nginx -t
systemctl reload nginx

# ─── 10. Verify with doctor ──────────────────────────────────────────────────
echo ""
php --version | head -1
mariadb --version

if [ -f /etc/shipard/server.json ]; then
    echo ""
    echo "==> Verifying installation with shpd-server doctor..."
    echo ""
    if shpd-server doctor; then
        echo ""
        echo "==> Installation complete and verified."
    else
        echo ""
        echo "==> Installation finished, but doctor found issues."
        echo "    Review the report above and fix before proceeding."
        exit 1
    fi
else
    echo ""
    echo "==> Installation complete (doctor skipped — server-init not yet run)."
    echo ""
    echo "Next steps:"
    step=1
    if [ "$MODE" = "development" ]; then
        # shpd-server needs vendor/, so dependencies come before server-init.
        cat <<EOF

  $step. Install dependencies and build the frontend — as '$SHIPARD_USER',
     not as root:

       bash scripts/dev-update.sh
EOF
        step=$((step + 1))
    fi
    cat <<EOF

  $step. Initialize the server config (creates /etc/shipard/server.json
     with admin DB credentials):

       sudo shpd-server server-init --mode=$MODE --user=$SHIPARD_USER

  $((step + 1)). Verify the setup:

       shpd-server doctor

  $((step + 2)). If 'doctor' reports fixable issues (e.g. after migrating from an
     older layout), apply the contract:

       sudo shpd-server fix-permissions --dry-run
       sudo shpd-server fix-permissions
EOF
fi
