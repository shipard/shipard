"""Zápis datové sady `shpd.dataset.v1` tak, jak ji vypisuje PHP
(`DatasetWriter`, `DatasetManifest`, `Slug::make`) — sdílené korpusem
(`dataset.py`) i demo sadami (`demo.py`), bez vazby na korpus.

Sada je po seedu a `dataset-dump` bajtově shodná, proto se tu drží tvar
PHP přesně: 4 mezery, UTF-8 bez escapování, `/` bez escapování, `\\n` na
konci, čísla jen `Decimal` / `int` (hodnoty sloupců přes
`PRESERVE_ZERO_FRACTION`: `1.0` zůstává; bloby analýz přes
`php_json_numbers`: `1.0` → `1`), názvy souborů `NNNN-<slug>.jsonc`.
"""

from __future__ import annotations

import hashlib
import json
import re
import shutil
from dataclasses import dataclass
from decimal import Decimal
from pathlib import Path

DATASET_FORMAT = "shpd.dataset.v1"
SETUP_FORMAT = "shpd.dataset.setup.v1"
MESSAGE_FORMAT = "shpd.mail.incomingMessage"
PERSON_FORMAT = "shpd.persons.person"

NAME_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
CREATED_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
# Pořadí sekcí jako `DatasetManifest::SECTIONS` / `DatasetDumper` (counts v dumpu).
SECTIONS = ("setup", "exchangeRates", "persons", "items", "workOrders", "assets", "docs", "bank", "registry", "mail")
# Pořadí jako `SetupExporter::TABLES`; `accounts` = nesystémové účty osnovy (#131 fáze 2a),
# `work_order_kinds` / `work_order_series` = druhy a řady zakázek (fáze 2b, D29),
# `asset_*` = účetní skupiny, skupiny typů a typy majetku (fáze 2c, D39).
SETUP_TABLES = ("settings", "accounts", "bank_accounts", "cash_desks", "cost_centers", "vat_registrations", "binders", "mailboxes",
                "work_order_kinds", "work_order_series", "asset_accounting_groups", "asset_type_groups", "asset_types")
PROVISION_KEYS = ("accountingItems",)   # serverAi zaniklo (#126 D9) — serverové AI plyne z třídy dat

# Port Shipard\Core\Utils\Slug::make — stejná tabulka, stejný regex.
_TRANSLIT = {
    "á": "a", "č": "c", "ď": "d", "é": "e", "ě": "e", "í": "i", "ň": "n",
    "ó": "o", "ř": "r", "š": "s", "ť": "t", "ú": "u", "ů": "u", "ý": "y",
    "ž": "z", "ä": "a", "ľ": "l", "ĺ": "l", "ô": "o", "ŕ": "r", "ö": "o",
    "ü": "u", "ß": "ss", "ł": "l", "ą": "a", "ę": "e", "ś": "s", "ź": "z",
    "ż": "z", "ć": "c", "ń": "n", "à": "a", "è": "e", "ì": "i", "ò": "o",
    "ù": "u", "â": "a", "ê": "e", "î": "i", "û": "u", "ç": "c",
    "ñ": "n", "ø": "o", "å": "a", "æ": "ae", "œ": "oe",
}


class DatasetBuildError(ValueError):
    pass


def slug(text: str, max_length: int = 60, fallback: str = "record") -> str:
    ascii_ = "".join(_TRANSLIT.get(ch, ch) for ch in text.lower())
    ascii_ = re.sub(r"[^a-z0-9]+", "-", ascii_).strip("-")
    if len(ascii_) > max_length:
        ascii_ = ascii_[:max_length].rstrip("-")
    return ascii_ or fallback


def file_name(ordinal: int, key: str, ext: str = ".jsonc") -> str:
    """`DatasetWriter::fileName`: čtyřmístné pořadí + slug přirozeného klíče."""
    return f"{ordinal:04d}-{slug(key)}{ext}"


# ── Zápis JSON jako DatasetWriter (PHP) ──────────────────────────────────────

def _dump_decimal(value: Decimal) -> str:
    """`DatasetWriter` (`PRESERVE_ZERO_FRACTION`): číslo s desetinnou
    tečkou je float (`486.50` → `486.5`, `1.0` → `1.0`), bez ní int."""
    if value == 0:
        value = abs(value)
    if value.as_tuple().exponent >= 0:
        return str(int(value))
    return repr(float(value))


def php_json_numbers(value):
    """Čísla jako po `json_decode` → `json_encode` bez
    `PRESERVE_ZERO_FRACTION` (MailSeeder ukládá bloby analýzy takto):
    celá hodnota je int (`2340.00` → `2340`, `1.0` → `1`), ostatní float.
    Vrací kopii; na bloby `analysisJson` / `canonicalJson`."""
    if isinstance(value, Decimal):
        return int(value) if value == value.to_integral_value() else value
    if isinstance(value, dict):
        return {k: php_json_numbers(v) for k, v in value.items()}
    if isinstance(value, list):
        return [php_json_numbers(v) for v in value]
    return value


def _dump_str(value: str) -> str:
    out = json.dumps(value, ensure_ascii=False)
    # PHP escapuje U+2028/2029 i s JSON_UNESCAPED_UNICODE.
    return out.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")


