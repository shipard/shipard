#!/usr/bin/env bash
# test-round.sh — testovací kolo Shipardu na čisté Multipass VM (#146, F8b).
#
# Runs ON THE HOST (macOS, or Linux with Multipass; Windows is not supported):
# creates a fresh VM from the cloud-init of the tested branch exactly the way
# a newcomer does (docs/dev/local-dev.md), runs the bootstrap a second time
# with --with-testing, runs `shpd-server test-round` inside the VM, optionally
# the AI evaluation, copies the results to ~/shipard-rounds/<vm>/ and deletes
# the VM on success (it stays after a failure, with instructions). A checkout
# on the host is not needed — only this file:
#
#   curl -fsSLO https://raw.githubusercontent.com/shipard/shipard/stable/scripts/multipass/test-round.sh
#   bash test-round.sh                                  # stable, Ubuntu 26.04, whole round
#   bash test-round.sh --branch my-branch -- --only datasets
#   bash test-round.sh --eval baseline --repeat 1 --max-cost 1 --ai-key-file ~/.secrets/anthropic.key
#
# Written for bash 3.2 (the macOS default) with BSD or GNU tools: no
# associative arrays, mapfile, ${x,,}, sed -i, date -d, readlink -f, grep -P;
# no jq or python on the host. Everything lives in functions and the last
# line starts it, so a partially downloaded copy never runs half of it.
# Phase headers are in English like dev-bootstrap.sh, messages and the
# summary in Czech. Documentation: docs/test-corpus.md § 22.

set -euo pipefail

RAW_BASE="https://raw.githubusercontent.com/shipard/shipard"
RESULTS_FORMAT="shpd.testRound.cleanServer.v1"
LAUNCH_TIMEOUT=3600
# cloud-init phase (#146 N5): short `cloud-init status` queries every
# CLOUD_INIT_POLL s, each under a watchdog of EXEC_GUARD s, CLOUD_INIT_LIMIT s
# in total — on Apple Virtualization Framework (Multipass 1.17) a `multipass
# exec` started while the VM boots may never return although the command
# inside finished; `timeout` does not exist on macOS.
CLOUD_INIT_LIMIT=3600
CLOUD_INIT_POLL=10
EXEC_GUARD=30

BRANCH="stable"
IMAGE="26.04"
CPUS=4
MEMORY="8G"
DISK="30G"
OUT_ROOT="$HOME/shipard-rounds"
KEEP=0
EVAL_VARIANT=""
EVAL_REPEAT=""
EVAL_MAX_COST=""
AI_KEY_FILE=""
EVAL_DATASETS=()
ROUND_ARGS=()

VM=""
OUT_DIR=""
TMP_DIR=""
GUARDED_PID=""
PHASES_FILE=""
NOTES_FILE=""
GUEST_FILE=""
VM_EXISTS=0
VM_DELETED=0
ROUND_EXIT=""
EVAL_EXIT=""
FINAL_EXIT=""
RUN_EXIT=0
INTERRUPTED=0
STARTED_AT=""
MULTIPASS_VERSION=""
HOST_OS=""
HOST_ARCH=""
DASHBOARD_URL=""
PHASE_ID=""
PHASE_TITLE=""
PHASE_START=""
PHASE_START_ISO=""

usage() {
    cat <<USAGE
Použití: bash test-round.sh [volby] [-- <volby pro shpd-server test-round>]

Založí čistou Multipass VM, nainstaluje do ní Shipard cestou nováčka
(cloud-init ze shipard-dev.yaml testované větve), podruhé spustí bootstrap
s --with-testing, pustí celé testovací kolo (kód, sady, videa) a výsledky
odnese do ~/shipard-rounds/<vm>/. VM po úspěchu smaže, po selhání nechá.
Testuje se pushnutý kód z GitHubu, ne lokální změny.

Volby:
  --branch <větev>          testovaná větev na GitHubu (výchozí stable)
  --image <verze>           26.04 (výchozí) nebo 24.04
  --cpus <n> --memory <x> --disk <x>
                            velikost VM (výchozí $CPUS / $MEMORY / $DISK)
  --out <adresář>           kořen výsledků (výchozí ~/shipard-rounds)
  --keep                    VM nechat i po úspěchu
  --eval <varianta> --repeat <n> --max-cost <USD> --ai-key-file <soubor>
                            evaluace po kole (všechny čtyři volby spolu;
                            klíč se čte jen ze souboru, nikdy z argumentu)
  --eval-dataset <sada>     sada pro evaluaci (opakovatelné; výchozí všechny)
  -h, --help                tato nápověda

Vše za -- jde beze změny do prvního shpd-server test-round
(--only, --dataset, --scenario).

Exit: 0 vše prošlo; 1 kolo nebo evaluace skončily 1; 2 instalace selhala
nebo kolo nezačalo; 130 přerušeno (VM zůstane).

Dokumentace: docs/test-corpus.md § 22
USAGE
}

# ─── Helpers ─────────────────────────────────────────────────────────────────

now_iso() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

now_epoch() {
    date +%s
}

# fmt_duration <seconds> → H:MM:SS
fmt_duration() {
    local s="$1"
    printf '%d:%02d:%02d' $((s / 3600)) $((s % 3600 / 60)) $((s % 60))
}

