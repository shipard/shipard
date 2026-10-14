"""Formátování hodnot pro tisk, skládání HTML ze šablony s placeholdery
`{{key}}` a render PDF přes Gotenberg (Chromium, docs/render.md).

Na papír se hodnoty tisknou tak, jak je tisknou skutečné doklady (PSČ
s mezerou, IBAN po čtveřicích, telefon s mezerami) — kanonický tvar
nese očekávaný výsledek (tasks/testing-phase1.md, Tvary hodnot).
"""

from __future__ import annotations

import hashlib
import html
import json
import os
import re
import urllib.error
import urllib.request
import uuid
from datetime import date
from decimal import Decimal, ROUND_HALF_UP

from .compute import CENTS

GOTENBERG_URL = os.environ.get("GOTENBERG_URL", "http://10.199.6.210:3000")

NNBSP = " "  # úzká nezlomitelná mezera pro tisíce

UNIT_DISPLAY = {"m2": "m²", "měs": "měs."}


# ── Formátování ──────────────────────────────────────────────────────────────

def fmt_money(value: Decimal, locale: str = "cs") -> str:
    s = f"{value.quantize(CENTS, rounding=ROUND_HALF_UP):,.2f}"
    if locale == "en":
        return s
    return s.replace(",", NNBSP).replace(".", ",")


def fmt_qty(value: Decimal, locale: str = "cs") -> str:
    normalized = value.normalize()
    if normalized == normalized.to_integral_value():
        return str(int(normalized))
    s = format(normalized, "f")
    return s if locale == "en" else s.replace(".", ",")


def fmt_date(iso: str, locale: str = "cs") -> str:
    d = date.fromisoformat(iso)
    if locale == "en":
        return d.isoformat()
    return f"{d.day}. {d.month}. {d.year}"


def fmt_pct(pct: int, locale: str = "cs") -> str:
    return f"{pct}%" if locale == "en" else f"{pct}{NNBSP}%"


def fmt_iban(iban: str) -> str:
    return " ".join(iban[i:i + 4] for i in range(0, len(iban), 4))


def fmt_zip(zip_code: str, country: str) -> str:
    if country in ("cz", "sk") and re.fullmatch(r"[0-9]{5}", zip_code):
        return zip_code[:3] + " " + zip_code[3:]
    return zip_code


def fmt_phone(phone: str) -> str:
    """`+420577123456` → `+420 577 123 456`; `+49…` → `+49 …`; `+1…` → `+1 …`."""
    m = re.fullmatch(r"\+([0-9]+)", phone)
    if not m:
        return phone
    digits = m.group(1)
    cc_len = 1 if digits.startswith("1") else 2 if digits[:2] in ("49", "43", "44", "33", "39", "31") else 3
    cc, rest = digits[:cc_len], digits[cc_len:]
    groups = [rest[i:i + 3] for i in range(0, len(rest), 3)]
    if len(groups) > 1 and len(groups[-1]) == 1:
        groups[-2:] = [groups[-2] + groups[-1]]
    return "+" + cc + " " + " ".join(groups)


# ── HTML ─────────────────────────────────────────────────────────────────────

def esc(value: str) -> str:
    return html.escape(str(value), quote=False)


def render_rows(computed: dict, *, locale: str = "cs",
                unit_display: dict[str, str] | None = UNIT_DISPLAY,
                discount_column: bool = False, vat_column: bool = True) -> str:
    """Řádky položek `<tr class="row">`. Výchozí volání = demo (bajtově shodné).

    `unit_display=None` tiskne jednotku doslova; `discount_column` přidá
    `<td class="c-disc">` za cenu/MJ; `vat_column=False` vynechá sloupec DPH
    (doklad bez DPH).
    """
    units = unit_display or {}
    parts = []
    for row in computed["rows"]:
        unit = units.get(row["unit"], row["unit"])
        cells = [
            f'<td class="c-text">{esc(row["text"])}</td>',
            f'<td class="c-qty">{fmt_qty(row["qty"], locale)}</td>',
            f'<td class="c-unit">{esc(unit)}</td>',
            f'<td class="c-price">{fmt_money(row["unitPrice"], locale)}</td>',
        ]
        if discount_column:
            disc = row.get("discountPct")
            cells.append(f'<td class="c-disc">{fmt_qty(disc, locale) + fmt_pct_suffix(locale) if disc is not None else ""}</td>')
        if vat_column:
            cells.append(f'<td class="c-vat">{fmt_pct(row["vatPct"], locale)}</td>')
        cells.append(f'<td class="c-total">{fmt_money(row["total"], locale)}</td>')
        parts.append('\t\t\t<tr class="row">' + "".join(cells) + "</tr>")
    return "\n".join(parts)