def _dump(value, level: int) -> str:
    pad = "    " * level
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, Decimal):
        return _dump_decimal(value)
    if isinstance(value, float):
        raise TypeError("float v datové sadě — čísla jen Decimal / int")
    if isinstance(value, str):
        return _dump_str(value)
    if isinstance(value, dict):
        if not value:
            return "{}"
        inner = ",\n".join(f"{pad}    {_dump_str(str(k))}: {_dump(v, level + 1)}" for k, v in value.items())
        return "{\n" + inner + "\n" + pad + "}"
    if isinstance(value, (list, tuple)):
        if not value:
            return "[]"
        inner = ",\n".join(f"{pad}    {_dump(v, level + 1)}" for v in value)
        return "[\n" + inner + "\n" + pad + "]"
    raise TypeError(f"nepodporovaný typ {type(value).__name__}")


def dumps_dataset(value) -> str:
    """JSON jako `DatasetWriter::encode()`: 4 mezery, UTF-8 bez escapování,
    `/` bez escapování, `\\n` na konci."""
    return _dump(value, 0) + "\n"


def prune(value):
    """`ValueNormalizer::prune()`: null a prázdná pole pryč (rekurzivně).
    Na snapshot analýzy (`analysisJson`, `canonicalJson`) se nepoužívá."""
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            v = prune(v)
            if v is None or v == [] or v == {}:
                continue
            out[k] = v
        return out
    if isinstance(value, list):
        return [prune(v) for v in value if v is not None]
    return value


# ── Manifest a přílohy ───────────────────────────────────────────────────────

def manifest(*, name: str, title: str, description: str | None, created: str, counts: dict[str, int],
             country: str | None = None, profile: str | None = None, provision: dict | None = None) -> dict:
    """`DatasetManifest::toArray()`: pořadí klíčů format, name, title,
    [description], dateMode, created, [country], [profile], [provision],
    counts. `counts` se zapisují v předaném pořadí (dump: pořadí sekcí
    `SECTIONS` včetně nul)."""
    out: dict = {"format": DATASET_FORMAT, "name": name, "title": title}
    if description is not None:
        out["description"] = description
    out["dateMode"] = "fixed"
    out["created"] = created
    if country is not None:
        out["country"] = country
    if profile is not None:
        out["profile"] = profile
    if provision:
        out["provision"] = provision
    out["counts"] = dict(counts)
    return out


def attachment_entry(name: str, data: bytes, order: int | None = None, *,
                     mime_type: str = "application/pdf") -> dict:
    """Položka `attachments[]` jak ji vypíše exportér: `file` = `name`
    (dump pojmenuje soubor uloženým názvem), velikost a SHA-256 z bajtů,
    `order` jen tam, kde ho formát má (zprávy ano, spisovna ne)."""
    entry = {
        "file": name,
        "name": name,
        "mimeType": mime_type,
        "size": len(data),
        "sha256": hashlib.sha256(data).hexdigest(),
    }
    if order is not None:
        entry["order"] = order
    return entry


# ── Zápis do cílové složky ───────────────────────────────────────────────────

@dataclass(frozen=True)
class SyncResult:
    written: tuple[str, ...]
    unchanged: tuple[str, ...]
    removed: tuple[str, ...]


def clear_managed(out_dir: Path, names: tuple[str, ...]) -> None:
    """Smaže spravované položky (soubor i složku) v cílové složce."""
    for name in names:
        target = out_dir / name
        if target.is_dir():
            shutil.rmtree(target)
        elif target.exists():
            target.unlink()


def _check_rel(rel: str, managed: tuple[str, ...]) -> None:
    parts = Path(rel).parts
    if not parts or Path(rel).is_absolute() or ".." in parts:
        raise DatasetBuildError(f"neplatná relativní cesta v sadě: {rel!r}")
    if parts[0] not in managed:
        raise DatasetBuildError(f"{rel}: cesta mimo spravované položky ({', '.join(managed)})")


def sync_tree(out_dir: Path, files: dict[str, bytes], *, managed: tuple[str, ...]) -> SyncResult:
    """Srovná cílovou složku s `files` (relativní cesta → bajty): zapíše
    jen soubory s jinými bajty, pod spravovanými položkami smaže vše, co
    ve `files` není, a odstraní prázdné složky. Nespravované soubory
    (např. `expected/` korpusu) nechává. Druhý běh beze změny = nic
    nezapíše — PDF z Gotenbergu tak přežijí, dokud se nezmění obsah."""
    for rel in files:
        _check_rel(rel, managed)

    written: list[str] = []
    unchanged: list[str] = []
    for rel in sorted(files):
        path = out_dir / rel
        data = files[rel]
        if path.is_file() and path.read_bytes() == data:
            unchanged.append(rel)
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        written.append(rel)

    removed: list[str] = []
    for name in managed:
        target = out_dir / name
        if target.is_file():
            if name not in files:
                target.unlink()
                removed.append(name)
            continue
        if not target.is_dir():
            continue
        for path in sorted(p for p in target.rglob("*") if p.is_file()):
            rel = path.relative_to(out_dir).as_posix()
            if rel not in files:
                path.unlink()
                removed.append(rel)
        # Prázdné složky zdola nahoru (sidecar `.files/` smazané přílohy, celá sekce).
        for directory in sorted((p for p in target.rglob("*") if p.is_dir()), key=lambda p: len(p.parts), reverse=True):
            if not any(directory.iterdir()):
                directory.rmdir()
        if not any(target.iterdir()):
            target.rmdir()

    return SyncResult(written=tuple(written), unchanged=tuple(unchanged), removed=tuple(removed))