# json_escape <text> — one JSON string body (no quotes): backslash, quote,
# control characters; newlines and tabs become spaces.
json_escape() {
    printf '%s' "$1" | tr '\n\t' '  ' | tr -d '\000-\010\013\014\016-\037' \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# json_number_or_null <value>
json_number_or_null() {
    local re='^[0-9]+([.][0-9]+)?$'
    if [[ "$1" =~ $re ]]; then
        printf '%s' "$1"
    else
        printf 'null'
    fi
}

# note <text> — a remark for the summary (missing results, warnings).
note() {
    echo "    ! $1" >&2
    if [ -n "$NOTES_FILE" ]; then
        printf '%s\n' "$1" >> "$NOTES_FILE"
    fi
}

phase_begin() {
    PHASE_ID="$1"
    PHASE_TITLE="$2"
    PHASE_START="$(now_epoch)"
    PHASE_START_ISO="$(now_iso)"
    echo ""
    echo "==> $2"
}

# phase_end <status> [<exit code>]
phase_end() {
    local end
    if [ -z "$PHASE_ID" ]; then
        return 0
    fi
    end="$(now_epoch)"
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$PHASE_ID" "$PHASE_TITLE" "$PHASE_START_ISO" \
        "$(now_iso)" "$((end - PHASE_START))" "$1" "${2:-}" >> "$PHASES_FILE"
    PHASE_ID=""
}

# run_logged <log file> <command…> — the command's output to the terminal and
# the log; its exit code in RUN_EXIT (PIPESTATUS right after the pipe —
# pipefail alone would return tee's code when the log cannot be written).
run_logged() {
    local log="$1"
    shift
    set +e
    "$@" 2>&1 | tee -a "$log"
    RUN_EXIT=${PIPESTATUS[0]}
    set -e
}

# quote_args <arg…> — one line of shell-quoted words for bash inside the VM.
quote_args() {
    local a out=""
    for a in "$@"; do
        out="$out $(printf '%q' "$a")"
    done
    printf '%s' "$out"
}

vm_exec() {
    multipass exec "$VM" -- "$@"
}

vm_sh() {
    multipass exec "$VM" -- bash -lc "$1"
}

vm_present() {
    multipass info "$VM" >/dev/null 2>&1
}

# vm_ipv4 — the address `multipass info` shows; a placeholder when unknown.
vm_ipv4() {
    local ip
    ip="$(multipass info "$VM" 2>/dev/null | awk '$1 == "IPv4:" {print $2; exit}' || true)"
    printf '%s' "${ip:-<IPv4 z multipass info $VM>}"
}

# vm_exec_guarded <limit s> <output file> <command…> — `multipass exec` in
# the background under a watchdog: the client may hang on AVF (N5), so a call
# that does not return within the limit is killed. Returns the command's exit
# code, 124 when killed by the watchdog. Output (both streams) to the file.
vm_exec_guarded() {
    local limit="$1" out="$2" waited=0 rc
    shift 2
    multipass exec "$VM" -- "$@" > "$out" 2>&1 &
    GUARDED_PID=$!
    while kill -0 "$GUARDED_PID" 2>/dev/null; do
        if [ "$waited" -ge "$limit" ]; then
            kill "$GUARDED_PID" 2>/dev/null || true
            wait "$GUARDED_PID" 2>/dev/null || true
            GUARDED_PID=""
            return 124
        fi
        sleep 1
        waited=$((waited + 1))
    done
    # No `set +e` / `set -e` pair here: re-enabling errexit inside the
    # function would make the caller's own `set +e` ineffective.
    rc=0
    wait "$GUARDED_PID" || rc=$?
    GUARDED_PID=""
    return "$rc"
}

# fail <message> [<hint>…] — before the VM exists: message, exit 2.
fail() {
    local line
    echo "" >&2
    echo "Chyba: $1" >&2
    shift
    for line in "$@"; do
        echo "       $line" >&2
    done
    exit 2
}

# die <message> [<hint>…] — a phase failed (exit 2): results are collected
# when the VM exists, summary written, VM left in place.
die() {
    local line
    echo "" >&2
    echo "Chyba: $1" >&2
    shift
    for line in "$@"; do
        echo "       $line" >&2
    done
    phase_end failed 2
    FINAL_EXIT=2
    if [ "$VM_EXISTS" = 1 ] && vm_present; then
        collect
    fi
    finish
}

on_interrupt() {
    local phase="$PHASE_ID"
    trap - INT TERM
    INTERRUPTED=1
    if [ -n "$GUARDED_PID" ]; then
        kill "$GUARDED_PID" 2>/dev/null || true
        GUARDED_PID=""
    fi
    echo "" >&2
    echo "==> Přerušeno (Ctrl+C)" >&2
    phase_end interrupted 130
    FINAL_EXIT=130
    if [ -n "$OUT_DIR" ] && [ -d "$OUT_DIR" ]; then
        note "běh přerušen — výsledky jsou neúplné, sběr z VM se neprovedl"
        # Only a running round survives the interrupt as a dashboard job —
        # multipass exec dies, the job in the VM does not. Earlier phases
        # leave nothing running that would be worth mentioning.
        case "$phase" in
            round|eval)
                if [ -n "$DASHBOARD_URL" ]; then
                    note "kolo ve VM může běžet dál jako úloha dashboardu: $DASHBOARD_URL"
                fi
                ;;
        esac
        finish
    fi
    exit 130
}

