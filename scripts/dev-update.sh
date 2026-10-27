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
#   4. npm install + Chromium of Playwright   (in tests/e2e/)          ┐ only when
#   5. npm install + Chromium of Playwright   (in tools/video-runner/) ┘ node_modules exists
#
# Steps 4–5 keep the testing tools (docs/dev/dev-daily.md § 5, installed by
# dev-bootstrap.sh --with-testing) in step with the checkout; a project
# without node_modules is skipped silently — the developer did not want the
# tools. Chromium's system libraries need root and are not touched here:
# when a new revision wants more of them, the preflight of Proklikat / Videa
# says so (step 4 of § 5 fixes it).
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

E2E_DIR="tests/e2e"
VIDEO_DIR="tools/video-runner"
E2E_CLI="$E2E_DIR/node_modules/@playwright/test/cli.js"
VIDEO_CLI="$VIDEO_DIR/node_modules/playwright/cli.js"

# Step numbering counts only the steps that will run.
TOTAL=3
if [ -d "$E2E_DIR/node_modules" ]; then
    TOTAL=$((TOTAL + 1))
fi
if [ -d "$VIDEO_DIR/node_modules" ]; then
    TOTAL=$((TOTAL + 1))
fi
STEP=0

step() {
    STEP=$((STEP + 1))
    if [ "$STEP" -gt 1 ]; then
        echo ""
    fi
    echo "==> [$STEP/$TOTAL] $1"
}

# update_playwright_project <dir> <playwright cli.js> — dependencies and the
# browser of one Node project, as the current user (the browser lives in
# ~/.cache/ms-playwright of whoever runs this). Not npx: it would look at the
# registry. Skipped without node_modules.
update_playwright_project() {
    local dir="$1" cli="$2"
    if [ ! -d "$dir/node_modules" ]; then
        return 0
    fi
    step "npm install + Chromium ($dir)"
    (cd "$dir" && npm install --no-audit --no-fund)
    (cd "$dir" && node "$REPO_ROOT/$cli" install chromium)
}

step "composer install"
composer install --no-interaction

step "npm install (frontend)"
(cd frontend && npm install --no-audit --no-fund)

step "npm run build (frontend)"
(cd frontend && npm run build)

update_playwright_project "$E2E_DIR" "$E2E_CLI"
update_playwright_project "$VIDEO_DIR" "$VIDEO_CLI"

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