def fmt_pct_suffix(locale: str) -> str:
    return "%" if locale == "en" else f"{NNBSP}%"


def render_vat_recap(computed: dict, *, locale: str = "cs") -> str:
    parts = []
    for pct, r in computed["recap"].items():
        parts.append(
            '\t\t\t<tr class="vr">'
            f'<td class="v-pct">{fmt_pct(pct, locale)}</td>'
            f'<td class="v-base">{fmt_money(r["base"], locale)}</td>'
            f'<td class="v-tax">{fmt_money(r["tax"], locale)}</td>'
            f'<td class="v-total">{fmt_money(r["total"], locale)}</td>'
            "</tr>"
        )
    return "\n".join(parts)


def render_kv_rows(items: list[tuple[str, str]]) -> str:
    """Řádky popisek / hodnota pro meta tabulky: `<tr><td class="l">…</td><td class="v">…</td></tr>`.
    Popisky i hodnoty už jako HTML (volající escapuje)."""
    return "\n".join(
        f'\t\t\t<tr><td class="l">{label}</td><td class="v">{value}</td></tr>' for label, value in items
    )


def render_html(template: str, values: dict[str, str]) -> str:
    def repl(match: re.Match) -> str:
        key = match.group(1)
        if key not in values:
            raise KeyError(f"šablona chce neznámý placeholder {{{{{key}}}}}")
        return values[key]

    rendered = re.sub(r"\{\{(\w+)\}\}", repl, template)
    assert "{{" not in rendered, "v HTML zůstal nenahrazený placeholder"
    return rendered


# ── Gotenberg ────────────────────────────────────────────────────────────────

# A4 rozměrově — jmenované formáty Gotenberg nezná (docs/render.md).
# Okraje 0, vnitřní okraje řeší CSS šablon (accent bar šablony a smí k hraně).
GOTENBERG_FIELDS = {
    "paperWidth": "8.27",
    "paperHeight": "11.7",
    "marginTop": "0",
    "marginBottom": "0",
    "marginLeft": "0",
    "marginRight": "0",
    "printBackground": "true",
}

PDF_OPTION_KEYS = frozenset({
    "paperWidth", "paperHeight", "marginTop", "marginBottom", "marginLeft", "marginRight",
    "printBackground", "landscape", "scale",
})


def pdf_options_from_html(html_content: str) -> dict[str, str]:
    """Volby papíru ze šablony: `<!-- pdf: paperWidth=80mm paperHeight=200mm -->`
    v prvních 2 kB. Bez hlavičky = GOTENBERG_FIELDS (A4, okraje 0)."""
    m = re.search(r"<!--\s*pdf:\s*(.*?)\s*-->", html_content[:2048], re.DOTALL)
    fields = dict(GOTENBERG_FIELDS)
    if not m:
        return fields
    for token in m.group(1).split():
        key, sep, value = token.partition("=")
        assert sep and key in PDF_OPTION_KEYS and value, f"neznámá volba pdf: {token}"
        fields[key] = value
    return fields


def render_hash(html_content: str, fields: dict[str, str]) -> str:
    """sha256 všeho, co ovlivňuje PDF: HTML + volby Gotenbergu."""
    payload = html_content + "\n" + json.dumps(fields, sort_keys=True)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def render_pdf(html_content: str, *, fields: dict[str, str] = GOTENBERG_FIELDS,
               url: str | None = None, timeout: int = 60) -> bytes:
    base_url = url or GOTENBERG_URL
    boundary = "----shpd-invoicegen-" + uuid.uuid4().hex
    parts: list[bytes] = []
    for name, value in fields.items():
        parts.append(
            (f"--{boundary}\r\n"
             f'Content-Disposition: form-data; name="{name}"\r\n\r\n'
             f"{value}\r\n").encode("utf-8")
        )
    # Hlavní HTML MUSÍ mít jméno index.html, jinak Gotenberg vrátí 400.
    parts.append(
        (f"--{boundary}\r\n"
         'Content-Disposition: form-data; name="files"; filename="index.html"\r\n'
         "Content-Type: text/html\r\n\r\n").encode("utf-8")
        + html_content.encode("utf-8")
        + b"\r\n"
    )
    parts.append(f"--{boundary}--\r\n".encode("utf-8"))
    body = b"".join(parts)

    request = urllib.request.Request(
        f"{base_url}/forms/chromium/convert/html",
        data=body,
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            pdf = response.read()
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", errors="replace")[:500]
        raise RuntimeError(f"Gotenberg HTTP {e.code}: {detail}") from e
    except urllib.error.URLError as e:
        raise RuntimeError(f"Gotenberg nedostupný na {base_url}: {e.reason}") from e

    assert pdf.startswith(b"%PDF-"), "odpověď není PDF"
    return pdf
