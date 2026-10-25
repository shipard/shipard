"""Očekávaný výsledek dokladu = papírová projekce canonicalu
shpd.docs.document.v1 (tasks/testing-phase1.md, D14): vždy všechna pole
projekce (`tests/corpus/projection.jsonc`), `null` = na dokladu není, klíče
v pořadí projekce, čísla jako Decimal (zapisovač je nepřevádí na float).

Pravidla odvozených polí sledují prompt analýzy pošty
(`modules/core/mail/profiles/czech_general.jsonc`, PROMPT_VERSION):
`vat.place` podle DIČ dodavatele, `vat.reverseCharge` jen u přenesení /
reverse charge (čl. 138 ho nezapíná), `supplyKind` na každém řádku
dodavatele mimo ČR, `reverseChargeCode` jen u tuzemského přenesení,
účtenka bez odběratele a splatnosti. Když se prompt změní, změní se tento
modul a korpus se přegeneruje.

Hodnoty netištěné samostatně (`unprinted` v datovém souboru, #103 D110,
D112): `paymentReference: docNumber` dává do očekávání číslo dokladu jako
platební referenci (doklad o ni žádá v poznámce, řádek VS netiskne); období
`period: rows` zůstává v `dates` a očekávání ho nese jako dnes — jen ho
šablona netiskne v hlavičce (`corpusprint.template_values`).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from decimal import Decimal
from pathlib import Path

from .compute import CENTS
from .jsonc import load_jsonc

PROMPT_VERSION = "v4.10.0"

PROJECTION_FORMAT = "shpd.corpus.projection"
PROJECTION_VERSION = "1"

EU_COUNTRIES = frozenset({
    "at", "be", "bg", "hr", "cy", "cz", "dk", "ee", "fi", "fr", "de", "gr", "hu", "ie",
    "it", "lv", "lt", "lu", "mt", "nl", "pl", "pt", "ro", "sk", "si", "es", "se",
})
VAT_PREFIX_TO_COUNTRY = {"EL": "gr", "XI": "xi"}  # XI = Severní Irsko (zboží jako EU)

# vatTreatment → (vat.mode, vat.reverseCharge)
VAT_DERIVATION: dict[str, tuple[str, bool]] = {
    "standard": ("fromBase", False),
    "pricesWithVat": ("fromTotal", False),
    "reverseCharge": ("fromBase", True),
    "exemptIntraCommunity": ("fromBase", False),
    "noVat": ("none", False),
}

PARTY_IGNORED_KEYS = frozenset({"vatPayer", "accent", "print"})  # jen pro generátor / tisk


class ProjectionError(ValueError):
    pass


@dataclass(frozen=True)
class Projection:
    document: dict
    defs: dict
    shapes: dict
    nullable_nodes: frozenset[str]


def load_projection(path: Path) -> Projection:
    raw = load_jsonc(path)
    if raw.get("format") != PROJECTION_FORMAT or raw.get("formatVersion") != PROJECTION_VERSION:
        raise ProjectionError(f"{path}: není {PROJECTION_FORMAT} v{PROJECTION_VERSION}")
    shapes = raw["shapes"]
    for name, shape in shapes.items():
        if not isinstance(shape, dict) or len(shape) != 1:
            raise ProjectionError(f"tvar {name}: právě jeden klíč regex|const|check|byCountry")
        kind = next(iter(shape))
        if kind == "regex":
            if "~" in shape["regex"]:
                raise ProjectionError(f"tvar {name}: regex nesmí obsahovat '~'")
            re.compile(shape["regex"])
        elif kind == "check":
            if shape["check"] not in ("number", "amount", "index", "tag"):
                raise ProjectionError(f"tvar {name}: neznámá kontrola {shape['check']}")
        elif kind == "byCountry":
            if "*" not in shape["byCountry"]:
                raise ProjectionError(f"tvar {name}: byCountry bez '*'")
            for target in shape["byCountry"].values():
                if target not in shapes or "byCountry" in shapes[target]:
                    raise ProjectionError(f"tvar {name}: byCountry odkazuje na {target}")
        elif kind != "const":
            raise ProjectionError(f"tvar {name}: neznámý druh {kind}")
    return Projection(
        document=raw["document"],
        defs=raw["$defs"],
        shapes=shapes,
        nullable_nodes=frozenset(raw.get("nullableNodes", [])),
    )


# ── Odvozená pole ────────────────────────────────────────────────────────────

def derive_vat(vat_treatment: str) -> tuple[str, bool]:
    return VAT_DERIVATION[vat_treatment]


def vat_place(supplier: dict) -> str:
    """Prompt: podle DIČ dodavatele (CZ → domestic, jiný stát EU → intracom);
    bez DIČ podle sídla (cz domestic, EU intracom, jinak thirdCountry)."""
    vat_id = supplier.get("vatId")
    if vat_id:
        prefix = vat_id[:2].upper()
        country = VAT_PREFIX_TO_COUNTRY.get(prefix, prefix.lower())
    else:
        country = supplier["country"]
    if country == "cz":
        return "domestic"
    if country in EU_COUNTRIES or country == "xi":
        return "intracom"
    return "thirdCountry"


def _amount(value: Decimal | None) -> Decimal | None:
    return None if value is None else value.quantize(CENTS)


def _party(identity: dict | None) -> dict | None:
    if identity is None:
        return None
    return {k: v for k, v in identity.items() if k not in PARTY_IGNORED_KEYS}


def build_expected(data: dict, supplier: dict, profile: dict | None, computed: dict,
                   *, receipt: bool) -> dict:
    """Neuspořádaný strom očekávání; `apply_projection` ho srovná, doplní
    `null` a odmítne klíče mimo projekci."""
    treatment = data["vatTreatment"]
    mode, reverse_charge = derive_vat(treatment)
    foreign = supplier["country"] != "cz"
    domestic_rc = treatment == "reverseCharge" and not foreign
    dates = data.get("dates", {})
    payment = data.get("payment") or {}
    unprinted = data.get("unprinted") or {}
    # D110: doklad žádá uvést číslo faktury s platbou → referencí je číslo dokladu.
    reference = data["docNumber"] if unprinted.get("paymentReference") == "docNumber" else data.get("paymentReference")

    rows = []
    exceptions = []
    for index, row in enumerate(computed["rows"]):
        rows.append({
            "rowKind": "item",
            "item": {
                "supplierCode": row.get("supplierCode"),
                "ean": row.get("ean"),
                "name": row["text"],
                "description": row.get("description"),
            },
            "unit": row["unit"],
            "quantity": row["qty"].normalize(),
            "unitPrice": _amount(row["unitPrice"]),
            "totalPrice": _amount(row["total"]),
            "priceCalcMode": row["priceCalcMode"],
            "discountPct": row["discountPct"].normalize() if row["discountPct"] is not None else None,
            "discountAmount": None,  # šablony tisknou slevu v %, canonical je „pct nebo amount“
            "vat": {
                "pct": row["vatPct"],
                "supplyKind": row.get("supplyKind") if foreign else None,
                "reverseChargeCode": row.get("reverseChargeCode") if domestic_rc else None,
            },
        })
        if row.get("tag"):
            exceptions.append({"rowIndex": index, "tag": row["tag"]})

    if mode == "none":
        recap = []  # schéma chce pole; prázdné ≡ null (rovnocenné tvary)
    else:
        recap = [
            {"vatPct": pct, "base": _amount(r["base"]), "tax": _amount(r["tax"]), "total": _amount(r["total"])}
            for pct, r in computed["recap"].items()
        ]

    return {
        "format": "shpd.docs.document",
        "formatVersion": "1.0",
        "docType": "invoiceReceived",
        "docNumber": data["docNumber"],
        "selfParty": "customer",
        "supplier": _party(supplier),
        "customer": None if receipt else _party(profile),
        "dates": {
            "issueDate": dates.get("issue"),
            "dueDate": None if receipt else dates.get("due"),
            "taxPointDate": dates.get("tax"),
            "periodFrom": dates.get("periodFrom"),
            "periodTo": dates.get("periodTo"),
        },
        "currency": data["currency"],
        "exchangeRate": None,
        "vat": {"mode": mode, "place": vat_place(supplier), "reverseCharge": reverse_charge},
        "payment": {
            "method": payment.get("method", "bankTransfer"),
            "paymentReference": reference,
            "specificSymbol": payment.get("specificSymbol"),
            "constantSymbol": payment.get("constantSymbol"),
        },
        "notes": {"onDocument": (data.get("notes") or {}).get("onDocument")},
        "rows": rows,
        "vatRecap": recap,
        "totals": {
            "totalBase": _amount(computed["totalBase"]),
            "totalVat": _amount(computed["totalVat"]),
            "totalAmount": _amount(computed["totalAmount"]),
            "totalRounding": computed["totalRounding"] if computed["totalRounding"] != 0 else Decimal("0"),
        },
        "_resolve": {"contentTag": {"tag": data["contentTag"], "rowExceptions": exceptions}},
    }


# ── Projekce ─────────────────────────────────────────────────────────────────

def _resolve(spec, projection: Projection):
    if isinstance(spec, dict) and "$ref" in spec:
        if len(spec) != 1:
            raise ProjectionError(f"$ref {spec['$ref']}: objekt smí mít jen $ref")
        return projection.defs[spec["$ref"]]
    return spec


def _null_tree(spec, projection: Projection):
    spec = _resolve(spec, projection)
    if isinstance(spec, dict):
        return {key: _null_tree(sub, projection) for key, sub in spec.items()}
    return None


def check_shape(name: str, value, parent: dict | None, projection: Projection,
                *, taxonomy: set[str] | None) -> str | None:
    """Vrátí popis problému, nebo None."""
    shape = projection.shapes[name]
    if "byCountry" in shape:
        table = shape["byCountry"]
        country = (parent or {}).get("country")
        name = table.get(country, table["*"])
        shape = projection.shapes[name]
    if "const" in shape:
        return None if value == shape["const"] and type(value) is type(shape["const"]) else \
            f"{value!r} ≠ const {shape['const']!r} ({name})"
    if value is None:
        return None
    if "regex" in shape:
        if not isinstance(value, str) or re.fullmatch(shape["regex"], value) is None:
            return f"{value!r} neodpovídá tvaru {name} /{shape['regex']}/"
        return None
    check = shape["check"]
    if check == "number":
        ok = isinstance(value, (int, Decimal)) and not isinstance(value, bool)
    elif check == "amount":
        ok = (isinstance(value, int) and not isinstance(value, bool)) or (
            isinstance(value, Decimal) and value.as_tuple().exponent >= -2
        )
    elif check == "index":
        ok = isinstance(value, int) and not isinstance(value, bool) and value >= 0
    else:  # tag
        ok = isinstance(value, str) and (taxonomy is None or value in taxonomy)
    return None if ok else f"{value!r} neprošlo kontrolou {name}"


def apply_projection(doc: dict, projection: Projection, *, taxonomy: set[str] | None = None) -> dict:
    """Uspořádá `doc` podle projekce, doplní chybějící listy i objekty jako
    `null`, odmítne klíče mimo projekci a ověří tvary hodnot."""
    problems: list[str] = []

    def walk(spec, value, path: str, parent: dict | None):
        spec = _resolve(spec, projection)
        node = re.sub(r"\[[0-9]+\]", "[]", path)
        if isinstance(spec, list):
            if value is None:
                if node not in projection.nullable_nodes:
                    problems.append(f"{path}: pole nesmí být null")
                return None
            if not isinstance(value, list):
                problems.append(f"{path}: očekáváno pole")
                return None
            return [walk(spec[0], item, f"{path}[{i}]", None) for i, item in enumerate(value)]
        if isinstance(spec, dict):
            if value is None:
                if node in projection.nullable_nodes:
                    return None
                return _null_tree(spec, projection)
            if not isinstance(value, dict):
                problems.append(f"{path}: očekáván objekt")
                return None
            extra = [k for k in value if k not in spec]
            if extra:
                problems.append(f"{path or '/'}: klíče mimo projekci {', '.join(extra)}")
            return {
                key: walk(sub, value.get(key), f"{path}.{key}" if path else key, value)
                for key, sub in spec.items()
            }
        if isinstance(value, (dict, list)):
            problems.append(f"{path}: očekáván skalár")
            return None
        if isinstance(value, float):
            problems.append(f"{path}: float — čísla jen Decimal / int")
            return None
        if spec is not None:
            problem = check_shape(spec, value, parent, projection, taxonomy=taxonomy)
            if problem:
                problems.append(f"{path}: {problem}")
        return value

    ordered = walk(projection.document, doc, "", None)
    if problems:
        raise ProjectionError("\n".join(problems))
    return ordered


# ── Zápis JSON ───────────────────────────────────────────────────────────────

def _dump_number(value: Decimal) -> str:
    if value == 0:
        value = abs(value)  # žádné -0.00
    return format(value, "f")


def _dump(value, level: int) -> str:
    pad = "  " * level
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, Decimal):
        return _dump_number(value)
    if isinstance(value, float):
        raise TypeError("float v očekávaném výsledku — čísla jen Decimal / int")
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=False)
    if isinstance(value, dict):
        if not value:
            return "{}"
        inner = ",\n".join(
            f"{pad}  {json.dumps(key, ensure_ascii=False)}: {_dump(item, level + 1)}"
            for key, item in value.items()
        )
        return "{\n" + inner + "\n" + pad + "}"
    if isinstance(value, (list, tuple)):
        if not value:
            return "[]"
        inner = ",\n".join(f"{pad}  {_dump(item, level + 1)}" for item in value)
        return "[\n" + inner + "\n" + pad + "]"
    raise TypeError(f"nepodporovaný typ {type(value).__name__}")


def dumps_expected(doc: dict) -> str:
    """JSON s odsazením 2 mezery, UTF-8 bez escapování, Decimal jako číslo
    (bez exponentu), `\\n` na konci."""
    return _dump(doc, 0) + "\n"
