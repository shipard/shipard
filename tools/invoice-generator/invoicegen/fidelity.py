"""Věrnost tisku korpusu (#103 D105): identifikátory z očekávaného výsledku
musí být v textu vygenerovaného PDF. Papírová projekce (D14,
`docs/test-corpus.md` § 8) říká `null` = na dokladu není — tedy ne-`null`
hodnota, kterou PDF nenese, je chyba sady, ne modelu.

Text PDF dává `pdftotext` (poppler-utils, bez `-layout` — sloupce stránky
jdou za sebou a hodnota se nemíchá s druhým sloupcem). Porovnává se bez
bílých znaků: IBAN je na PDF po čtveřicích, telefon s mezerami a dlouhá
hodnota může být zalomená přes řádek.
"""

from __future__ import annotations

import re
import subprocess
from collections import Counter
from dataclasses import dataclass
from pathlib import Path

__all__ = ["IDENTIFIER_PATHS", "FidelityError", "Finding", "identifier_values", "missing_identifiers",
           "pdf_text", "squash", "summarize"]

# Cesty projekce, jejichž ne-`null` hodnota musí být v textu PDF doslova.
# `rows[]` = každý řádek dokladu. Pole bez pevného tvaru (názvy, adresy,
# částky) se nekontrolují — tisknou se formátovaně a jinde.
IDENTIFIER_PATHS: tuple[str, ...] = (
    "docNumber",
    "supplier.companyId", "supplier.vatId", "supplier.courtRegistration",
    "supplier.contact.email", "supplier.contact.phone", "supplier.contact.web",
    "supplier.bankAccount.accountNumber", "supplier.bankAccount.iban", "supplier.bankAccount.bic",
    "payment.paymentReference", "payment.constantSymbol", "payment.specificSymbol",
    "customer.companyId", "customer.vatId",
    "rows[].item.supplierCode", "rows[].item.ean", "rows[].item.description",
)

_WHITESPACE = re.compile(r"\s+")
_INDEX = re.compile(r"\[[0-9]+\]")


class FidelityError(RuntimeError):
    """Text PDF nejde získat (chybí `pdftotext`, poškozené PDF)."""


@dataclass(frozen=True)
class Finding:
    path: str   # cesta v očekávání s indexem řádku: `rows[3].item.ean`
    value: str  # hodnota z očekávání, jak je v JSON

    @property
    def node(self) -> str:
        """Cesta bez indexů (`rows[].item.ean`) — klíč souhrnu."""
        return _INDEX.sub("[]", self.path)


def squash(text: str) -> str:
    """Text bez bílých znaků (vč. NBSP a úzké mezery) — tvar pro porovnání."""
    return _WHITESPACE.sub("", text)


def _get(node, path: str):
    for key in path.split("."):
        if not isinstance(node, dict):
            return None
        node = node.get(key)
    return node


def identifier_values(expected: dict) -> list[tuple[str, str]]:
    """Dvojice (cesta s indexem, hodnota) pro každou ne-`null` hodnotu
    z `IDENTIFIER_PATHS` v očekávaném výsledku, v pořadí cest a řádků."""
    values: list[tuple[str, str]] = []
    for path in IDENTIFIER_PATHS:
        if path.startswith("rows[]."):
            for index, row in enumerate(expected.get("rows") or []):
                value = _get(row, path[len("rows[]."):])
                if value is not None:
                    values.append((f"rows[{index}]." + path[len("rows[]."):], str(value)))
        else:
            value = _get(expected, path)
            if value is not None:
                values.append((path, str(value)))
    return values


def missing_identifiers(expected: dict, text: str) -> list[Finding]:
    """Hodnoty očekávání, které v textu PDF (po odstranění bílých znaků) nejsou."""
    haystack = squash(text)
    return [Finding(path, value) for path, value in identifier_values(expected)
            if squash(value) not in haystack]


def summarize(findings: list[Finding]) -> Counter[str]:
    """Počet nálezů po cestě bez indexů, nejčastější první."""
    return Counter(finding.node for finding in findings)


def pdf_text(path: Path, *, command: str = "pdftotext") -> str:
    """Text PDF přes `pdftotext` (bez `-layout`). Chybějící nástroj nebo
    selhání = `FidelityError`."""
    try:
        result = subprocess.run([command, str(path), "-"], capture_output=True, text=True, check=False)
    except FileNotFoundError as e:
        raise FidelityError(f"{command} není k dispozici — nainstaluj poppler-utils") from e
    if result.returncode != 0:
        raise FidelityError(f"{command} {path.name} skončil s kódem {result.returncode}: "
                            + (result.stderr or result.stdout).strip())
    return result.stdout
