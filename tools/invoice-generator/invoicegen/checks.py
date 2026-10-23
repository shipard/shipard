"""Kontrolní součty identifikátorů (IČO, mod 11 čísel účtů, IBAN, DIČ zemí EU,
BIC) a validace identit — demo kolekce i korpusu (bez sítě; ARES a VIES
jsou v registries.py)."""

from __future__ import annotations

import re
from datetime import date

EXAMPLE_TLD = ".example"  # RFC 2606 — doména nikdy nikomu nebude patřit

# Formát čísla DIČ po prefixu země (bez mezer) — jen země, které korpus používá
# nebo může používat; ostatní obecný tvar.
VAT_ID_FORMATS = {
    "CZ": r"[0-9]{8,10}",
    "SK": r"[0-9]{10}",
    "DE": r"[0-9]{9}",
    "AT": r"U[0-9]{8}",
    "PL": r"[0-9]{10}",
}


# ── Kontrolní součty ─────────────────────────────────────────────────────────

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


def iban_check_digits(country: str, bban: str) -> str:
    """Kontrolní číslice IBAN (ISO 7064 MOD 97-10) pro zemi a BBAN."""
    numeric = "".join(str(int(c, 36)) for c in bban + country.upper() + "00")
    return f"{98 - int(numeric) % 97:02d}"


def _cz_bban(account: str) -> str | None:
    m = re.fullmatch(r"(?:(\d{2,6})-)?(\d{2,10})/(\d{4})", account)
    if not m:
        return None
    prefix, number, bank = m.groups()
    return bank + (prefix or "").zfill(6) + number.zfill(10)


def iban_from_account(account: str) -> str:
    """CZ IBAN z čísla účtu `[předčíslí-]číslo/kód banky`."""
    bban = _cz_bban(account)
    assert bban is not None, f"neplatný tvar účtu {account}"
    return "CZ" + iban_check_digits("CZ", bban) + bban


def iban_matches_account(iban: str, account: str) -> bool:
    bban = _cz_bban(account)
    return bban is not None and iban.startswith("CZ") and iban[4:] == bban


def de_vat_check_digit(digits8: str) -> int:
    """ISO 7064 MOD 11,10 pro německé USt-IdNr. (8 číslic → kontrolní číslice)."""
    assert re.fullmatch(r"[0-9]{8}", digits8), "DE DIČ: 8 číslic před kontrolní"
    product = 10
    for d in digits8:
        s = (int(d) + product) % 10
        if s == 0:
            s = 10
        product = (2 * s) % 11
    return (11 - product) % 10


def de_vat_valid(vat_id: str) -> bool:
    if not re.fullmatch(r"DE[0-9]{9}", vat_id):
        return False
    return de_vat_check_digit(vat_id[2:10]) == int(vat_id[10])


def vat_id_format_valid(vat_id: str) -> bool:
    if not re.fullmatch(r"[A-Z]{2}[0-9A-Z]{2,13}", vat_id):
        return False
    pattern = VAT_ID_FORMATS.get(vat_id[:2])
    return pattern is None or re.fullmatch(pattern, vat_id[2:]) is not None


def bic_valid(bic: str) -> bool:
    return re.fullmatch(r"[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?", bic) is not None


def example_domain(address: str) -> bool:
    """E-mail nebo web (bez schématu) malými písmeny v TLD .example."""
    host = address.rsplit("@", 1)[-1].split("/", 1)[0]
    return address == address.lower() and host.endswith(EXAMPLE_TLD) and len(host) > len(EXAMPLE_TLD)


def phone_canonical(phone: str) -> bool:
    return re.fullmatch(r"\+[1-9][0-9]{6,14}", phone) is not None


# Kódy zemí o dvou číslicích; `1` má jednu, ostatní (420, 421, …) tři.
TWO_DIGIT_COUNTRY_CODES = ("49", "43", "44", "33", "39", "31")


