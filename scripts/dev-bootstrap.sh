#!/usr/bin/env bash
# dev-bootstrap.sh — one-command development setup of Shipard on Ubuntu LTS
# (24.04 / 26.04): Multipass VM, WSL or a plain machine.
#
# Glues the steps of DEVELOPERS.md together, it does not replace them — fixes
# belong to install-packages.sh and DEVELOPERS.md:
#   checkout → install-packages.sh → dev-update.sh → server-init → git hooks
#   → render service (--with-render) → SSH for remote-dev-bridge (--with-ssh)
#   → doctor → summary with the dashboard address
#
# Idempotent. Run it as your own user, NOT via sudo: the script calls sudo
# itself, so that install-packages.sh finds the developer in $SUDO_USER.
#
# Usage:
#   bash scripts/dev-bootstrap.sh [options]
#   curl -fsSL https://raw.githubusercontent.com/shipard/shipard/stable/scripts/dev-bootstrap.sh | bash
#   curl -fsSL … | bash -s -- --with-ssh --ssh-pubkey-file <file>
#
# Everything lives in functions and the last line starts it: a partially
# downloaded copy (curl | bash) never runs half of the steps.
#
# Step headers are in English like the scripts this one calls; hints, errors
# and the final summary are in Czech (docs/local-dev.md is the guide).

set -euo pipefail

REPO_URL="https://github.com/shipard/shipard.git"
DOCS_URL="https://github.com/shipard/shipard/blob/stable/docs/local-dev.md"
SERVER_JSON="/etc/shipard/server.json"
RENDER_URL="http://127.0.0.1:3000"
RENDER_WAIT_SEC=600
SSH_PORT=2222
SSHD_DROPIN="/etc/ssh/sshd_config.d/shipard-dev.conf"

BRANCH="stable"
BRANCH_GIVEN=0
TARGET_DIR=""
WITH_RENDER=0
WITH_SSH=0
SSH_PUBKEY_FILE=""

IS_WSL=0
CURRENT_STEP=""
KEEPALIVE_PID=""
TMP_DIR=""

usage() {
    cat <<EOF
Použití: bash scripts/dev-bootstrap.sh [volby]

Rozjede vývojové prostředí Shipardu na Ubuntu 24.04 / 26.04 (Multipass, WSL,
běžný stroj): balíčky, závislosti, build frontendu, konfigurace serveru.
Končí zeleným 'shpd-server doctor' a adresou vývojářského dashboardu.

Spouštěj pod svým uživatelem, ne přes sudo. Opakované spuštění je bezpečné.

Volby:
  --with-render              i PDF rendering služba (Gotenberg v podmanu)
  --with-ssh                 SSH server pro remote-dev-bridge: port $SSH_PORT,
                             přihlášení jen klíčem
  --ssh-pubkey-file <cesta>  veřejný klíč do ~/.ssh/authorized_keys (ve WSL
                             typicky /mnt/c/Users/<user>/.ssh/id_ed25519.pub)
  --branch <větev>           větev pro nový checkout (výchozí: stable)
  --dir <cesta>              adresář checkoutu (výchozí: ~/sw/shpd; při spuštění
                             z checkoutu ten checkout)
  -h, --help                 tato nápověda

Návod krok za krokem: docs/local-dev.md
EOF
}

# fail <message> [<hint line>…] — nothing fails silently.
fail() {
    local line
    echo "" >&2
    echo "Chyba: $1" >&2
    shift
    for line in "$@"; do
        echo "       $line" >&2
    done
    exit 1
}

step() {
    CURRENT_STEP="$1"
    echo ""
    echo "==> $1"
}

