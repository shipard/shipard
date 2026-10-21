from __future__ import annotations

import hashlib
import tempfile
import unittest
from datetime import date
from decimal import Decimal
from pathlib import Path

from invoicegen.demo import MANAGED, build_demo_dataset
from invoicegen.jsonc import load_jsonc
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"


def fake_render(html: str, *, fields: dict[str, str]) -> bytes:
    return b"%PDF-1.4 fake " + hashlib.sha256(html.encode("utf-8")).hexdigest().encode()


def snapshot(out: Path) -> dict[str, bytes]:
    return {p.relative_to(out).as_posix(): p.read_bytes() for p in out.rglob("*") if p.is_file()}


class BuildDemoDatasetTest(unittest.TestCase):
    def test_layout_determinism_and_cleanup(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "it-servis"
            state = Path(tmp) / "state.json"
            out.mkdir()
            (out / "docs").mkdir()
            (out / "docs" / "9999-stale.jsonc").write_text("{}\n")
            (out / "expected").mkdir()
            (out / "expected" / "keep.json").write_text("[]\n")

            first = build_demo_dataset(STORY_DIR, out, repo_root=REPO_ROOT, state_path=state, render=fake_render)
            self.assertEqual({"setup": 10, "exchangeRates": 0, "persons": 25, "items": 12, "workOrders": 15, "docs": 291,
                              "bank": 20, "registry": 6, "mail": 62}, first.counts)
            self.assertEqual(67, first.rendered, "61 faktur dodavatelů (vč. 10 v angličtině) + 6 dokumentů spisovny")
            self.assertGreaterEqual(first.economy.margin, Decimal("0.08"), "souhrn ekonomiky je součástí sestavení")
            self.assertIn("docs/9999-stale.jsonc", first.sync.removed)
            self.assertTrue((out / "expected" / "keep.json").is_file(), "nespravované soubory zůstávají")
            manifest = load_jsonc(out / "manifest.jsonc")
            self.assertEqual(["format", "name", "title", "description", "dateMode", "created", "country", "provision", "counts"],
                             list(manifest))
            self.assertEqual("2026-10-20T00:00:00Z", manifest["created"])
            self.assertEqual({"accountingItems": "all"}, manifest["provision"])
            self.assertEqual(list(first.counts), list(manifest["counts"]))
            files = snapshot(out)
            self.assertIn("persons/0001-autodoprava-kopretina-s-r-o.jsonc", files)
            self.assertIn("persons/0025-zeleny-terminal-s-r-o.jsonc", files)
            self.assertIn("docs/0001-31pok2600001.jsonc", files)
            self.assertIn("docs/0155-0000000155.jsonc", files, "koncept se zástupným číslem podle pořadí vložení")
            self.assertIn("docs/0291-12260001.jsonc", files)
            self.assertTrue(any(k.startswith("mail/") and k.endswith("/INV-2026-18407.pdf") for k in files),
                            "PDF faktury dodavatele v EUR")
            self.assertIn("setup/accounts.jsonc", files, "analytika 221200 pro BU2")
            self.assertIn("setup/work_order_kinds.jsonc", files, "druhy zakázek (fáze 2b)")
            self.assertIn("workOrders/0001-p0001.jsonc", files, "zakázky podle čísla: projekty P před paušály S")
            self.assertIn("workOrders/0003-s0001.jsonc", files)
            self.assertIn("workOrders/0015-s0013.jsonc", files)
            work_order = load_jsonc(out / "workOrders" / "0003-s0001.jsonc")
            self.assertEqual("shpd.dataset.workOrder.v1", work_order["format"])
            self.assertEqual(["format", "workOrder", "rows", "periods"], list(work_order))
            self.assertEqual(10, len(work_order["periods"]), "evidence úplná: leden–říjen")
            self.assertEqual("1260001", work_order["periods"][0]["docNumber"])
            medvidek = load_jsonc(out / "workOrders" / "0004-s0002.jsonc")
            self.assertEqual("!0000000155", medvidek["periods"][-1]["docNumber"], "koncept měsíce kotvy zástupným číslem")
            concept = load_jsonc(out / "docs" / "0155-0000000155.jsonc")
            self.assertEqual({"kind": "workOrder"}, concept["source"])
            self.assertEqual({"workOrder": "S0002"}, concept["dimensions"])
            project = load_jsonc(out / "workOrders" / "0001-p0001.jsonc")
            self.assertEqual(["format", "workOrder"], list(project), "projekt bez předpisu a období")
            self.assertEqual(("finished", "2026-04-22"), (project["workOrder"]["state"], project["workOrder"]["dateEnd"]))
            self.assertIn("bank/0001-bu1-2026-01.jsonc", files)
            self.assertIn("bank/0010-bu1-2026-10.jsonc", files)
            self.assertIn("bank/0011-bu2-2026-01.jsonc", files)
            self.assertIn("mail/0001-msg-demo-patro-2601.files/NP-26-031.pdf", files)
            self.assertTrue(any(k.startswith("registry/0001-") and k.endswith(".pdf") for k in files))
            for name in MANAGED:
                self.assertTrue((out / name).exists(), name)

            second = build_demo_dataset(STORY_DIR, out, repo_root=REPO_ROOT, state_path=state, render=fake_render)
            self.assertEqual((0, 67), (second.rendered, second.reused))
            self.assertEqual((), second.sync.written)
            self.assertEqual((), second.sync.removed)
            self.assertEqual(files, snapshot(out), "druhý běh = stejné bajty")

    def test_anchor_shift_builds_the_same_number_of_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "shift"
            summary = build_demo_dataset(STORY_DIR, out, repo_root=REPO_ROOT, anchor=date(2027, 1, 20),
                                         state_path=Path(tmp) / "state.json", render=fake_render)
            self.assertEqual({"setup": 10, "exchangeRates": 0, "persons": 25, "items": 12, "workOrders": 15, "docs": 291,
                              "bank": 20, "registry": 6, "mail": 62}, summary.counts)
            manifest = load_jsonc(out / "manifest.jsonc")
            self.assertEqual("2027-01-20T00:00:00Z", manifest["created"])
            self.assertTrue((out / "bank" / "0010-bu1-2027-01.jsonc").is_file(), "poslední výpis v měsíci kotvy")
            self.assertEqual("2026-04-01", load_jsonc(out / "setup" / "vat_registrations.jsonc")["rows"][0]["valid_from"])
            self.assertTrue((out / "docs").glob("*-1270001.jsonc"), "číslování nového fiskálního roku")


if __name__ == "__main__":
    unittest.main()
