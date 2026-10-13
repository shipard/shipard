#!/usr/bin/env bash
#
# dev-update.sh — sync dev environment after pulling new code.
#
# Philosophy: always run all steps. They're idempotent and fast on no-op,
# so it's simpler and safer to run everything than to detect what changed.
#
# Steps:
#   1. composer install   (regenerates autoloader as a side-effect)
#   2. npm install        (in frontend/)
#   3. npm run build      (in frontend/)
#
# Data-source upgrades (ds-upgrade-all) and the system cron file
# (cron-install) are intentionally NOT run here — both need sudo; the former
# touches real DB state, the latter the system and, on the first install,
# every data source's state.json. The script only reminds the user.
# SHPD_DEV_UPDATE_NO_CRON_HINT=1 silences the cron reminder (dev-bootstrap.sh
# sets it — it installs cron itself two steps later).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

echo "==> [1/3] composer install"
composer install --no-interaction

echo ""
echo "==> [2/3] npm install (frontend)"
(cd frontend && npm install --no-audit --no-fund)

echo ""
echo "==> [3/3] npm run build (frontend)"
(cd frontend && npm run build)

echo ""
echo "==> Done. Pokud se měnily definice tabulek nebo cfgItems modulů,"
echo "    upgraduj všechny datové zdroje:"
echo ""
echo "        sudo shpd-server ds-upgrade-all"
echo ""

# Periodic jobs (#115 D6): cron file missing or outdated → remind. The check
# is silent and needs no root; without shpd-server on PATH (install-packages.sh
# not run yet) there is nothing to check.
if [ -z "${SHPD_DEV_UPDATE_NO_CRON_HINT:-}" ] && command -v shpd-server >/dev/null 2>&1 \
    && ! shpd-server cron-install --check >/dev/null 2>&1; then
    if [ -e /etc/cron.d/shipard ]; then
        echo "==> Cron soubor /etc/cron.d/shipard je zastaralý — přegeneruj ho:"
        echo ""
        echo "        sudo shpd-server cron-install"
    else
        echo "==> Periodické úlohy (cron) na tomto stroji neběží — nainstaluj je:"
        echo ""
        echo "        sudo shpd-server cron-install"
        echo ""
        echo "    První instalace vypne cron všem existujícím zdrojům dat a vypíše je;"
        echo "    zapni ho těm, které chceš: sudo shpd-server ds-cron --on <id>"
    fi
    echo ""
fi