on_exit() {
    if [ -n "$TMP_DIR" ]; then
        rm -rf "$TMP_DIR"
    fi
}

# ─── Arguments ───────────────────────────────────────────────────────────────

parse_args() {
    local name value
    while [ $# -gt 0 ]; do
        case "$1" in
            --)
                shift
                while [ $# -gt 0 ]; do
                    ROUND_ARGS+=("$1")
                    shift
                done
                break
                ;;
            --keep) KEEP=1 ;;
            -h|--help)
                usage
                exit 0
                ;;
            --branch=*|--image=*|--cpus=*|--memory=*|--disk=*|--out=*|--eval=*|--repeat=*|--max-cost=*|--ai-key-file=*|--eval-dataset=*)
                name="${1%%=*}"
                value="${1#*=}"
                set_option "$name" "$value"
                ;;
            --branch|--image|--cpus|--memory|--disk|--out|--eval|--repeat|--max-cost|--ai-key-file|--eval-dataset)
                if [ $# -lt 2 ]; then
                    fail "volba $1 potřebuje hodnotu (nápověda: --help)."
                fi
                set_option "$1" "$2"
                shift
                ;;
            *)
                fail "neznámá volba '$1' (nápověda: --help)." \
                     "Volby pro shpd-server test-round patří až za '--'."
                ;;
        esac
        shift
    done
}

set_option() {
    if [ -z "$2" ]; then
        fail "volba $1 má prázdnou hodnotu."
    fi
    case "$1" in
        --branch)       BRANCH="$2" ;;
        --image)        IMAGE="$2" ;;
        --cpus)         CPUS="$2" ;;
        --memory)       MEMORY="$2" ;;
        --disk)         DISK="$2" ;;
        --out)          OUT_ROOT="$2" ;;
        --eval)         EVAL_VARIANT="$2" ;;
        --repeat)       EVAL_REPEAT="$2" ;;
        --max-cost)     EVAL_MAX_COST="$2" ;;
        --ai-key-file)  AI_KEY_FILE="$2" ;;
        --eval-dataset) EVAL_DATASETS+=("$2") ;;
    esac
}

validate_args() {
    local branch_re='^[A-Za-z0-9._/-]+$' int_re='^[1-9][0-9]*$' size_re='^[1-9][0-9]*[KMGTkmgt]?[Bb]?$'
    local repeat_re='^[1-5]$' cost_re='^[0-9]+([.][0-9]+)?$' name_re='^[A-Za-z0-9._-]+$'
    local given=0 d

    if [[ ! "$BRANCH" =~ $branch_re ]]; then
        fail "větev '$BRANCH' nevypadá jako název větve (povolené znaky: písmena, číslice, . _ / -)."
    fi
    case "$IMAGE" in
        24.04|26.04) ;;
        *) fail "--image musí být 26.04 nebo 24.04 (zadáno '$IMAGE')." ;;
    esac
    if [[ ! "$CPUS" =~ $int_re ]]; then
        fail "--cpus musí být kladné celé číslo (zadáno '$CPUS')."
    fi
    if [[ ! "$MEMORY" =~ $size_re ]]; then
        fail "--memory čeká velikost jako 8G (zadáno '$MEMORY')."
    fi
    if [[ ! "$DISK" =~ $size_re ]]; then
        fail "--disk čeká velikost jako 30G (zadáno '$DISK')."
    fi

    for d in "$EVAL_VARIANT" "$EVAL_REPEAT" "$EVAL_MAX_COST" "$AI_KEY_FILE"; do
        if [ -n "$d" ]; then
            given=$((given + 1))
        fi
    done
    if [ "$given" -gt 0 ] && [ "$given" -lt 4 ]; then
        fail "evaluace potřebuje všechny čtyři volby spolu: --eval <varianta> --repeat <n> --max-cost <USD> --ai-key-file <soubor>."
    fi
    if [ "$given" -eq 0 ] && [ -n "${EVAL_DATASETS[*]+x}" ]; then
        fail "--eval-dataset má smysl jen spolu s --eval."
    fi
    if [ "$given" -eq 4 ]; then
        if [[ ! "$EVAL_VARIANT" =~ $name_re ]]; then
            fail "--eval '$EVAL_VARIANT' nevypadá jako název varianty (tests/eval/variants)."
        fi
        if [[ ! "$EVAL_REPEAT" =~ $repeat_re ]]; then
            fail "--repeat musí být 1–5 (zadáno '$EVAL_REPEAT')."
        fi
        if [[ ! "$EVAL_MAX_COST" =~ $cost_re ]]; then
            fail "--max-cost musí být číslo v USD (zadáno '$EVAL_MAX_COST')."
        fi
        if [ ! -f "$AI_KEY_FILE" ] || [ ! -r "$AI_KEY_FILE" ]; then
            fail "soubor s AI klíčem nejde přečíst: $AI_KEY_FILE"
        fi
        if [ ! -s "$AI_KEY_FILE" ]; then
            fail "soubor s AI klíčem je prázdný: $AI_KEY_FILE"
        fi
        for d in ${EVAL_DATASETS[@]+"${EVAL_DATASETS[@]}"}; do
            if [[ ! "$d" =~ $name_re ]]; then
                fail "--eval-dataset '$d' nevypadá jako název sady (tests/datasets)."
            fi
        done
    fi
}

