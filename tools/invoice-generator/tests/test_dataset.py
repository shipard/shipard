from __future__ import annotations

import copy
import hashlib
import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from invoicegen.corpus import PROFILES, cells_for
from invoicegen.dataset import (
    DatasetBuildError, Profile, analysis_json, build_dataset, dataset_canonical, dumps_dataset, load_profile,
    message_for_cell, message_id, own_person, php_json_numbers, prune, slug, strip_dataset_additions,
)
from tests.fixtures import DATA_DOMESTIC, DATA_EU_SERVICES, DATA_RECEIPT, IDENTITIES, PROJECTION_PATH, data
from tests.test_expected import expected_for
from tests.test_reporting import REPORTING

D = Decimal
PDF = b"%PDF-1.7\n%test\n"


def self_expected_strip(canonical: dict) -> dict:
    return strip_dataset_additions(canonical)


def cell_for(doc: dict, stem: str, profile: str = "vat-payer"):
    return next(c for c in cells_for(doc, stem, IDENTITIES) if getattr(c, "profile", None) == profile and hasattr(c, "data"))


class SlugTest(unittest.TestCase):
    def test_matches_php_slug(self) -> None:
        self.assertEqual("msg-corpus-vp-0101", slug("MSG-CORPUS-VP-0101"))
        self.assertEqual("tvarokov-s-r-o", slug("Tvarokov s.r.o."))
        self.assertEqual("spolek-zeleny-kopec-z-s", slug("Spolek Zelený kopec, z. s."))
        self.assertEqual("strasse-ss", slug("Straße ß"))
        self.assertEqual("record", slug("…"))
        self.assertEqual("a" * 60, slug("a" * 61))
        self.assertEqual("ab", slug("ab-" + "c" * 0 + "-"))


class DumpsDatasetTest(unittest.TestCase):
    def test_numbers_follow_php_json_encode(self) -> None:
        out = dumps_dataset({"a": D("486.50"), "b": D("2340.00"), "c": D("4"), "d": D("1.0"), "e": D("0.643"), "f": 7, "g": D("0.0")})
        self.assertEqual('{\n    "a": 486.5,\n    "b": 2340.0,\n    "c": 4,\n    "d": 1.0,\n    "e": 0.643,\n    "f": 7,\n    "g": 0.0\n}\n', out)

    def test_strings_unicode_slashes_and_empties(self) -> None:
        out = dumps_dataset({"s": "Dřevomat / Haná \"x\"\n", "e": {}, "l": [], "n": None, "b": True})
        self.assertEqual('{\n    "s": "Dřevomat / Haná \\"x\\"\\n",\n    "e": {},\n    "l": [],\n    "n": null,\n    "b": true\n}\n', out)
        self.assertEqual('"a\\u2028b"\n', dumps_dataset("a\u2028b"))

    def test_float_is_rejected(self) -> None:
        with self.assertRaises(TypeError):
            dumps_dataset({"x": 1.5})

    def test_blob_numbers_follow_json_decode_encode(self) -> None:
        blob = php_json_numbers({"a": D("2340.00"), "b": D("1.0"), "c": D("486.50"), "d": D("0"), "e": [D("12.5"), D("4")], "s": "x"})
        self.assertEqual({"a": 2340, "b": 1, "c": D("486.50"), "d": 0, "e": [D("12.5"), 4], "s": "x"}, blob)
        self.assertIs(int, type(blob["a"]))
        self.assertEqual('{\n    "a": 2340,\n    "b": 1,\n    "c": 486.5,\n    "d": 0,\n    "e": [\n        12.5,\n        4\n    ],\n    "s": "x"\n}\n',
                         dumps_dataset(blob))

    def test_roundtrips_through_json(self) -> None:
        doc = {"rows": [{"q": D("12.5"), "p": D("486.50"), "t": D("6081.25")}], "n": "ž"}
        parsed = json.loads(dumps_dataset(doc))
        self.assertEqual({"rows": [{"q": 12.5, "p": 486.5, "t": 6081.25}], "n": "ž"}, parsed)


class PruneTest(unittest.TestCase):
    def test_prune_drops_null_and_empty(self) -> None:
        self.assertEqual({"a": 1, "c": {"d": False}, "e": 0}, prune({"a": 1, "b": None, "c": {"d": False, "x": None}, "e": 0, "f": [], "g": {}}))