on_exit() {
    local code=$?
    if [ -n "$KEEPALIVE_PID" ]; then
        kill "$KEEPALIVE_PID" 2>/dev/null || true
    fi
    if [ -n "$TMP_DIR" ]; then
        rm -rf "$TMP_DIR"
    fi
    if [ "$code" -ne 0 ] && [ -n "$CURRENT_STEP" ]; then
        {
            echo ""
            echo "==> Bootstrap selhal v kroku: $CURRENT_STEP (exit $code)"
            echo "    Příčinu ukazuje výstup výše. Po opravě spusť bootstrap znovu —"
            echo "    hotové kroky se jen ověří."
            echo "    Nápověda: $DOCS_URL"
        } >&2
    fi
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --with-render) WITH_RENDER=1 ;;
            --with-ssh)    WITH_SSH=1 ;;
            --ssh-pubkey-file=*) SSH_PUBKEY_FILE="${1#*=}" ;;
            --branch=*)          BRANCH="${1#*=}"; BRANCH_GIVEN=1 ;;
            --dir=*)             TARGET_DIR="${1#*=}" ;;
            --ssh-pubkey-file|--branch|--dir)
                if [[ $# -lt 2 ]]; then
                    fail "volba $1 potřebuje hodnotu (nápověda: --help)."
                fi
                case "$1" in
                    --ssh-pubkey-file) SSH_PUBKEY_FILE="$2" ;;
                    --branch)          BRANCH="$2"; BRANCH_GIVEN=1 ;;
                    --dir)             TARGET_DIR="$2" ;;
                esac
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                fail "neznámá volba '$1' (nápověda: --help)."
                ;;
        esac
        shift
    done

    if [ -z "$BRANCH" ]; then
        fail "volba --branch nesmí být prázdná."
    fi
    if [ -n "$SSH_PUBKEY_FILE" ] && [ "$WITH_SSH" != 1 ]; then
        fail "--ssh-pubkey-file má smysl jen spolu s --with-ssh."
    fi
}

# Started from a checkout → that checkout. Piped into bash (curl | bash)
# BASH_SOURCE is not a file and the documented default applies.
default_target_dir() {
    local src="${BASH_SOURCE[0]:-}" dir
    if [ -f "$src" ] && [ "$(basename "$src")" = "dev-bootstrap.sh" ]; then
        dir="$(cd "$(dirname "$src")/.." && pwd)"
        if [ -e "$dir/.git" ] && [ -f "$dir/scripts/install-packages.sh" ]; then
            echo "$dir"
            return
        fi
    fi
    echo "$HOME/sw/shpd"
}

# Reads --ssh-pubkey-file into $TMP_DIR/pubkeys: one key per line, without the
# CR of a file written on Windows. The lines are never echoed — a private key
# passed by mistake must not end up in a log.
load_pubkeys() {
    local line count=0
    local re='^(ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com)[[:space:]]+[A-Za-z0-9+/=]+([[:space:]].*)?$'

    if [ ! -f "$SSH_PUBKEY_FILE" ] || [ ! -r "$SSH_PUBKEY_FILE" ]; then
        fail "soubor s veřejným klíčem nejde přečíst: $SSH_PUBKEY_FILE"
    fi

    : > "$TMP_DIR/pubkeys"
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in
            ''|'#'*) continue ;;
        esac
        if [[ ! "$line" =~ $re ]]; then
            fail "$SSH_PUBKEY_FILE nevypadá jako veřejný SSH klíč." \
                 "Čekám řádek začínající ssh-ed25519, ssh-rsa nebo ecdsa-sha2-…" \
                 "Předej soubor .pub — nikdy soukromý klíč."
        fi
        printf '%s\n' "$line" >> "$TMP_DIR/pubkeys"
        count=$((count + 1))
    done < "$SSH_PUBKEY_FILE"

    if [ "$count" -eq 0 ]; then
        fail "v souboru $SSH_PUBKEY_FILE není žádný veřejný klíč."
    fi
    if command -v ssh-keygen >/dev/null 2>&1 \
        && ! ssh-keygen -l -f "$TMP_DIR/pubkeys" >/dev/null 2>&1; then
        fail "$SSH_PUBKEY_FILE není platný veřejný SSH klíč (ssh-keygen ho odmítl)."
    fi
}