# ─── 1. Host preflight ───────────────────────────────────────────────────────
preflight_host() {
    phase_begin preflight "Host preflight"

    if ! command -v multipass >/dev/null 2>&1; then
        fail "chybí multipass v PATH." \
             "Instalace: https://canonical.com/multipass/install"
    fi
    MULTIPASS_VERSION="$(multipass version 2>/dev/null | awk 'NR == 1 {print $2; exit}' || true)"
    if ! command -v curl >/dev/null 2>&1; then
        fail "chybí curl."
    fi

    HOST_ARCH="$(uname -m 2>/dev/null || echo '?')"
    if command -v sw_vers >/dev/null 2>&1; then
        HOST_OS="macOS $(sw_vers -productVersion 2>/dev/null || echo '?')"
    elif [ -r /etc/os-release ]; then
        HOST_OS="$(. /etc/os-release && echo "${PRETTY_NAME:-${ID:-linux}}")"
    else
        HOST_OS="$(uname -sr 2>/dev/null || echo '?')"
    fi

    VM="shipard-round-$(date +%Y%m%d-%H%M%S)"
    OUT_DIR="$OUT_ROOT/$VM"
    if ! mkdir -p "$OUT_DIR" 2>/dev/null || ! touch "$OUT_DIR/.write-test" 2>/dev/null; then
        OUT_DIR=""
        fail "do adresáře výsledků nejde zapisovat: $OUT_ROOT/$VM" \
             "Jiný kořen výsledků: --out <adresář>."
    fi
    rm -f "$OUT_DIR/.write-test"
    NOTES_FILE="$TMP_DIR/notes.txt"
    : > "$NOTES_FILE"
    : > "$PHASES_FILE"

    echo "    OK: $HOST_OS ($HOST_ARCH), multipass ${MULTIPASS_VERSION:-?}, curl."
    echo "    VM: $VM (Ubuntu $IMAGE, $CPUS CPU / $MEMORY / $DISK), větev $BRANCH"
    echo "    Výsledky: $OUT_DIR"
    if [ -n "$EVAL_VARIANT" ]; then
        echo "    Evaluace: $EVAL_VARIANT ×$EVAL_REPEAT, strop $EVAL_MAX_COST USD, klíč ze souboru"
    fi
    phase_end ok 0
}

# ─── 2. cloud-init of the tested branch (U2) ────────────────────────────────
fetch_yaml() {
    local url="$RAW_BASE/$BRANCH/scripts/multipass/shipard-dev.yaml"
    local tmp="$TMP_DIR/shipard-dev.yaml" code c1 c2
    local pat_url='stable/scripts/dev-bootstrap.sh' pat_bash='| bash >'
    local new_url="$BRANCH/scripts/dev-bootstrap.sh" new_bash="| bash -s -- --branch $BRANCH >"
    local changed='shipard-dev.yaml se změnil — uprav scripts/multipass/test-round.sh'

    phase_begin yaml "Cloud-init (shipard-dev.yaml, větev $BRANCH)"

    code="$(curl -sS -o "$tmp" -w '%{http_code}' "$url" 2>/dev/null || true)"
    case "$code" in
        200) ;;
        404)
            die "větev '$BRANCH' na GitHubu neexistuje, nebo v ní není scripts/multipass/shipard-dev.yaml (HTTP 404)." \
                 "$url"
            ;;
        *)
            die "shipard-dev.yaml se nepodařilo stáhnout (HTTP ${code:-bez odpovědi})." \
                 "$url"
            ;;
    esac

    if [ "$BRANCH" = "stable" ]; then
        cp "$tmp" "$OUT_DIR/shipard-dev.yaml"
        echo "    stable: yaml použit beze změny."
    else
        c1="$(grep -c -F -- "$pat_url" "$tmp" || true)"
        c2="$(grep -c -F -- "$pat_bash" "$tmp" || true)"
        if [ "$c1" != 1 ] || [ "$c2" != 1 ]; then
            die "$changed" \
                "Čekám právě jednou '$pat_url' (nalezeno $c1×) a '$pat_bash' (nalezeno $c2×)."
        fi
        sed -e "s#$pat_url#$new_url#" -e "s#$pat_bash#$new_bash#" "$tmp" > "$OUT_DIR/shipard-dev.yaml"
        c1="$(grep -c -F -- "$new_url" "$OUT_DIR/shipard-dev.yaml" || true)"
        c2="$(grep -c -F -- "$new_bash" "$OUT_DIR/shipard-dev.yaml" || true)"
        if [ "$c1" != 1 ] || [ "$c2" != 1 ]; then
            die "$changed" \
                "Po náhradě větve není v yaml právě jednou '$new_url' a '$new_bash'."
        fi
        echo "    Větev $BRANCH: v yaml nahrazen odkaz na dev-bootstrap.sh a přidáno --branch."
    fi
    echo "    Uloženo: $OUT_DIR/shipard-dev.yaml"
    phase_end ok 0
}

