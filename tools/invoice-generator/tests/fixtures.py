"""Testovací identity a datový soubor ve tvaru korpusu (tasks/testing-phase1.md).
Jen pro unit testy — nejsou to identity korpusu, nic se z nich netiskne."""

from __future__ import annotations

import copy
from decimal import Decimal
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
PROJECTION_PATH = REPO_ROOT / "tests" / "corpus" / "projection.jsonc"
TAXONOMY_PATH = REPO_ROOT / "modules" / "core" / "exchange" / "config" / "contentTags.jsonc"

IDENTITIES = {
    "verifiedAt": "2026-10-14",
    "profiles": {
        "vat-payer": {
            "name": "Testovna s.r.o.", "country": "cz", "companyId": "07123451",
            "taxId": "CZ07123451", "vatId": "CZ07123451",
            "address": {"street": "Zkušební", "houseNumber": "12/4", "city": "Zlín", "zip": "76001", "country": "cz"},
        },
        "non-payer": {
            "name": "Zkušebna s.r.o.", "country": "cz", "companyId": "28845714",
            "taxId": None, "vatId": None,
            "address": {"street": "Pokusná", "houseNumber": "7", "city": "Brno", "zip": "60200", "country": "cz"},
        },
        "association": {
            "name": "Spolek Testík, z. s.", "country": "cz", "companyId": "40519775",
            "taxId": None, "vatId": None,
            "address": {"street": "Spolková", "houseNumber": "3", "city": "Praha 2", "zip": "12000", "country": "cz"},
        },
    },
    "suppliers": {
        "cz-payer": {
            "vatPayer": True,
            "name": "Dodavatelna s.r.o.", "country": "cz", "companyId": "06388451",
            "taxId": "CZ06388451", "vatId": "CZ06388451",
            "courtRegistration": "C 99999 vedená u Krajského soudu v Brně",
            "address": {"street": "Skladová", "houseNumber": "1128/6", "city": "Brno", "zip": "61600", "country": "cz"},
            "contact": {"name": None, "email": "fakturace@dodavatelna.example", "phone": "+420000123456", "web": "www.dodavatelna.example"},
            "bankAccount": {"accountNumber": "1803729684/0100", "iban": "CZ7201000000001803729684", "bic": "KOMBCZPP"},
        },
        "cz-nonpayer": {
            "vatPayer": False,
            "name": "Jan Fiktivní", "country": "cz", "companyId": "17930278",
            "taxId": None, "vatId": None, "courtRegistration": None,
            "address": {"street": "Lektorská", "houseNumber": "5", "city": "Olomouc", "zip": "77900", "country": "cz"},
            "contact": {"name": None, "email": "jan@fiktivni.example", "phone": "+420000111222", "web": None},
            "bankAccount": {"accountNumber": "5638829718/0300", "iban": "CZ8503000000005638829718", "bic": None},
        },
        "de": {
            "vatPayer": True,
            "name": "Hostaro GmbH", "country": "de", "companyId": None,
            "taxId": None, "vatId": "DE293847560",

            "courtRegistration": "Amtsgericht Berlin-Charlottenburg, HRB 999999",
            "address": {"street": "Serverstraße", "houseNumber": "14", "city": "Berlin", "zip": "10115", "country": "de"},
            "contact": {"name": None, "email": "billing@hostaro.example", "phone": "+490002345678", "web": "www.hostaro.example"},
            "bankAccount": {"accountNumber": None, "iban": "DE72100700000532013000", "bic": "TESTDEBBXXX"},
        },
        "us": {
            "vatPayer": False,
            "name": "Cloudbase Inc.", "country": "us", "companyId": None,
            "taxId": None, "vatId": None, "courtRegistration": None,
            "address": {"street": "500 Market Street, Suite 200", "houseNumber": None, "city": "San Francisco", "zip": "94105", "country": "us"},
            "contact": {"name": None, "email": "billing@cloudbase.example", "phone": "+14155550132", "web": "www.cloudbase.example"},
            "bankAccount": {"accountNumber": None, "iban": None, "bic": None},
        },
    },
}

