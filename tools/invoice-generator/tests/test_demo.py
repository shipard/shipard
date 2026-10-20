from __future__ import annotations

import hashlib
import tempfile
import unittest
from datetime import date
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
            self.assertEqual({"setup": 7, "exchangeRates": 0, "persons": 24, "items": 12, "docs": 240, "registry": 6, "mail": 52},
                             first.counts)
            self.assertEqual(57, first.rendered)
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
            self.assertIn("persons/0024-zeleny-terminal-s-r-o.jsonc", files)
            self.assertIn("docs/0001-31pok2600001.jsonc", files)
            self.assertIn("docs/0117-0000000117.jsonc", files, "koncept se zástupným číslem podle pořadí vložení")
            self.assertIn("docs/0240-12260001.jsonc", files)
            self.assertIn("mail/0001-msg-demo-patro-2601.files/NP-26-031.pdf", files)
            self.assertTrue(any(k.startswith("registry/0001-") and k.endswith(".pdf") for k in files))
            for name in MANAGED:
                self.assertTrue((out / name).exists(), name)

            second = build_demo_dataset(STORY_DIR, out, repo_root=REPO_ROOT, state_path=state, render=fake_render)
            self.assertEqual((0, 57), (second.rendered, second.reused))
            self.assertEqual((), second.sync.written)
            self.assertEqual((), second.sync.removed)
            self.assertEqual(files, snapshot(out), "druhý běh = stejné bajty")

    def test_anchor_shift_builds_the_same_number_of_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "shift"
            summary = build_demo_dataset(STORY_DIR, out, repo_root=REPO_ROOT, anchor=date(2027, 1, 20),
                                         state_path=Path(tmp) / "state.json", render=fake_render)
            self.assertEqual({"setup": 7, "exchangeRates": 0, "persons": 24, "items": 12, "docs": 240, "registry": 6, "mail": 52},
                             summary.counts)
            manifest = load_jsonc(out / "manifest.jsonc")
            self.assertEqual("2027-01-20T00:00:00Z", manifest["created"])
            self.assertEqual("2026-04-01", load_jsonc(out / "setup" / "vat_registrations.jsonc")["rows"][0]["valid_from"])
            self.assertTrue((out / "docs").glob("*-1270001.jsonc"), "číslování nového fiskálního roku")


if __name__ == "__main__":
    unittest.main()