# ─── 3. Launch ───────────────────────────────────────────────────────────────
launch_vm() {
    local rc=0

    phase_begin launch "Launch $VM (Ubuntu $IMAGE, --timeout $LAUNCH_TIMEOUT)"
    echo "    multipass launch čeká, dokud cloud-init (bootstrap cestou nováčka) nedoběhne —"
    echo "    desítky minut. Průběh v druhém terminálu:"
    echo "        multipass exec $VM -- tail -f shipard-bootstrap.log"

    set +e
    multipass launch "$IMAGE" --name "$VM" --cpus "$CPUS" --memory "$MEMORY" --disk "$DISK" \
        --timeout "$LAUNCH_TIMEOUT" --cloud-init "$OUT_DIR/shipard-dev.yaml"
    rc=$?
    set -e

    if vm_present; then
        VM_EXISTS=1
        DASHBOARD_URL="http://$(vm_ipv4)/_dev/"
    fi
    if [ "$rc" -ne 0 ]; then
        if [ "$VM_EXISTS" = 1 ]; then
            note "multipass launch skončil kódem $rc, VM ale existuje (timeout?) — pokračuji čekáním na cloud-init"
            phase_end warning "$rc"
            return 0
        fi
        die "multipass launch selhal (exit $rc) a VM $VM neexistuje."
    fi
    phase_end ok 0
}

# ─── 4. cloud-init ───────────────────────────────────────────────────────────
# No long-running `multipass exec` here (N5): `cloud-init status --wait`
# started during the boot hung for half an hour on AVF although it had
# finished inside the VM. Short queries under a watchdog instead; the
# `--long` output and the bootstrap log are read only after cloud-init ended.
wait_cloud_init() {
    local started waited status rc last_rc=0 hung=0 polls=0 unparsed=0 degraded=0
    local short="$TMP_DIR/cloud-init-status.txt"

    phase_begin cloud-init "Waiting for cloud-init (bootstrap cestou nováčka)"
    echo "    Každých $CLOUD_INIT_POLL s dotaz 'cloud-init status' (hlídač $EXEC_GUARD s na dotaz,"
    echo "    celkem nejvýš $(fmt_duration "$CLOUD_INIT_LIMIT")); instalace cestou nováčka trvá minuty až desítky minut."

    started="$(now_epoch)"
    while :; do
        set +e
        vm_exec_guarded "$EXEC_GUARD" "$short" cloud-init status
        rc=$?
        set -e
        if [ "$rc" -eq 124 ]; then
            hung=$((hung + 1))
            status=""
        else
            last_rc="$rc"
            status="$(awk -F': *' '$1 == "status" {print $2; exit}' "$short" 2>/dev/null || true)"
            if [ -z "$status" ]; then
                # Answered, but not with a status line: a VM that is gone, a
                # broken client. Three times in a row is not a boot in progress.
                unparsed=$((unparsed + 1))
                if [ "$unparsed" -ge 3 ]; then
                    sed 's/^/    | /' "$short" >&2
                    die "multipass exec $VM -- cloud-init status opakovaně nevrací stav (výpis výše) — VM zůstává."
                fi
            else
                unparsed=0
            fi
        fi
        case "$status" in
            done|"degraded done"|error*|disabled) break ;;
        esac
        # running, "not started", "degraded running" or no answer: keep waiting.
        waited=$(( $(now_epoch) - started ))
        if [ "$waited" -ge "$CLOUD_INIT_LIMIT" ]; then
            die "cloud-init ve VM nedoběhl do $(fmt_duration "$CLOUD_INIT_LIMIT") (poslední stav: ${status:-bez odpovědi}, dotazů bez odpovědi: $hung) — VM zůstává, průběh: multipass exec $VM -- cat shipard-bootstrap.log"
        fi
        polls=$((polls + 1))
        if [ $((polls % 6)) -eq 0 ]; then
            echo "    … $(fmt_duration "$waited"): ${status:-bez odpovědi}"
        fi
        sleep "$CLOUD_INIT_POLL"
    done

    if [ "$hung" -gt 0 ]; then
        note "cloud-init: $hung dotazů 'multipass exec' bez odpovědi do $EXEC_GUARD s, ukončil je hlídač (AVF, N5)"
    fi

    # Details only now, when the VM is up: `status --long` into cloud-init.txt
    # (the short answer as a fallback) and the newcomer's bootstrap log. The
    # exit code of `status --long` repeats the state (1 error, 2 degraded) —
    # only the watchdog or a missing status line means no answer.
    rc=0
    vm_exec_guarded "$EXEC_GUARD" "$OUT_DIR/cloud-init.txt" cloud-init status --long || rc=$?
    if [ "$rc" -eq 124 ] || ! grep -q '^status:' "$OUT_DIR/cloud-init.txt" 2>/dev/null; then
        cp "$short" "$OUT_DIR/cloud-init.txt" 2>/dev/null || true
        note "cloud-init status --long bez odpovědi — v cloud-init.txt je poslední krátký stav"
    fi
    if ! vm_exec_guarded 120 "$OUT_DIR/bootstrap-1.log" cat shipard-bootstrap.log; then
        note "bootstrap-1.log (~/shipard-bootstrap.log ve VM) se nepodařilo zkopírovat celý"
    fi

    # Degraded: exit 2 of `cloud-init status`, or the extended status of the
    # long form (`status: done` + `extended_status: degraded done`).
    if [ "$last_rc" -eq 2 ] || [ "$status" = "degraded done" ] \
        || grep -q -E '^(extended_)?status: *degraded' "$OUT_DIR/cloud-init.txt" 2>/dev/null; then
        degraded=1
    fi

    case "$status" in
        done|"degraded done")
            if [ "$degraded" = 1 ]; then
                note "cloud-init skončil jako 'degraded' (cloud-init.txt) — pokračuji"
                phase_end warning 0
            else
                echo "    cloud-init: done."
                phase_end ok 0
            fi
            ;;
        *)
            if [ -s "$OUT_DIR/bootstrap-1.log" ]; then
                echo "    Konec bootstrap-1.log:" >&2
                tail -n 20 "$OUT_DIR/bootstrap-1.log" | sed 's/^/    | /' >&2
            fi
            die "cloud-init ve VM skončil stavem '${status:-neznámý}' (cloud-init.txt, bootstrap-1.log)."
            ;;
    esac
}