DEMO_RESERVED = {
    "ownCompany": {"name": "Truhlářství Hoblinka s.r.o.", "ico": "52654150"},
    "suppliers": {"softaro": {"name": "Softaro s.r.o.", "ico": "54965691", "iban": "CZ8520100000002308417557"}},
}

DATA_DOMESTIC = {
    "variant": "domestic-goods",
    "form": "pdf",
    "supplier": "cz-payer",
    "docNumber": "FV-2026-0101",
    "paymentReference": "20260101",
    "dates": {"issue": "2026-09-01", "tax": "2026-08-31", "due": "2026-09-15"},
    "currency": "CZK",
    "vatTreatment": "standard",
    "payment": {"method": "bankTransfer", "specificSymbol": None, "constantSymbol": "0308"},
    "contentTag": "goods.stock",
    "rows": [
        {"text": "Spárovka smrková 18 mm", "qty": Decimal("12.5"), "unit": "m2", "unitPrice": Decimal("486.50"), "vatPct": 21},
        {"text": "Doprava", "qty": 1, "unit": "ks", "unitPrice": Decimal("650.00"), "vatPct": 21, "discountPct": 10,
         "tag": "services.shipping"},
    ],
    "rounding": "none",
    "template": "cs-a",
    "notes": {"onDocument": "Zboží zůstává do zaplacení majetkem dodavatele."},
}

DATA_EU_SERVICES = {
    "variant": "eu-services",
    "form": "pdf",  # D10: zahraniční dodavatel ISDOC nedostane
    "supplier": "de",
    "docNumber": "INV-2026-0388",
    "paymentReference": None,
    "dates": {"issue": "2026-09-02", "tax": "2026-08-31", "due": "2026-09-16",
              "periodFrom": "2026-08-01", "periodTo": "2026-08-31"},
    "currency": "EUR",
    "vatTreatment": "reverseCharge",
    "payment": {"method": "bankTransfer", "specificSymbol": None, "constantSymbol": None},
    "contentTag": "it.hosting",
    "rows": [
        {"text": "Managed Kubernetes cluster", "description": "Billing period 08/2026", "supplierCode": "MK8S-M",
         "qty": 1, "unit": "ks", "unitPrice": Decimal("412.00"), "vatPct": 0, "supplyKind": "services"},
        {"text": "Object storage", "qty": Decimal("1.25"), "unit": "TB", "unitPrice": Decimal("18.40"), "vatPct": 0,
         "supplyKind": "services", "discountPct": 10},
    ],
    "rounding": "none",
    "template": "en",
    "notes": {"onDocument": None},
    "skipProfiles": {"non-payer": "identifikovaná osoba — druhá vlna",
                     "association": "identifikovaná osoba — druhá vlna"},
    "overrides": {},
}

DATA_RECEIPT = {
    "variant": "receipt",
    "form": "pdf",
    "supplier": "cz-payer",
    "docNumber": "0000123456",
    "paymentReference": None,
    "dates": {"issue": "2026-09-03", "tax": "2026-09-03", "due": None},
    "currency": "CZK",
    "vatTreatment": "pricesWithVat",
    "payment": {"method": "card", "specificSymbol": None, "constantSymbol": None},
    "contentTag": "vehicle.fuel",
    "rows": [
        {"text": "Natural 95", "qty": Decimal("38.56"), "unit": "l", "unitPrice": Decimal("38.90"), "vatPct": 21,
         "priceCalcMode": "fromTotal", "totalPrice": Decimal("1500.00")},
        {"text": "Káva", "qty": 1, "unit": "ks", "unitPrice": Decimal("45.00"), "vatPct": 12, "tag": "people.catering"},
    ],
    "rounding": "none",
    "template": "receipt",
    "notes": {"onDocument": None},
}

