"""Řazení textů jako MariaDB `utf8mb4_czech_ci` — kvůli pořadí souborů
osob v sadě (`PersonExporter`: `ORDER BY full_name`), které musí generátor
trefit stejně jako dump (D10).

Klíč má tři úrovně jako česká norma: primární = základní písmena, kde
`č`, `ch`, `ř`, `š`, `ž` jsou samostatná písmena (za c, h, r, s, z),
ostatní diakritika se skládá až na sekundární úrovni (á = a), velikost
písmen na terciární. Číslice řadí před písmena, mezera a interpunkce
nejníže. Okrajové chování collation (váhy interpunkce, remízy) se tu
nereplikuje — místo toho `robust_order()` hlásí jména, o jejichž pořadí
rozhoduje jen diakritika, velikost písmen nebo interpunkce; autor příběhu
takové jméno přejmenuje.
"""

from __future__ import annotations

import unicodedata

# Primární abeceda česky (ČSN 97 6030): ch mezi h a i, háčková písmena za
# svým základem; ostatní písmena s diakritikou mají základ.
_PRIMARY_ALPHABET = ["a", "b", "c", "č", "d", "e", "f", "g", "h", "ch", "i", "j", "k", "l", "m", "n", "o",
                     "p", "q", "r", "ř", "s", "š", "t", "u", "v", "w", "x", "y", "z", "ž"]
_PRIMARY = {letter: 100 + index for index, letter in enumerate(_PRIMARY_ALPHABET)}
_OWN_LETTERS = frozenset({"č", "ř", "š", "ž"})


def _base(ch: str) -> str:
    """Písmeno bez diakritiky (ß → ss se tu neřeší, v názvech se nečeká)."""
    decomposed = unicodedata.normalize("NFD", ch)
    return "".join(c for c in decomposed if not unicodedata.combining(c))


def czech_key(text: str) -> tuple[tuple[int, ...], tuple[int, ...], tuple[int, ...]]:
    primary: list[int] = []
    secondary: list[int] = []
    tertiary: list[int] = []
    lowered = text.lower()
    i = 0
    while i < len(lowered):
        ch = lowered[i]
        if ch == "c" and i + 1 < len(lowered) and lowered[i + 1] == "h":
            primary.append(_PRIMARY["ch"])
            secondary.append(0)
            tertiary.append(int(text[i].isupper()))
            i += 2
            continue
        if ch in _OWN_LETTERS:
            primary.append(_PRIMARY[ch])
            secondary.append(0)
        elif ch.isdigit():
            primary.append(50 + int(ch))
            secondary.append(0)
        elif ch.isalpha():
            base = _base(ch)
            primary.append(_PRIMARY.get(base, 100 + len(_PRIMARY_ALPHABET) + ord(base)))
            secondary.append(int(base != ch))
        else:
            primary.append(1)   # mezera, interpunkce — nejníže, ale ne ignorované
            secondary.append(0)
        tertiary.append(int(text[i].isupper()))
        i += 1
    return tuple(primary), tuple(secondary), tuple(tertiary)


def sort_czech(texts: list[str]) -> list[str]:
    return sorted(texts, key=czech_key)


def robust_order(texts: list[str]) -> list[str]:
    """Problémy s pořadím: dvojice sousedních textů (po seřazení), o jejichž
    pořadí rozhoduje až diakritika / velikost písmen, nebo první rozdíl je
    interpunkce proti písmenu / číslici (jiné collation to řadí jinak)."""
    problems: list[str] = []
    ordered = sort_czech(texts)
    for first, second in zip(ordered, ordered[1:]):
        p1, p2 = czech_key(first)[0], czech_key(second)[0]
        if p1 == p2:
            problems.append(f"„{first}“ a „{second}“ se liší jen diakritikou nebo velikostí písmen")
            continue
        for a, b in zip(p1, p2):
            if a == b:
                continue
            if a == 1 or b == 1:
                problems.append(f"„{first}“ a „{second}“: o pořadí rozhoduje interpunkce nebo mezera")
            break
        else:
            # Jeden je prefixem druhého — pořadí jednoznačné (kratší první).
            pass
    return problems
