"""Ověření identit korpusu v registrech (síť): ARES — IČO neexistuje a název
má nula zásahů; VIES — DIČ je neplatné. Spouští se jen
`build.py --verify-identities` po změně identit (tasks/testing-phase1.md).

`fetch` je injektovatelný kvůli testům: (url, method, body, headers) → (status, body).
Nedostupný registr = RegistryUnavailable — identity se neoznačí jako ověřené.
"""

from __future__ import annotations

import json
import re
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Callable

ARES_BASE = "https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty"
VIES_BASE = "https://ec.europa.eu/taxation_customs/vies/rest-api/ms"
USER_AGENT = "shipard-invoice-generator/1 (tests/corpus; +https://github.com/shipard)"
VIES_UNAVAILABLE = frozenset({
    "MS_UNAVAILABLE", "MS_MAX_CONCURRENT_REQ", "SERVICE_UNAVAILABLE", "TIMEOUT",
    "GLOBAL_MAX_CONCURRENT_REQ", "MS_MAX_CONCURRENT_REQ_TIME", "GLOBAL_MAX_CONCURRENT_REQ_TIME",
})

Fetch = Callable[[str, str, bytes | None, dict[str, str]], tuple[int, bytes]]


class RegistryUnavailable(Exception):
    pass


@dataclass(frozen=True)
class Check:
    subject: str   # klíč identity (profiles.vat-payer, suppliers.hosting)
    kind: str      # "ARES IČO" | "ARES název" | "VIES"
    value: str
    ok: bool
    detail: str


def urllib_fetch(url: str, method: str = "GET", body: bytes | None = None,
                 headers: dict[str, str] | None = None, *, timeout: int = 20) -> tuple[int, bytes]:
    request = urllib.request.Request(url, data=body, method=method,
                                     headers={"User-Agent": USER_AGENT, "Accept": "application/json",
                                              **(headers or {})})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        raise RegistryUnavailable(f"{url}: {getattr(e, 'reason', e)}") from e


def _unavailable_status(status: int, url: str, body: bytes) -> None:
    if status == 429 or status >= 500:
        raise RegistryUnavailable(f"{url}: HTTP {status} {body[:200].decode('utf-8', 'replace')}")


def ares_ico_exists(ico: str, fetch: Fetch = urllib_fetch) -> bool:
    url = f"{ARES_BASE}/{ico}"
    status, body = fetch(url, "GET", None, {})
    if status == 404:
        return False
    if status == 200:
        return True
    if status == 400:
        raise ValueError(f"ARES odmítl IČO {ico}: {body[:200].decode('utf-8', 'replace')}")
    _unavailable_status(status, url, body)
    raise RegistryUnavailable(f"{url}: neočekávaný HTTP {status}")


def ares_name_hits(name: str, fetch: Fetch = urllib_fetch) -> int:
    url = f"{ARES_BASE}/vyhledat"
    payload = json.dumps({"obchodniJmeno": name, "start": 0, "pocet": 1}).encode("utf-8")
    status, body = fetch(url, "POST", payload, {"Content-Type": "application/json"})
    if status == 200:
        return int(json.loads(body)["pocetCelkem"])
    if status == 400:
        raise ValueError(f"ARES odmítl hledání názvu {name!r}: {body[:200].decode('utf-8', 'replace')}")
    _unavailable_status(status, url, body)
    raise RegistryUnavailable(f"{url}: neočekávaný HTTP {status}")


def vies_vat_valid(vat_id: str, fetch: Fetch = urllib_fetch) -> bool:
    url = f"{VIES_BASE}/{vat_id[:2]}/vat/{vat_id[2:]}"
    status, body = fetch(url, "GET", None, {})
    _unavailable_status(status, url, body)
    if status != 200:
        raise RegistryUnavailable(f"{url}: neočekávaný HTTP {status}")
    answer = json.loads(body)
    error = answer.get("userError") or "VALID"
    if error in VIES_UNAVAILABLE:
        raise RegistryUnavailable(f"{url}: {error}")
    if error == "INVALID_INPUT":
        raise ValueError(f"VIES odmítl tvar DIČ {vat_id}")
    if error not in ("VALID", "INVALID"):  # INVALID = dotaz prošel, číslo neexistuje
        raise RegistryUnavailable(f"{url}: {error}")
    return bool(answer.get("isValid"))


def verify_identities(identities: dict, fetch: Fetch = urllib_fetch,
                      sleep: Callable[[float], None] = time.sleep, pause: float = 0.3) -> list[Check]:
    """Každá identita: ARES IČO (očekává se 404), ARES název (0 zásahů), VIES
    pro každé DIČ (očekává se neplatné). Nedostupný registr = výjimka."""
    checks: list[Check] = []
    parties = [(f"{section}.{key}", party)
               for section in ("profiles", "suppliers")
               for key, party in (identities.get(section) or {}).items()]
    for subject, party in parties:
        name = party["name"]
        if party.get("country") == "cz" and party.get("companyId"):
            ico = party["companyId"]
            try:
                exists = ares_ico_exists(ico, fetch)
                checks.append(Check(subject, "ARES IČO", ico, not exists, "existuje" if exists else "volné"))
            except ValueError as e:
                checks.append(Check(subject, "ARES IČO", ico, False, str(e)))
            sleep(pause)
            try:
                hits = ares_name_hits(name, fetch)
                checks.append(Check(subject, "ARES název", name, hits == 0, f"{hits} zásahů"))
            except ValueError as e:
                checks.append(Check(subject, "ARES název", name, False, str(e)))
            sleep(pause)
        vat_id = party.get("vatId")
        if vat_id:
            try:
                valid = vies_vat_valid(vat_id, fetch)
                checks.append(Check(subject, "VIES", vat_id, not valid, "platné" if valid else "neplatné"))
            except ValueError as e:
                checks.append(Check(subject, "VIES", vat_id, False, str(e)))
            sleep(pause)
    return checks


def format_check(check: Check) -> str:
    mark = "OK " if check.ok else "FAIL"
    return f"{mark} {check.kind} {check.value} ({check.subject}) … {check.detail}"


def update_verified_at(jsonc_text: str, iso_date: str) -> str:
    """Přepíše jen řádek "verifiedAt" (komentáře zůstanou); právě jeden zásah."""
    pattern = re.compile(r'^(\s*"verifiedAt":\s*")[0-9]{4}-[0-9]{2}-[0-9]{2}(")', re.MULTILINE)
    text, count = pattern.subn(lambda m: m.group(1) + iso_date + m.group(2), jsonc_text)
    if count != 1:
        raise ValueError(f"verifiedAt: očekáván právě jeden řádek, nalezeno {count}")
    return text