# ─── 5. Second bootstrap (D2) ────────────────────────────────────────────────
bootstrap_second() {
    phase_begin bootstrap-2 "Second bootstrap (--with-testing)"
    run_logged "$OUT_DIR/bootstrap-2.log" vm_sh 'bash ~/sw/shpd/scripts/dev-bootstrap.sh --with-testing'
    if [ "$RUN_EXIT" -ne 0 ]; then
        die "druhý běh bootstrapu (--with-testing) selhal (exit $RUN_EXIT) — bootstrap-2.log."
    fi
    phase_end ok 0
}

# ─── 6. AI key (D5) — only from the file through STDIN, never in argv ───────
set_ai_key() {
    local rc=0

    phase_begin ai-key "AI key (shpd-server dev-ai-key --set-key, ze souboru)"
    set +e
    vm_exec sudo shpd-server dev-ai-key --set-key < "$AI_KEY_FILE" >> "$OUT_DIR/ai-key.log" 2>&1
    rc=$?
    vm_exec sudo shpd-server dev-ai-key --status >> "$OUT_DIR/ai-key.log" 2>&1
    set -e
    if [ "$rc" -ne 0 ]; then
        die "nastavení AI klíče ve VM selhalo (exit $rc) — ai-key.log."
    fi
    echo "    Klíč uložen ve VM (ai-key.log: jen existence a práva souboru)."
    phase_end ok 0
}

# ─── 7. Test round (U4) ──────────────────────────────────────────────────────
run_round() {
    local args

    phase_begin round "Test round (shpd-server test-round)"
    DASHBOARD_URL="http://$(vm_ipv4)/_dev/"
    echo "    Dashboard VM: $DASHBOARD_URL — kolo jde sledovat živě."
    args="$(quote_args ${ROUND_ARGS[@]+"${ROUND_ARGS[@]}"})"
    run_logged "$OUT_DIR/round.log" vm_sh "cd ~/sw/shpd && shpd-server test-round$args"
    ROUND_EXIT=$RUN_EXIT
    case "$ROUND_EXIT" in
        0) phase_end ok 0 ;;
        2) phase_end failed 2 ;;
        *) phase_end failed "$ROUND_EXIT" ;;
    esac
    echo "    Kolo skončilo exit $ROUND_EXIT."
}

# ─── 8. Evaluation (D5) ──────────────────────────────────────────────────────
run_eval() {
    local args d

    phase_begin eval "Evaluation (test-round --only datasets --eval $EVAL_VARIANT)"
    if [ "$ROUND_EXIT" = 2 ]; then
        note "evaluace přeskočena — první kolo nezačalo (exit 2)"
        phase_end skipped 2
        return 0
    fi
    args=" --only datasets"
    for d in ${EVAL_DATASETS[@]+"${EVAL_DATASETS[@]}"}; do
        args="$args --dataset $(printf '%q' "$d")"
    done
    args="$args --eval $(printf '%q' "$EVAL_VARIANT") --repeat $EVAL_REPEAT --max-cost $EVAL_MAX_COST"
    run_logged "$OUT_DIR/round-eval.log" vm_sh "cd ~/sw/shpd && shpd-server test-round$args"
    EVAL_EXIT=$RUN_EXIT
    case "$EVAL_EXIT" in
        0) phase_end ok 0 ;;
        *) phase_end failed "$EVAL_EXIT" ;;
    esac
    echo "    Evaluace skončila exit $EVAL_EXIT."
}