class CanonicalTest(unittest.TestCase):
    def setUp(self) -> None:
        self.expected = expected_for(data(DATA_DOMESTIC))

    def test_added_fields_and_positions(self) -> None:
        canonical = dataset_canonical(self.expected, "0101-domestic-goods.pdf", "2026-09-02T08:01:00")
        keys = list(canonical)
        self.assertEqual(keys.index("formatVersion") + 1, keys.index("source"))
        self.assertEqual(keys.index("_resolve") - 1, keys.index("attachments"))
        self.assertEqual({"kind": "aiExtraction", "extractedAt": "2026-09-02T08:01:00+00:00", "confidence": D("1.0"),
                          "promptVersion": "corpus"}, canonical["source"])
        self.assertEqual([{"filename": "0101-domestic-goods.pdf", "mimeType": "application/pdf", "kind": "original", "ref": "att:1"}],
                         canonical["attachments"])
        self.assertEqual(["tag", "tagSource", "tagConfidence", "rowExceptions"], list(canonical["_resolve"]["contentTag"]))
        self.assertEqual("llm", canonical["_resolve"]["contentTag"]["tagSource"])
        self.assertEqual(D("1.0"), canonical["_resolve"]["contentTag"]["tagConfidence"])

    def test_nothing_else_changes_and_strip_is_inverse(self) -> None:
        before = copy.deepcopy(self.expected)
        canonical = dataset_canonical(self.expected, "x.pdf", "2026-09-02T08:01:00")
        self.assertEqual(before, self.expected, "vstup se nemění")
        self.assertEqual(self.expected, strip_dataset_additions(canonical))
        self.assertIsNone(canonical["customer"]["contact"]["name"], "null zůstává null")

    def test_analysis_json_shape(self) -> None:
        canonical = dataset_canonical(self.expected, "x.pdf", "2026-09-02T08:01:00")
        out = analysis_json(canonical, "Faktura X")
        self.assertEqual(["overall_confidence", "message_classification", "document"], list(out))
        self.assertEqual({"primary_type": "invoiceReceived", "confidence": D("1.0"), "title": "Faktura X"}, out["message_classification"])
        self.assertEqual("invoiceReceived", out["document"]["doc_type"])
        self.assertNotIn("_resolve", out["document"]["extracted_json"])
        self.assertIn("source", out["document"]["extracted_json"])
        self.assertEqual("att:1", out["document"]["extracted_json"]["attachments"][0]["ref"])


