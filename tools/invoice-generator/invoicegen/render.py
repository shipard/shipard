"""Formátování hodnot pro tisk, skládání HTML ze šablony s placeholdery
`{{key}}` a render PDF přes Gotenberg (Chromium, docs/render.md)."""

from __future__ import annotations

import html
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

def fmt_money(value: Decimal) -> str:
    s = f"{value.quantize(CENTS, rounding=ROUND_HALF_UP):,.2f}"
    return s.replace(",", NNBSP).replace(".", ",")


def fmt_qty(value: Decimal) -> str:
    normalized = value.normalize()
    if normalized == normalized.to_integral_value():
        return str(int(normalized))
    return format(normalized, "f").replace(".", ",")


def fmt_date(iso: str) -> str:
    d = date.fromisoformat(iso)
    return f"{d.day}. {d.month}. {d.year}"


def fmt_iban(iban: str) -> str:
    return " ".join(iban[i:i + 4] for i in range(0, len(iban), 4))


# ── HTML ─────────────────────────────────────────────────────────────────────

def esc(value: str) -> str:
    return html.escape(str(value), quote=False)


def render_rows(computed: dict) -> str:
    parts = []
    for row in computed["rows"]:
        unit = UNIT_DISPLAY.get(row["unit"], row["unit"])
        parts.append(
            '\t\t\t<tr class="row">'
            f'<td class="c-text">{esc(row["text"])}</td>'
            f'<td class="c-qty">{fmt_qty(row["qty"])}</td>'
            f'<td class="c-unit">{esc(unit)}</td>'
            f'<td class="c-price">{fmt_money(row["unitPrice"])}</td>'
            f'<td class="c-vat">{row["vatPct"]}{NNBSP}%</td>'
            f'<td class="c-total">{fmt_money(row["total"])}</td>'
            "</tr>"
        )
    return "\n".join(parts)


def render_vat_recap(computed: dict) -> str:
    parts = []
    for pct, r in computed["recap"].items():
        parts.append(
            '\t\t\t<tr class="vr">'
            f'<td class="v-pct">{pct}{NNBSP}%</td>'
            f'<td class="v-base">{fmt_money(r["base"])}</td>'
            f'<td class="v-tax">{fmt_money(r["tax"])}</td>'
            f'<td class="v-total">{fmt_money(r["total"])}</td>'
            "</tr>"
        )
    return "\n".join(parts)


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