# ─── 1. Prerequisites ────────────────────────────────────────────────────────
check_prerequisites() {
    local os_id="" os_version=""

    step "Checking prerequisites"

    if [ "$(id -u)" -eq 0 ]; then
        fail "skript nespouštěj jako root ani přes sudo." \
             "Spusť ho pod svým uživatelem — sudo si zavolá sám. Podle toho, kdo ho" \
             "spustil, se určí vývojář, kterému bude instalace patřit."
    fi
    if ! command -v sudo >/dev/null 2>&1; then
        fail "chybí příkaz sudo." \
             "Jako root ho nainstaluj (apt-get install sudo) a přidej svého uživatele" \
             "do skupiny sudo."
    fi

    # The same simple check as in install-packages.sh (which does the real
    # validation) — here only to stop before anything is cloned or installed.
    if [ -r /etc/os-release ]; then
        os_id="$(. /etc/os-release && echo "${ID:-}")"
        os_version="$(. /etc/os-release && echo "${VERSION_ID:-}")"
    fi
    case "$os_id:$os_version" in
        ubuntu:24.04|ubuntu:26.04) ;;
        *)
            fail "nepodporovaný systém '${os_id:-neznámý} ${os_version:-}'." \
                 "Podporované: Ubuntu 24.04 LTS a 26.04 LTS."
            ;;
    esac

    if [ ! -d /run/systemd/system ]; then
        fail "v systému neběží systemd — bez něj se nespustí nginx, PHP-FPM ani MariaDB." \
             "Ve WSL: použij WSL 2 a v /etc/wsl.conf nastav v sekci [boot] systemd=true," \
             "pak ve Windows spusť 'wsl --shutdown' a otevři Ubuntu znovu."
    fi

    if grep -qiE 'microsoft|wsl' /proc/sys/kernel/osrelease 2>/dev/null; then
        IS_WSL=1
    fi

    if [ "$WITH_SSH" = 1 ]; then
        if [ -n "$SSH_PUBKEY_FILE" ]; then
            load_pubkeys
        elif [ ! -s "$HOME/.ssh/authorized_keys" ]; then
            fail "--with-ssh potřebuje veřejný klíč: přidej --ssh-pubkey-file <cesta>." \
                 "SSH se povolí jen pro přihlášení klíčem a ~/.ssh/authorized_keys je" \
                 "prázdný — nikdo by se nepřihlásil." \
                 "Ve WSL typicky: --ssh-pubkey-file /mnt/c/Users/<user>/.ssh/id_ed25519.pub"
        fi
    fi

    # One sudo prompt, right at the start. `sudo -n` first: on cloud images
    # (passwordless sudo) `sudo -v` still asks for a password — it wants
    # NOPASSWD on every sudoers entry of the user, the sudo group included —
    # and under cloud-init there is no terminal to answer.
    if ! sudo -n true 2>/dev/null; then
        if ! (exec < /dev/tty) 2>/dev/null; then
            fail "sudo chce heslo a není tu terminál, kde by se na něj dalo zeptat." \
                 "Spusť bootstrap z interaktivního terminálu, nebo uživateli povol" \
                 "sudo bez hesla."
        fi
        echo "    Instalace potřebuje sudo — heslo zadáš jen teď na začátku."
        sudo -v
    fi

    # Keeps the sudo timestamp fresh: dev-update.sh between the sudo steps can
    # outlast it on a slow machine and the password prompt would come back
    # in the middle of the run.
    ( while sleep 60; do sudo -n true 2>/dev/null || exit 0; done ) \
        < /dev/null > /dev/null 2>&1 &
    KEEPALIVE_PID=$!

    echo "    OK: Ubuntu $os_version, uživatel $(id -un), sudo, systemd."
}

# ─── 2. Checkout ─────────────────────────────────────────────────────────────
ensure_checkout() {
    step "Checkout: $TARGET_DIR"

    if [ -e "$TARGET_DIR" ]; then
        if [ ! -e "$TARGET_DIR/.git" ] || [ ! -f "$TARGET_DIR/scripts/install-packages.sh" ]; then
            fail "$TARGET_DIR existuje, ale není to checkout Shipardu." \
                 "Přesuň ho jinam, nebo zvol jiný adresář volbou --dir."
        fi
        # No git pull — the bootstrap never touches work in progress.
        echo "    Checkout už existuje, nic nestahuji (žádný git pull)."
        if [ "$BRANCH_GIVEN" = 1 ]; then
            echo "    Volba --branch platí jen pro nový checkout — tady se nepoužije."
        fi
    else
        if ! command -v git >/dev/null 2>&1; then
            echo "    Instaluji git…"
            sudo env DEBIAN_FRONTEND=noninteractive apt-get update
            sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y git
        fi
        mkdir -p "$(dirname "$TARGET_DIR")"
        git clone --branch "$BRANCH" "$REPO_URL" "$TARGET_DIR"
    fi

    TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"
}