def phone_parts(phone: str) -> tuple[str, str] | None:
    """Kód země a národní číslo kanonického telefonu (`+420000201304` →
    `("420", "000201304")`); jiný tvar → None. Jediná tabulka délek kódů
    zemí — sdílí ji tisk (`render.fmt_phone`) i kontrola identit."""
    m = re.fullmatch(r"\+([0-9]+)", phone)
    if not m:
        return None
    digits = m.group(1)
    cc_len = 1 if digits.startswith("1") else 2 if digits[:2] in TWO_DIGIT_COUNTRY_CODES else 3
    return digits[:cc_len], digits[cc_len:]


def phone_unassigned(phone: str) -> bool:
    """Telefon fiktivní identity z nepřidělitelného rozsahu: národní číslo
    začíná `000` (stejné pravidlo jako demo sada), nebo americké číslo
    z vyhrazeného rozsahu 555-01xx (`+1 NXX 555 01XX`)."""
    parts = phone_parts(phone)
    if parts is None:
        return False
    cc, national = parts
    if national.startswith("000"):
        return True
    return cc == "1" and re.fullmatch(r"[2-9][0-9]{2}55501[0-9]{2}", national) is not None


# ── Identity dema ────────────────────────────────────────────────────────────

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


# ── Identity korpusu ─────────────────────────────────────────────────────────

def _text(value) -> bool:
    return isinstance(value, str) and value == value.strip() and value != ""


def _validate_corpus_party(key: str, party: dict, problems: list[str]) -> None:
    def bad(msg: str) -> None:
        problems.append(f"{key}: {msg}")

    name = party.get("name")
    country = party.get("country")
    if not _text(name):
        bad("chybí name")
    if not isinstance(country, str) or not re.fullmatch(r"[a-z]{2}", country):
        bad(f"country {country!r} není ISO alpha-2 malými")
        return

    company_id = party.get("companyId")
    tax_id = party.get("taxId")
    vat_id = party.get("vatId")
    if country == "cz":
        if not isinstance(company_id, str) or not ico_valid(company_id):
            bad(f"IČO {company_id!r} nemá validní kontrolní součet")
        if vat_id is not None and vat_id != "CZ" + str(company_id):
            bad(f"vatId {vat_id} ≠ CZ + IČO")
    elif country == "us":
        if vat_id is not None:
            bad("us: vatId musí být null")
    if vat_id is not None:
        if not vat_id_format_valid(vat_id):
            bad(f"vatId {vat_id} nemá tvar DIČ")
        elif vat_id[:2] == "DE" and not de_vat_valid(vat_id):
            bad(f"vatId {vat_id} nemá validní kontrolní číslici (ISO 7064 MOD 11,10)")
        prefix = "EL" if country == "gr" else country.upper()
        if vat_id[:2] != prefix:
            bad(f"vatId {vat_id} neodpovídá zemi {country}")
    if tax_id != vat_id:
        bad(f"taxId {tax_id!r} ≠ vatId {vat_id!r} (u EU DIČ = VAT ID)")
    if "vatPayer" in party and bool(party["vatPayer"]) != (vat_id is not None):
        bad("vatPayer neodpovídá přítomnosti vatId")
    if party.get("courtRegistration") is not None and not _text(party["courtRegistration"]):
        bad("courtRegistration není text")

    address = party.get("address")
    if not isinstance(address, dict):
        bad("chybí address")
    else:
        if not _text(address.get("street")):
            bad("address.street chybí")
        if not _text(address.get("city")):
            bad("address.city chybí")
        if address.get("country") != country:
            bad(f"address.country {address.get('country')!r} ≠ country {country}")
        zip_code = address.get("zip")
        if country in ("cz", "sk"):
            if not isinstance(zip_code, str) or not re.fullmatch(r"[0-9]{5}", zip_code):
                bad(f"PSČ {zip_code!r} — {country}: 5 číslic bez mezery")
        elif not _text(zip_code):
            bad(f"PSČ {zip_code!r} chybí")
        house = address.get("houseNumber")
        if country == "us":
            if house is not None:
                bad("us: houseNumber musí být null (číslo je v street)")
        elif not _text(house):
            bad("address.houseNumber chybí")

    contact = party.get("contact")
    if contact is not None:
        if not isinstance(contact, dict):
            bad("contact není objekt")
        else:
            email = contact.get("email")
            if email is not None and (not re.fullmatch(r"[^A-Z\s@]+@[^A-Z\s@]+\.[a-z]+", email)
                                      or not example_domain(email)):
                bad(f"email {email!r} — malými, doména .example")
            web = contact.get("web")
            if web is not None and (not re.fullmatch(r"[a-z0-9.-]+(?:/[^\s/A-Z]+)*", web)
                                    or not example_domain(web)):
                bad(f"web {web!r} — malými, bez schématu a koncového lomítka, doména .example")
            phone = contact.get("phone")
            if phone is not None and not phone_canonical(phone):
                bad(f"telefon {phone!r} — jen + a číslice")
            elif phone is not None and not phone_unassigned(phone):
                bad(f"telefon {phone!r} — národní číslo musí začínat 000 (výjimka +1 …555 01xx)")
            if contact.get("name") is not None and not _text(contact["name"]):
                bad("contact.name není text")

    bank = party.get("bankAccount")
    if bank is not None:
        if not isinstance(bank, dict):
            bad("bankAccount není objekt")
        else:
            account = bank.get("accountNumber")
            iban = bank.get("iban")
            bic = bank.get("bic")
            if account is not None and not account_valid(account):
                bad(f"účet {account} nesedí na mod 11")
            if iban is not None and not iban_valid(iban):
                bad(f"IBAN {iban} nemá validní kontrolní číslice")
            if account is not None and iban is None:
                bad("účet bez IBAN")
            if account is not None and iban is not None and not iban_matches_account(iban, account):
                bad("IBAN neodpovídá číslu účtu")
            if account is not None and country != "cz":
                bad("accountNumber jen u cz")
            if bic is not None and not bic_valid(bic):
                bad(f"BIC {bic} nemá tvar")