# ─── 9. Collect (D6) — never aborts; what cannot be fetched is a note ────────
collect() {
    phase_begin collect "Collecting results"
    set +e

    vm_exec shpd-server doctor > "$OUT_DIR/doctor.txt" 2>&1 \
        || note "shpd-server doctor skončil nenulově (doctor.txt)"

    vm_exec tar -czf - -C /opt/shipard/run dev-jobs > "$OUT_DIR/jobs.tgz" 2>"$TMP_DIR/tar.err"
    if [ ! -s "$OUT_DIR/jobs.tgz" ]; then
        note "jobs.tgz se nepodařilo stáhnout (/opt/shipard/run/dev-jobs ve VM): $(head -n 1 "$TMP_DIR/tar.err" 2>/dev/null)"
        rm -f "$OUT_DIR/jobs.tgz"
    elif ! tar -tzf "$OUT_DIR/jobs.tgz" >/dev/null 2>&1; then
        note "jobs.tgz neprošel kontrolou (tar -tzf) — přenos přes multipass exec archiv poškodil"
    fi

    if [ -n "$EVAL_VARIANT" ]; then
        vm_sh 'cd ~/sw/shpd && git ls-files --others --modified --exclude-standard -- tests/eval | tar -czf - -T -' \
            > "$OUT_DIR/eval.tgz" 2>"$TMP_DIR/tar.err"
        if [ ! -s "$OUT_DIR/eval.tgz" ]; then
            note "eval.tgz se nepodařilo stáhnout (tests/eval ve VM): $(head -n 1 "$TMP_DIR/tar.err" 2>/dev/null)"
            rm -f "$OUT_DIR/eval.tgz"
        elif ! tar -tzf "$OUT_DIR/eval.tgz" >/dev/null 2>&1; then
            note "eval.tgz neprošel kontrolou (tar -tzf)"
        fi
    fi

    vm_sh '. /etc/os-release 2>/dev/null; echo "os=${VERSION_ID:-}"; echo "arch=$(uname -m)";
        echo "php=$(php -r "echo PHP_VERSION;" 2>/dev/null)"; echo "mariadb=$(mariadb --version 2>/dev/null)";
        echo "node=$(node -v 2>/dev/null)"; echo "npm=$(npm -v 2>/dev/null)";
        echo "commit=$(git -C ~/sw/shpd rev-parse HEAD 2>/dev/null)";
        echo "branch=$(git -C ~/sw/shpd rev-parse --abbrev-ref HEAD 2>/dev/null)"' \
        > "$GUEST_FILE" 2>/dev/null \
        || note "verze ve VM (PHP, MariaDB, Node, commit) se nepodařilo zjistit"

    set -e
    phase_end ok 0
}

# guest_value <key> — from the key=value lines collected in the VM.
guest_value() {
    awk -F= -v k="$1" '$1 == k {sub(/^[^=]*=/, ""); print; exit}' "$GUEST_FILE" 2>/dev/null || true
}

# ─── 10. Results: environment.json, summary.txt ──────────────────────────────
write_environment() {
    local out="$OUT_DIR/environment.json" first=1 d
    local id title start end dur status code

    {
        printf '{\n'
        printf '  "format": "%s",\n' "$RESULTS_FORMAT"
        printf '  "startedAt": "%s",\n' "$(json_escape "$STARTED_AT")"
        printf '  "finishedAt": "%s",\n' "$(now_iso)"
        printf '  "exitCode": %s,\n' "$(json_number_or_null "$FINAL_EXIT")"
        printf '  "roundExit": %s,\n' "$(json_number_or_null "$ROUND_EXIT")"
        printf '  "evalExit": %s,\n' "$(json_number_or_null "$EVAL_EXIT")"
        printf '  "interrupted": %s,\n' "$([ "$INTERRUPTED" = 1 ] && echo true || echo false)"
        printf '  "host": {"os": "%s", "arch": "%s", "multipass": "%s"},\n' \
            "$(json_escape "$HOST_OS")" "$(json_escape "$HOST_ARCH")" "$(json_escape "$MULTIPASS_VERSION")"
        printf '  "vm": {"name": "%s", "image": "%s", "cpus": %s, "memory": "%s", "disk": "%s", "branch": "%s", "deleted": %s, "dashboard": "%s"},\n' \
            "$(json_escape "$VM")" "$(json_escape "$IMAGE")" "$(json_number_or_null "$CPUS")" \
            "$(json_escape "$MEMORY")" "$(json_escape "$DISK")" "$(json_escape "$BRANCH")" \
            "$([ "$VM_DELETED" = 1 ] && echo true || echo false)" "$(json_escape "$DASHBOARD_URL")"
        printf '  "guest": {"osVersion": "%s", "arch": "%s", "php": "%s", "mariadb": "%s", "node": "%s", "npm": "%s", "commit": "%s", "branch": "%s"},\n' \
            "$(json_escape "$(guest_value os)")" "$(json_escape "$(guest_value arch)")" \
            "$(json_escape "$(guest_value php)")" "$(json_escape "$(guest_value mariadb)")" \
            "$(json_escape "$(guest_value node)")" "$(json_escape "$(guest_value npm)")" \
            "$(json_escape "$(guest_value commit)")" "$(json_escape "$(guest_value branch)")"
        printf '  "roundArgs": ['
        for d in ${ROUND_ARGS[@]+"${ROUND_ARGS[@]}"}; do
            if [ "$first" = 1 ]; then first=0; else printf ', '; fi
            printf '"%s"' "$(json_escape "$d")"
        done
        printf '],\n'
        if [ -n "$EVAL_VARIANT" ]; then
            printf '  "eval": {"variant": "%s", "repeat": %s, "maxCostUsd": %s, "datasets": [' \
                "$(json_escape "$EVAL_VARIANT")" "$(json_number_or_null "$EVAL_REPEAT")" "$(json_number_or_null "$EVAL_MAX_COST")"
            first=1
            for d in ${EVAL_DATASETS[@]+"${EVAL_DATASETS[@]}"}; do
                if [ "$first" = 1 ]; then first=0; else printf ', '; fi
                printf '"%s"' "$(json_escape "$d")"
            done
            printf ']},\n'
        else
            printf '  "eval": null,\n'
        fi
        printf '  "phases": [\n'
        first=1
        while IFS=$'\t' read -r id title start end dur status code; do
            if [ "$first" = 1 ]; then first=0; else printf ',\n'; fi
            printf '    {"id": "%s", "title": "%s", "startedAt": "%s", "endedAt": "%s", "durationSec": %s, "status": "%s", "exitCode": %s}' \
                "$(json_escape "$id")" "$(json_escape "$title")" "$start" "$end" \
                "$(json_number_or_null "$dur")" "$(json_escape "$status")" "$(json_number_or_null "$code")"
        done < "$PHASES_FILE"
        printf '\n  ],\n'
        printf '  "notes": ['
        first=1
        while IFS= read -r d; do
            if [ "$first" = 1 ]; then first=0; else printf ', '; fi
            printf '"%s"' "$(json_escape "$d")"
        done < "$NOTES_FILE"
        printf ']\n'
        printf '}\n'
    } > "$out"
}

