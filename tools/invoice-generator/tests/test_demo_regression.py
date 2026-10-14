"""Demo kolekce `demo/invoices/` musí dávat stále stejné HTML a součty.

Demo je vázané na video (tasks/demo-invoices.md) — jeho výstup se nesmí
změnit ani při rozšiřování sdíleného generátoru. Hashe HTML byly sejmuty
před vytažením kódu do `invoicegen` (tasks/testing-phase1.md, krok 1).
Oprava: změní-li se demo šablona nebo data vědomě, přepočítej hashe tímto
testem (vypíše je ve zprávě selhání) a přegeneruj demo PDF.
"""

from __future__ import annotations

import hashlib
import importlib.util
import unittest
from decimal import Decimal
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
DEMO_DIR = REPO_ROOT / "demo" / "invoices"

EXPECTED = {
    "0001-softaro-it-pausal": (
        "978b3e7f7f2fbacdf62d31940de65960b47117754744ec45bd85d28861761a1d",
        ("8500.00", "1785.00", "0", "10285.00"),
    ),
    "0002-dubretta-material": (
        "2b218bc41bf27b57f693e0f9f5202fa6f149b496eb5fc2f1d53f8f725ac34d6b",
        ("16998.45", "3569.67", "0", "20568.12"),
    ),
    "0003-papyria-kancelar": (
        "2657e0595f89d5f4173f17fff4b4acac55351e329c341fa8697471297a602966",
        ("4207.00", "809.49", "0", "5016.49"),
    ),
    "0004-energima-elektrina": (
        "307903e7a17fac7bcf3a2660f629e28e7a1edf6f88e09e22a55263ad6618d97b",
        ("3527.68", "740.81", "-0.49", "4268"),
    ),
}


def load_demo_build():
    spec = importlib.util.spec_from_file_location("demo_build", DEMO_DIR / "build.py")
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class DemoRegressionTest(unittest.TestCase):
    def test_html_and_totals_unchanged(self) -> None:
        build = load_demo_build()
        identities = build.load_jsonc(DEMO_DIR / "suppliers.jsonc")
        own = identities["ownCompany"]
        templates = {
            p.stem: p.read_text(encoding="utf-8")
            for p in sorted((DEMO_DIR / "templates").glob("*.html"))
        }
        data_files = sorted((DEMO_DIR / "data").glob("*.jsonc"))
        self.assertEqual(sorted(EXPECTED), [p.stem for p in data_files])

        for path in data_files:
            invoice = build.load_jsonc(path)
            build.validate_header(invoice)
            supplier = identities["suppliers"][invoice["supplier"]]
            computed = build.compute(invoice)
            rendered = build.render_html(
                templates[invoice["template"]],
                build.build_values(invoice, supplier, own, computed),
            )
            digest = hashlib.sha256(rendered.encode("utf-8")).hexdigest()
            expected_hash, expected_totals = EXPECTED[path.stem]
            totals = tuple(
                str(computed[k]) for k in ("totalBase", "totalVat", "totalRounding", "totalAmount")
            )
            self.assertEqual(expected_totals, totals, path.stem)
            self.assertIsInstance(computed["totalAmount"], Decimal)
            self.assertEqual(expected_hash, digest, f"{path.stem}: HTML se změnilo (sha256 {digest})")


if __name__ == "__main__":
    unittest.main()