def validate_identities(identities: dict, *, reserved: dict | None = None) -> list[str]:
    """Identity korpusu (`tests/corpus/cz/identities.jsonc`): vrací seznam
    problémů (prázdný = v pořádku). `reserved` = identity dema
    (`suppliers.jsonc`), se kterými se korpus nesmí překrývat."""
    problems: list[str] = []
    verified = identities.get("verifiedAt")
    try:
        date.fromisoformat(verified)
    except (TypeError, ValueError):
        problems.append(f"verifiedAt {verified!r} není ISO datum")

    parties: list[tuple[str, dict]] = []
    for section in ("profiles", "suppliers"):
        entries = identities.get(section)
        if not isinstance(entries, dict) or not entries:
            problems.append(f"chybí {section}")
            continue
        for key, party in entries.items():
            if not isinstance(party, dict):
                problems.append(f"{section}.{key}: není objekt")
                continue
            if section == "suppliers" and not isinstance(party.get("vatPayer"), bool):
                problems.append(f"{section}.{key}: chybí vatPayer")
            parties.append((f"{section}.{key}", party))
            _validate_corpus_party(f"{section}.{key}", party, problems)

    seen: dict[tuple[str, str], str] = {}
    for key, party in parties:
        values = {
            "name": (party.get("name") or "").lower(),
            "companyId": party.get("companyId"),
            "vatId": party.get("vatId"),
            "iban": (party.get("bankAccount") or {}).get("iban"),
        }
        for field, value in values.items():
            if not value:
                continue
            other = seen.setdefault((field, value), key)
            if other != key:
                problems.append(f"{key}: {field} {value} je shodné s {other}")

    if reserved:
        demo_parties = [reserved.get("ownCompany") or {}] + list((reserved.get("suppliers") or {}).values())
        demo_names = {p["name"].lower() for p in demo_parties if p.get("name")}
        demo_icos = {p["ico"] for p in demo_parties if p.get("ico")}
        demo_ibans = {p["iban"] for p in demo_parties if p.get("iban")}
        for key, party in parties:
            if (party.get("name") or "").lower() in demo_names:
                problems.append(f"{key}: název se překrývá s demo identitou")
            if party.get("companyId") in demo_icos:
                problems.append(f"{key}: IČO se překrývá s demo identitou")
            if (party.get("bankAccount") or {}).get("iban") in demo_ibans:
                problems.append(f"{key}: IBAN se překrývá s demo identitou")
    return problems