# ─── 7. Render service (--with-render) ───────────────────────────────────────
# Single-server setup from docs/operations/render-service.md.
setup_render() {
    local unit_src="$TARGET_DIR/docs/render/shpd-render.service"
    local unit_dst="/etc/systemd/system/shpd-render.service"
    local waited=0

    step "PDF rendering service (--with-render)"

    if [ -f "$unit_dst" ] && cmp -s "$unit_src" "$unit_dst"; then
        sudo systemctl enable shpd-render
        sudo systemctl start shpd-render
    else
        sudo install -o root -g root -m 0644 "$unit_src" "$unit_dst"
        sudo systemctl daemon-reload
        sudo systemctl enable shpd-render
        sudo systemctl restart shpd-render
    fi

    echo "    Čekám na $RENDER_URL/health — první spuštění stahuje image,"
    echo "    může to trvat několik minut."
    until curl -fs -o /dev/null --max-time 5 "$RENDER_URL/health"; do
        if [ "$waited" -ge "$RENDER_WAIT_SEC" ]; then
            fail "render služba se do $RENDER_WAIT_SEC s neozvala." \
                 "Podívej se: systemctl status shpd-render" \
                 "            sudo journalctl -u shpd-render -n 50" \
                 "Diagnostika: docs/operations/render-service.md"
        fi
        sleep 5
        waited=$((waited + 5))
        if [ $((waited % 30)) -eq 0 ]; then
            echo "    … stále čekám ($waited s)"
        fi
    done
    echo "    Render služba odpovídá."

    # Adds the "render" key to server.json as root. All other keys are kept,
    # nothing from the file is printed and an existing "render" is left alone.
    sudo php -- "$SERVER_JSON" "$RENDER_URL" <<'PHP'
<?php
[, $path, $url] = $argv;

// Decoded into objects, so that an empty {} does not come back as [].
$json = @file_get_contents($path);
$data = $json === false ? null : json_decode($json);
if (!$data instanceof stdClass) {
    fwrite(STDERR, "Chyba: $path nejde přečíst jako JSON objekt.\n");
    exit(1);
}
if (property_exists($data, 'render')) {
    echo "    Klíč render v $path už je — nechávám ho beze změny.\n";
    exit(0);
}
$data->render = (object) ['url' => $url, 'timeoutSec' => 30];

// Written next to the file and renamed over it, with the owner and mode of
// the original: the file holds the only copy of the admin DB password and
// must never end up half-written.
$out = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
);
$stat = stat($path);
$tmp = $path . '.tmp.' . getmypid();
umask(0077);
$ok = $out !== false
    && $stat !== false
    && file_put_contents($tmp, $out . "\n") !== false
    && chown($tmp, $stat['uid'])
    && chgrp($tmp, $stat['gid'])
    && chmod($tmp, $stat['mode'] & 07777)
    && rename($tmp, $path);
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "Chyba: zápis do $path se nepovedl.\n");
    exit(1);
}
echo "    Do $path doplněn klíč render.\n";
PHP
}

# ─── 8. SSH for remote-dev-bridge (--with-ssh) ───────────────────────────────

# Effective sshd configuration into a file. Needs root (host keys) and the
# privilege separation directory, which exists only while ssh.service runs.
sshd_dump() {
    sudo mkdir -p /run/sshd
    sudo /usr/sbin/sshd -T > "$1"
}

