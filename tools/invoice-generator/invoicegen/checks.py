"""Kontrolní součty identifikátorů (IČO, mod 11 čísel účtů, IBAN)
a validace identit demo kolekce."""

from __future__ import annotations

import re


def ico_valid(ico: str) -> bool:
    if not re.fullmatch(r"\d{8}", ico):
        return False
    total = sum(int(d) * w for d, w in zip(ico[:7], range(8, 1, -1)))
    return (11 - total % 11) % 10 == int(ico[7])


def _mod11(digits: str) -> bool:
    # váha číslice = 2^i mod 11, i od pravého kraje
    weights = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]
    total = sum(int(d) * weights[i] for i, d in enumerate(reversed(digits)))
    return total % 11 == 0


def account_valid(account: str) -> bool:
    m = re.fullmatch(r"(?:(\d{2,6})-)?(\d{2,10})/(\d{4})", account)
    if not m:
        return False
    prefix, number, _bank = m.groups()
    if prefix is not None and not _mod11(prefix):
        return False
    return _mod11(number)


def iban_valid(iban: str) -> bool:
    if not re.fullmatch(r"[A-Z]{2}\d{2}[A-Z0-9]+", iban):
        return False
    rearranged = iban[4:] + iban[:4]
    numeric = "".join(str(int(c, 36)) for c in rearranged)
    return int(numeric) % 97 == 1


def iban_matches_account(iban: str, account: str) -> bool:
    m = re.fullmatch(r"(?:(\d{2,6})-)?(\d{2,10})/(\d{4})", account)
    if not m:
        return False
    prefix, number, bank = m.groups()
    bban = bank + (prefix or "").zfill(6) + number.zfill(10)
    return iban.startswith("CZ") and iban[4:] == bban


def validate_party(name: str, party: dict, with_bank: bool) -> None:
    """Identita demo kolekce (`demo/invoices/suppliers.jsonc`): IČO, DIČ = CZ + IČO,
    volitelně účet + IBAN."""
    assert ico_valid(party["ico"]), f"{name}: IČO {party['ico']} nemá validní kontrolní součet"
    assert party["dic"] == "CZ" + party["ico"], f"{name}: DIČ nesedí na IČO"
    if with_bank:
        assert account_valid(party["account"]), f"{name}: účet {party['account']} nesedí na mod-11"
        assert iban_valid(party["iban"]), f"{name}: IBAN {party['iban']} nemá validní check digits"
        assert iban_matches_account(party["iban"], party["account"]), \
            f"{name}: IBAN neodpovídá číslu účtu"