class MessageTest(unittest.TestCase):
    def test_domestic_invoice_message(self) -> None:
        cell = cell_for(data(DATA_DOMESTIC), "0101-domestic-goods")
        m = message_for_cell(cell, expected_for(data(DATA_DOMESTIC)), PDF)

        self.assertEqual("MSG-CORPUS-VP-0101", m["messageId"])
        self.assertEqual("Faktura FV-2026-0101", m["subject"])
        self.assertEqual("fakturace@dodavatelna.example", m["senderEmail"])
        self.assertEqual("Dodavatelna s.r.o.", m["senderName"])
        self.assertEqual("Dodavatelna s.r.o.", m["partnerName"])
        self.assertEqual("2026-09-02T08:00:00", m["receivedAt"], "den vystavení + 1, 08:00")
        self.assertEqual(m["receivedAt"], m["created"])
        self.assertEqual((2, 20, 30, "invoiceReceived", "ai"),
                         (m["sourceType"], m["docState"], m["analysisState"], m["primaryType"], m["primaryTypeSource"]))
        self.assertIn("v příloze zasíláme fakturu FV-2026-0101", m["bodyPlain"])
        self.assertTrue(m["aiTitle"].startswith("Faktura FV-2026-0101 — Dodavatelna s.r.o., "))
        self.assertTrue(m["aiTitle"].endswith(" Kč"))
        self.assertNotIn("\u202f", m["aiTitle"])
        self.assertEqual([{"file": "0101-domestic-goods.pdf", "name": "0101-domestic-goods.pdf", "mimeType": "application/pdf",
                           "size": len(PDF), "sha256": hashlib.sha256(PDF).hexdigest(), "order": 1}], m["attachments"])
        a = m["analyses"][0]
        self.assertEqual("2026-09-02T08:01:00", a["analyzedAt"])
        self.assertEqual(a["analyzedAt"], a["created"])
        self.assertEqual(("corpus-ideal", "corpus", "czech_general", 2, D("1.0"), "goods.stock"),
                         (a["modelName"], a["promptVersion"], a["profile"], a["status"], a["confidence"], a["contentTag"]))
        self.assertEqual((0, 0, 0, D("0.0")), (a["tokensInput"], a["tokensOutput"], a["durationMs"], a["costUsd"]))
        self.assertNotIn("resolution", a)
        # Bloby jako po průchodu PHP: celé hodnoty int, řádek analýzy drží 1.0 / 0.0.
        self.assertEqual(1, a["canonicalJson"]["source"]["confidence"])
        self.assertIs(int, type(a["analysisJson"]["overall_confidence"]))
        self.assertEqual(D("486.50"), a["canonicalJson"]["rows"][0]["unitPrice"])
        self.assertEqual(self_expected_strip(a["canonicalJson"]), expected_for(data(DATA_DOMESTIC)))
        self.assertEqual(["analyzedAt", "status", "modelName", "promptVersion", "profile", "confidence", "proposedType",
                          "contentTag", "tokensInput", "tokensOutput", "durationMs", "costUsd", "created", "analysisJson",
                          "canonicalJson"], list(a))

    def test_english_template_and_foreign_currency(self) -> None:
        cell = cell_for(data(DATA_EU_SERVICES), "0502-eu-services")
        m = message_for_cell(cell, expected_for(data(DATA_EU_SERVICES)), PDF)

        self.assertEqual("MSG-CORPUS-VP-0502", m["messageId"])
        self.assertEqual("Invoice INV-2026-0388", m["subject"])
        self.assertIn("please find attached invoice INV-2026-0388", m["bodyPlain"])
        self.assertTrue(m["aiTitle"].endswith(" EUR"), m["aiTitle"])

    def test_receipt_is_sent_to_self(self) -> None:
        receipt = data(DATA_RECEIPT)
        identities = copy.deepcopy(IDENTITIES)
        identities["suppliers"]["cz-payer"]["contact"]["email"] = None
        cell = next(c for c in cells_for(receipt, "0901-receipt", identities) if getattr(c, "profile", None) == "non-payer")
        m = message_for_cell(cell, expected_for(receipt, "non-payer"), PDF)

        self.assertEqual("MSG-CORPUS-NP-0901", m["messageId"])
        self.assertEqual("Účtenka 3. 9. 2026", m["subject"])
        self.assertEqual("doklady@non-payer.example", m["senderEmail"])
        self.assertEqual("Zkušebna s.r.o.", m["senderName"], "jméno vlastní firmy profilu")
        self.assertEqual("Dodavatelna s.r.o.", m["partnerName"])
        self.assertTrue(m["aiTitle"].startswith("Účtenka 0000123456 — "))

    def test_message_id_fits_column(self) -> None:
        for profile in PROFILES:
            self.assertLessEqual(len(message_id(profile, "1002-long-document")), 30)


class OwnPersonTest(unittest.TestCase):
    def test_company_person_without_nulls(self) -> None:
        person = own_person(IDENTITIES["profiles"]["non-payer"])
        self.assertEqual(["format", "formatVersion", "personType", "country", "companyId", "name", "status", "addresses", "applyOptions"],
                         list(person))
        self.assertEqual({"isOwn": True, "docState": 40}, person["status"])
        self.assertEqual(1, person["addresses"][0]["addressType"])
        self.assertEqual("CZ07123451", own_person(IDENTITIES["profiles"]["vat-payer"])["vatId"])