# write_sshd_dropin [<port to keep>…]
write_sshd_dropin() {
    local port
    {
        echo "# Generated by scripts/dev-bootstrap.sh --with-ssh (SSH for remote-dev-bridge)."
        echo "# Key login only. Rewritten on every bootstrap run — do not edit."
        for port in "$@"; do
            echo "Port $port"
        done
        echo "Port $SSH_PORT"
        echo "PasswordAuthentication no"
        echo "KbdInteractiveAuthentication no"
    } > "$TMP_DIR/sshd-dropin"
    sudo install -o root -g root -m 0644 "$TMP_DIR/sshd-dropin" "$SSHD_DROPIN"
}

setup_ssh() {
    local auth="$HOME/.ssh/authorized_keys"
    local line key value port added=0 tries=0 ports_before=""
    local -a lost_ports=()

    step "SSH for remote-dev-bridge (--with-ssh)"

    if [ ! -x /usr/sbin/sshd ]; then
        sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a \
            apt-get install -y openssh-server
    fi
    # Host keys, in case the image shipped the package without them.
    sudo ssh-keygen -A > /dev/null

    mkdir -p "$HOME/.ssh"
    chmod 0700 "$HOME/.ssh"
    touch "$auth"
    chmod 0600 "$auth"
    if [ -n "$SSH_PUBKEY_FILE" ]; then
        # A file without the final newline would glue the new key to the last one.
        if [ -s "$auth" ] && [ -n "$(tail -c 1 "$auth")" ]; then
            echo >> "$auth"
        fi
        while IFS= read -r line; do
            if ! grep -qxF -- "$line" "$auth"; then
                printf '%s\n' "$line" >> "$auth"
                added=$((added + 1))
            fi
        done < "$TMP_DIR/pubkeys"
        echo "    ~/.ssh/authorized_keys: nově přidáno klíčů: $added."
    fi

    # A lone "Port 2222" replaces the default port 22 — on a VM that would cut
    # off the host tooling (multipass shell / exec). Outside WSL the ports sshd
    # listened on before therefore stay; in WSL it is 2222 only, port 22 may
    # belong to Windows. Kept ports are re-added only when the drop-in made
    # them disappear, so an explicit Port elsewhere is never duplicated.
    if [ "$IS_WSL" != 1 ]; then
        sshd_dump "$TMP_DIR/sshd-before"
        while read -r key value _; do
            if [ "$key" = "port" ]; then
                ports_before="$ports_before $value"
            fi
        done < "$TMP_DIR/sshd-before"
    fi
    write_sshd_dropin
    sshd_dump "$TMP_DIR/sshd-after"
    for port in $ports_before; do
        if ! grep -qx "port $port" "$TMP_DIR/sshd-after"; then
            lost_ports+=("$port")
        fi
    done
    if [ "${#lost_ports[@]}" -gt 0 ]; then
        write_sshd_dropin "${lost_ports[@]}"
        sshd_dump "$TMP_DIR/sshd-after"
    fi

    # sshd takes the first value it finds and this drop-in sorts after the
    # numbered ones (50-cloud-init.conf…), so check what really applies. With
    # password login still on, the new port is not opened at all.
    if ! grep -qx "port $SSH_PORT" "$TMP_DIR/sshd-after" \
        || ! grep -qx "passwordauthentication no" "$TMP_DIR/sshd-after" \
        || ! grep -qx "kbdinteractiveauthentication no" "$TMP_DIR/sshd-after"; then
        sudo rm -f "$SSHD_DROPIN"
        fail "nastavení z $SSHD_DROPIN se neuplatnilo (port $SSH_PORT, přihlášení jen klíčem)." \
             "sshd bere první nalezenou hodnotu — přihlášení heslem zapíná dřív jiný" \
             "soubor v /etc/ssh/sshd_config.d/ nebo /etc/ssh/sshd_config." \
             "Drop-in jsem zase odstranil a sshd běží beze změny. Uprav konfiguraci" \
             "a spusť bootstrap znovu."
    fi

    sudo systemctl daemon-reload
    if systemctl is-enabled --quiet ssh.socket 2>/dev/null; then
        # Socket activation (Ubuntu 22.10+): the listening port comes from a
        # generator that reads sshd_config on daemon-reload. A socket cannot
        # start while its service runs, so both go down first — open sessions
        # survive (KillMode=process).
        sudo systemctl stop ssh.socket ssh.service
        sudo systemctl start ssh.socket
    else
        sudo systemctl enable ssh.service
        sudo systemctl restart ssh.service
    fi

    while :; do
        ss -ltnH > "$TMP_DIR/ss-listen"
        if grep -qE ":${SSH_PORT}[[:space:]]" "$TMP_DIR/ss-listen"; then
            break
        fi
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            fail "sshd po restartu na portu $SSH_PORT nenaslouchá." \
                 "Podívej se: systemctl status ssh.socket ssh.service"
        fi
        sleep 1
    done
    echo "    sshd naslouchá na portu $SSH_PORT, přihlášení jen klíčem."
}

