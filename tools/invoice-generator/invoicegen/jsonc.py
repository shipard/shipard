"""Načtení JSONC (komentáře `//`, trailing čárky). Čísla s desetinnou
tečkou se načítají jako Decimal, celá čísla jako int."""

from __future__ import annotations

import json
from decimal import Decimal
from pathlib import Path


def _strip_comments(text: str) -> str:
    out: list[str] = []
    in_string = False
    escape = False
    i = 0
    while i < len(text):
        ch = text[i]
        if in_string:
            out.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            i += 1
            continue
        if ch == '"':
            in_string = True
            out.append(ch)
            i += 1
            continue
        if ch == "/" and i + 1 < len(text) and text[i + 1] == "/":
            while i < len(text) and text[i] != "\n":
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def _strip_trailing_commas(text: str) -> str:
    out: list[str] = []
    in_string = False
    escape = False
    for i, ch in enumerate(text):
        if in_string:
            out.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            continue
        if ch == '"':
            in_string = True
            out.append(ch)
            continue
        if ch == ",":
            j = i + 1
            while j < len(text) and text[j] in " \t\r\n":
                j += 1
            if j < len(text) and text[j] in "}]":
                continue  # trailing čárka — vypustit
        out.append(ch)
    return "".join(out)


def loads_jsonc(text: str) -> dict:
    return json.loads(_strip_trailing_commas(_strip_comments(text)), parse_float=Decimal)


def load_jsonc(path: Path) -> dict:
    return loads_jsonc(path.read_text(encoding="utf-8"))