class ProfileTest(unittest.TestCase):
    def write_profile(self, root: Path, **meta_changes) -> Path:
        meta = {"name": "cz-test", "title": "T", "description": "d", "created": "2026-10-14T00:00:00Z",
                "provision": {"accountingItems": "all"}}
        meta.update(meta_changes)
        (root / "setup").mkdir(parents=True, exist_ok=True)
        (root / "profile.jsonc").write_text(json.dumps(meta), encoding="utf-8")
        (root / "setup" / "settings.jsonc").write_text(json.dumps({
            "format": "shpd.dataset.setup.v1", "table": "settings",
            "rows": [{"key": "economy.accountChart", "value": "default"}],
        }), encoding="utf-8")
        (root / "setup" / "bank_accounts.jsonc").write_text(json.dumps({
            "format": "shpd.dataset.setup.v1", "table": "bank_accounts",
            "rows": [{"code": "BU1", "account_number": "1803729684/0100", "iban": "CZ7201000000001803729684"}],
        }), encoding="utf-8")
        return root

    def test_valid_profile_loads(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            profile = load_profile(self.write_profile(Path(tmp)), "x")
            self.assertEqual("cz-test", profile.name)
            self.assertEqual(["bank_accounts", "settings"], sorted(profile.setup))
            self.assertEqual({"accountingItems": "all"}, profile.provision)

    def test_invalid_profiles(self) -> None:
        cases = [
            {"name": "CZ Test"}, {"created": "2026-10-14"}, {"provision": {"foo": 1}},
            {"provision": {"accountingItems": []}}, {"extra": 1},
            {"provision": {"serverAi": True}},   # zaniklo (#126 D9)
        ]
        for changes in cases:
            with tempfile.TemporaryDirectory() as tmp, self.assertRaises(DatasetBuildError, msg=str(changes)):
                load_profile(self.write_profile(Path(tmp), **changes), "x")

    def test_bank_account_checks(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self.write_profile(Path(tmp))
            (root / "setup" / "bank_accounts.jsonc").write_text(json.dumps({
                "format": "shpd.dataset.setup.v1", "table": "bank_accounts",
                "rows": [{"code": "BU1", "account_number": "1803729685/0100", "iban": "CZ7201000000001803729684"}],
            }), encoding="utf-8")
            with self.assertRaises(DatasetBuildError) as ctx:
                load_profile(root, "x")
            self.assertIn("mod 11", str(ctx.exception))


class BuildDatasetTest(unittest.TestCase):
    """Miniaturní korpus v temp složce: dva datové soubory, očekávání
    a PDF z fixtur → sada profilu vat-payer; druhý build = stejné bajty."""

    def build(self, root: Path) -> tuple[Path, dict]:
        docs = {"0101-domestic-goods": data(DATA_DOMESTIC), "0502-eu-services": data(DATA_EU_SERVICES)}
        corpus = root / "corpus"
        for stem, doc in docs.items():
            for profile in PROFILES:
                cells = [c for c in cells_for(doc, stem, IDENTITIES) if getattr(c, "profile", None) == profile and hasattr(c, "data")]
                if not cells:
                    continue
                (corpus / "expected" / profile).mkdir(parents=True, exist_ok=True)
                (corpus / "out" / profile).mkdir(parents=True, exist_ok=True)
                from invoicegen.expected import dumps_expected
                (corpus / "expected" / profile / f"{stem}.json").write_text(dumps_expected(expected_for(doc, profile)), encoding="utf-8")
                (corpus / "out" / profile / f"{stem}.pdf").write_bytes(PDF + stem.encode())
        snapshot = root / "approved" / "general-ledger.json"
        snapshot.parent.mkdir(exist_ok=True)
        snapshot.write_text('{"reportId": "economy.accounting.generalLedger"}\n', encoding="utf-8")
        profile = Profile(key="vat-payer", name="cz-vat-payer", title="T", description=None, created="2026-10-14T00:00:00Z",
                          provision={"accountingItems": ["568201"]},
                          setup={"settings": {"format": "shpd.dataset.setup.v1", "table": "settings", "rows": [
                              {"key": "economy.accountChart", "value": "default"}, {"key": "economy.vatAgenda", "value": True}]}},
                          ledger_snapshot=snapshot)
        out = root / "datasets" / "vat-payer"
        summary = build_dataset(profile, IDENTITIES["profiles"]["vat-payer"], docs, IDENTITIES, corpus, out,
                                reporting=REPORTING, projection_path=PROJECTION_PATH)
        return out, {"summary": summary, "docs": docs}

    @staticmethod
    def snapshot(out: Path) -> dict[str, bytes]:
        return {str(p.relative_to(out)): p.read_bytes() for p in sorted(out.rglob("*")) if p.is_file()}

    def test_layout_order_and_determinism(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out, info = self.build(Path(tmp))
            first = self.snapshot(out)
            self.assertEqual(2, info["summary"].messages)
            self.assertEqual(0, info["summary"].skipped)
            self.assertEqual([
                "expected/costs.json",
                "expected/general-ledger.json",
                "expected/messages.json",
                "expected/projection.jsonc",
                "expected/vat.json",
                "mail/0001-msg-corpus-vp-0101.files/0101-domestic-goods.pdf",
                "mail/0001-msg-corpus-vp-0101.jsonc",
                "mail/0002-msg-corpus-vp-0502.files/0502-eu-services.pdf",
                "mail/0002-msg-corpus-vp-0502.jsonc",
                "manifest.jsonc",
                "persons/0001-testovna-s-r-o.jsonc",
                "setup/settings.jsonc",
            ], sorted(first))
            manifest = json.loads(first["manifest.jsonc"])
            self.assertEqual(["format", "name", "title", "dateMode", "created", "country", "profile", "provision", "counts"], list(manifest))
            self.assertEqual({"setup": 1, "persons": 1, "mail": 2}, manifest["counts"], "expected/ není sekce sady")
            self.assertEqual("cz", manifest["country"])
            self.assertEqual({"accountingItems": ["568201"]}, manifest["provision"])
            self.assertTrue(first["manifest.jsonc"].endswith(b"}\n"))

            self.assertEqual(PROJECTION_PATH.read_bytes(), first["expected/projection.jsonc"])
            self.assertEqual(b'{"reportId": "economy.accounting.generalLedger"}\n', first["expected/general-ledger.json"])
            vat = json.loads(first["expected/vat.json"], parse_float=Decimal)
            costs = json.loads(first["expected/costs.json"], parse_float=Decimal)
            self.assertEqual(["MSG-CORPUS-VP-0101", "MSG-CORPUS-VP-0502"], [r["messageId"] for r in vat], "pořadí zpráv")
            self.assertEqual(["MSG-CORPUS-VP-0101", "MSG-CORPUS-VP-0502"], [r["messageId"] for r in costs])
            self.assertEqual(["40"], list(vat[0]["return"]))
            self.assertEqual("EUR", vat[1]["currency"])
            self.assertEqual(expected_for(info["docs"]["0101-domestic-goods"])["totals"]["totalBase"], costs[0]["cost"])
            self.assertTrue(first["expected/vat.json"].startswith(b"[\n  {\n    \"messageId\""), "zapisovač očekávání (2 mezery)")
            self.assertEqual([
                {"messageId": "MSG-CORPUS-VP-0101", "stem": "0101-domestic-goods", "variant": "domestic-goods", "form": "pdf"},
                {"messageId": "MSG-CORPUS-VP-0502", "stem": "0502-eu-services", "variant": "eu-services", "form": "pdfIsdoc"},
            ], json.loads(first["expected/messages.json"]), "messages.json: zpráva → buňka korpusu (F4)")

            (out / "mail" / "stale.jsonc").write_text("x", encoding="utf-8")
            (out / "expected" / "stale.json").write_text("x", encoding="utf-8")
            out2, _ = self.build(Path(tmp))
            self.assertEqual(first, self.snapshot(out2), "druhý build = stejné bajty, cizí soubor ve spravované sekci zmizí")

    def test_non_payer_has_empty_vat_expectations_and_gross_costs(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.build(root)
            docs = {"0101-domestic-goods": data(DATA_DOMESTIC)}
            corpus = root / "corpus"
            (corpus / "expected" / "non-payer").mkdir(parents=True, exist_ok=True)
            (corpus / "out" / "non-payer").mkdir(parents=True, exist_ok=True)
            from invoicegen.expected import dumps_expected
            expected = expected_for(docs["0101-domestic-goods"], "non-payer")
            (corpus / "expected" / "non-payer" / "0101-domestic-goods.json").write_text(dumps_expected(expected), encoding="utf-8")
            (corpus / "out" / "non-payer" / "0101-domestic-goods.pdf").write_bytes(PDF)
            profile = Profile(key="non-payer", name="cz-non-payer", title="T", description=None, created="2026-10-14T00:00:00Z",
                              provision={}, setup={"settings": {"format": "shpd.dataset.setup.v1", "table": "settings",
                                                                "rows": [{"key": "economy.vatAgenda", "value": False}]}})
            out = root / "datasets" / "non-payer"
            build_dataset(profile, IDENTITIES["profiles"]["non-payer"], docs, IDENTITIES, corpus, out,
                          reporting=REPORTING, projection_path=PROJECTION_PATH)
            self.assertEqual(b"[]\n", (out / "expected" / "vat.json").read_bytes())
            self.assertFalse((out / "expected" / "general-ledger.json").exists(), "bez schváleného snímku se nic nekopíruje")
            costs = json.loads((out / "expected" / "costs.json").read_text(encoding="utf-8"), parse_float=Decimal)
            self.assertEqual(expected["totals"]["totalBase"] + expected["totals"]["totalVat"], costs[0]["cost"])

    def test_missing_expected_is_an_error(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out, info = self.build(Path(tmp))
            (Path(tmp) / "corpus" / "expected" / "vat-payer" / "0101-domestic-goods.json").unlink()
            profile = Profile(key="vat-payer", name="cz-vat-payer", title="T", description=None, created="2026-10-14T00:00:00Z",
                              provision={}, setup={})
            with self.assertRaises(DatasetBuildError):
                build_dataset(profile, IDENTITIES["profiles"]["vat-payer"], info["docs"], IDENTITIES, Path(tmp) / "corpus", out,
                              reporting=REPORTING, projection_path=PROJECTION_PATH)