# ─── 9. Doctor ───────────────────────────────────────────────────────────────
run_doctor() {
    step "Verification (shpd-server doctor)"

    if ! shpd-server doctor; then
        {
            echo ""
            echo "    doctor našel problém — který, říká výpis výše. Práva opravíš:"
            echo "        sudo shpd-server fix-permissions --dry-run   # náhled"
            echo "        sudo shpd-server fix-permissions             # oprava"
        } >&2
        exit 1
    fi
}

# ─── 10. Summary ─────────────────────────────────────────────────────────────
print_summary() {
    local host="localhost" ips=""

    # WSL forwards localhost from Windows; elsewhere the first address of the
    # machine (Multipass: the address `multipass info` shows).
    if [ "$IS_WSL" != 1 ]; then
        ips="$(hostname -I 2>/dev/null || true)"
        read -r host _ <<< "$ips" || true
        if [ -z "$host" ]; then
            host="localhost"
        fi
    fi

    cat <<EOF

==> Hotovo — vývojové prostředí Shipardu běží.

    Dashboard:  http://$host/_dev/
    Checkout:   $TARGET_DIR

    Co dál:
      1. Otevři dashboard v prohlížeči a založ první zdroj dat:
         + New DS → zaškrtni „Seed test data“ → Create Data Source → Open.
      2. Claude Code a napojení Clauda v chatu (remote-dev-bridge):
         $DOCS_URL
      3. Po 'git pull' se závislosti a frontend aktualizují samy (git hooky);
         po změně tabulek spusť 'shpd-server ds-upgrade-all'.
EOF

    if [ "$WITH_SSH" = 1 ]; then
        cat <<EOF

    SSH pro remote-dev-bridge:
      host:      $host
      port:      $SSH_PORT
      uživatel:  $(id -un)
      root:      $TARGET_DIR
EOF
    fi
    echo ""
}

main() {
    parse_args "$@"
    trap on_exit EXIT
    TMP_DIR="$(mktemp -d)"
    if [ -z "$TARGET_DIR" ]; then
        TARGET_DIR="$(default_target_dir)"
    fi

    check_prerequisites
    ensure_checkout

    # ─── 3. System packages, /opt/shipard, PHP-FPM pool, nginx ───────────────
    step "System packages and server layout (install-packages.sh)"
    sudo bash "$TARGET_DIR/scripts/install-packages.sh" --mode=development

    # ─── 4. composer, npm, frontend build — as the developer, not root ───────
    step "Dependencies and frontend build (dev-update.sh)"
    bash "$TARGET_DIR/scripts/dev-update.sh"

    # ─── 5. /etc/shipard/server.json (a no-op when it already exists) ────────
    step "Server config (shpd-server server-init)"
    sudo shpd-server server-init --mode=development

    # ─── 6. pre-commit checks, dev-update.sh after git pull ──────────────────
    step "Git hooks"
    git -C "$TARGET_DIR" config core.hooksPath .githooks
    echo "    core.hooksPath = .githooks"

    if [ "$WITH_RENDER" = 1 ]; then
        setup_render
    fi
    if [ "$WITH_SSH" = 1 ]; then
        setup_ssh
    fi

    run_doctor

    CURRENT_STEP=""
    print_summary
}

main "$@"