exit_text() {
    case "$1" in
        0)   echo "0 — vše prošlo" ;;
        1)   echo "1 — selhání, přeskočený krok nebo strop útraty" ;;
        2)   echo "2 — nezačalo" ;;
        130) echo "130 — přerušeno" ;;
        *)   echo "$1" ;;
    esac
}

write_summary() {
    local id title start end dur status code line
    {
        echo ""
        echo "==> Souhrn"
        echo "    VM:         $VM (Ubuntu $IMAGE, $CPUS CPU / $MEMORY / $DISK), větev $BRANCH"
        echo "    Hostitel:   $HOST_OS ($HOST_ARCH), multipass ${MULTIPASS_VERSION:-?}"
        if [ -s "$GUEST_FILE" ]; then
            echo "    Ve VM:      Ubuntu $(guest_value os) ($(guest_value arch)), PHP $(guest_value php), Node $(guest_value node), commit $(guest_value commit | cut -c1-12) ($(guest_value branch))"
        fi
        echo "    Výsledky:   $OUT_DIR"
        echo "    Fáze:"
        while IFS=$'\t' read -r id title start end dur status code; do
            printf '      %-12s %s  %8s  %s%s\n' "$id" "${start#*T}" "$(fmt_duration "$dur")" "$status" \
                "$([ -n "$code" ] && [ "$code" != 0 ] && echo " (exit $code)")"
        done < "$PHASES_FILE"
        if [ -n "$ROUND_EXIT" ]; then
            echo "    Kolo:       exit $(exit_text "$ROUND_EXIT")"
        else
            echo "    Kolo:       neproběhlo"
        fi
        if [ -n "$EVAL_VARIANT" ]; then
            if [ -n "$EVAL_EXIT" ]; then
                echo "    Evaluace:   exit $(exit_text "$EVAL_EXIT")"
            else
                echo "    Evaluace:   neproběhla"
            fi
        fi
        if [ -s "$NOTES_FILE" ]; then
            echo "    Poznámky:"
            while IFS= read -r line; do
                echo "      - $line"
            done < "$NOTES_FILE"
        fi
        if [ "$VM_EXISTS" != 1 ]; then
            echo "    VM:         nevznikla."
        elif [ "$VM_DELETED" = 1 ]; then
            echo "    VM:         smazána (multipass delete --purge $VM)."
        else
            if [ "$KEEP" = 1 ] && [ "$FINAL_EXIT" = 0 ]; then
                echo "    VM:         zůstává (--keep)."
            else
                echo "    VM:         zůstává kvůli selhání nebo přerušení."
            fi
            if [ -n "$DASHBOARD_URL" ]; then
                echo "      dashboard:  $DASHBOARD_URL"
            fi
            echo "      terminál:   multipass shell $VM"
            echo "      smazání:    multipass delete --purge $VM"
        fi
        echo "    Exit:       $(exit_text "$FINAL_EXIT")"
        echo ""
    } | tee "$OUT_DIR/summary.txt"
}

# ─── 11. Finish: exit code, VM fate (D7), results, exit ──────────────────────
compute_exit() {
    if [ -n "$FINAL_EXIT" ]; then
        return 0
    fi
    if [ "${ROUND_EXIT:-2}" = 2 ] || [ "${EVAL_EXIT:-0}" = 2 ]; then
        FINAL_EXIT=2
    elif [ "${ROUND_EXIT:-1}" = 0 ] && [ "${EVAL_EXIT:-0}" = 0 ]; then
        FINAL_EXIT=0
    else
        FINAL_EXIT=1
    fi
}

finish() {
    compute_exit
    if [ "$VM_EXISTS" = 1 ] && [ "$FINAL_EXIT" = 0 ] && [ "$KEEP" != 1 ]; then
        echo ""
        echo "==> Cleanup (multipass delete --purge $VM)"
        if multipass delete --purge "$VM"; then
            VM_DELETED=1
        else
            note "smazání VM selhalo — smaž ji ručně: multipass delete --purge $VM"
        fi
    fi
    write_environment
    write_summary
    exit "$FINAL_EXIT"
}

main() {
    parse_args "$@"
    validate_args
    trap on_exit EXIT
    TMP_DIR="$(mktemp -d)"
    PHASES_FILE="$TMP_DIR/phases.tsv"
    GUEST_FILE="$TMP_DIR/guest.env"
    NOTES_FILE=""
    STARTED_AT="$(now_iso)"
    trap on_interrupt INT TERM

    preflight_host
    fetch_yaml
    launch_vm
    wait_cloud_init
    bootstrap_second
    if [ -n "$EVAL_VARIANT" ]; then
        set_ai_key
    fi
    run_round
    if [ -n "$EVAL_VARIANT" ]; then
        run_eval
    fi
    collect
    finish
}

main "$@"