DATA_NO_VAT = {
    "variant": "domestic-non-payer-supplier",
    "form": "pdf",
    "supplier": "cz-nonpayer",
    "docNumber": "2026-07",
    "paymentReference": "202607",
    "dates": {"issue": "2026-09-04", "tax": None, "due": "2026-09-18"},
    "currency": "CZK",
    "vatTreatment": "noVat",
    "payment": {"method": "bankTransfer", "specificSymbol": None, "constantSymbol": None},
    "contentTag": "services.training",
    "rows": [
        {"text": "Školení první pomoci", "qty": 1, "unit": "ks", "unitPrice": Decimal("4800.00"), "vatPct": None},
    ],
    "rounding": "none",
    "template": "cs-b",
    "notes": {"onDocument": "Nejsem plátce DPH."},
}

DATA_THIRD_COUNTRY = {
    "variant": "third-country-services",
    "form": "pdf",
    "supplier": "us",
    "docNumber": "CB-204611",
    "paymentReference": None,
    "dates": {"issue": "2026-09-01", "tax": None, "due": "2026-09-01"},
    "currency": "USD",
    "vatTreatment": "noVat",
    "payment": {"method": "card", "specificSymbol": None, "constantSymbol": None},
    "contentTag": "it.software",
    "rows": [
        {"text": "Team plan", "description": "12 seats", "qty": 12, "unit": "seat", "unitPrice": Decimal("14.00"),
         "vatPct": None, "supplyKind": "services"},
        {"text": "Additional storage 100 GB", "qty": 1, "unit": "mo", "unitPrice": Decimal("9.00"), "vatPct": None,
         "supplyKind": "services"},
    ],
    "rounding": "none",
    "template": "en",
    "notes": {"onDocument": "Paid by card."},
    "skipProfiles": {"non-payer": "identifikovaná osoba — druhá vlna",
                     "association": "identifikovaná osoba — druhá vlna"},
}

TEMPLATES = {"cs-a", "cs-b", "cs-c", "en", "receipt"}


def data(doc: dict, **changes) -> dict:
    """Kopie datového souboru s přepsanými top-level klíči."""
    out = copy.deepcopy(doc)
    out.update(changes)
    return out


# Hodnoty netištěné samostatně (#103 D110, D112): doklad žádá uvést číslo
# faktury s platbou (referencí je číslo dokladu, řádek VS netiskne); období
# jen v popisu řádku (hlavička ho netiskne).
DATA_EU_SERVICES_REFERENCE = data(
    DATA_EU_SERVICES,
    notes={"onDocument": "Please quote the invoice number with your payment."},
    unprinted={"paymentReference": "docNumber"},
)
DATA_THIRD_COUNTRY_PERIOD = data(
    DATA_THIRD_COUNTRY,
    dates={**DATA_THIRD_COUNTRY["dates"], "periodFrom": "2026-09-01", "periodTo": "2026-09-30"},
    unprinted={"period": "rows"},
)
DATA_THIRD_COUNTRY_PERIOD["rows"][0]["description"] = "12 seats, 09/01/2026 – 09/30/2026"


def rate_list(valid_from: str, number: str, **rates: str) -> dict:
    """Denní lístek ČNB ve formátu shpd.exchangeRates.list.v1 (JPY za 100 jednotek)."""
    amounts = {"JPY": 100, "HUF": 100}
    return {
        "format": "shpd.exchangeRates.list.v1",
        "listType": "cz.cnb.daily",
        "homeCurrency": "CZK",
        "listNumber": number,
        "validFrom": valid_from,
        "validTo": None,
        "rates": [{"currency": c, "amount": amounts.get(c, 1), "rate": Decimal(r)} for c, r in rates.items()],
    }


# Lístek použitý pro DATA_EU_SERVICES (DUZP 2026-08-31, pondělí) a rezerva z pátku.
RATE_LISTS = [
    rate_list("2026-08-28", "26166", EUR="24.15", USD="20.701", XDR="28.244", JPY="13.98"),
    rate_list("2026-08-31", "26167", EUR="24.125", USD="20.698", XDR="28.171", JPY="13.95"),
]
CURRENCIES = frozenset({"CZK", "EUR", "USD", "JPY"})   # XDR mimo world.base.currencies
