from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from invoicegen.pdfcache import PdfCache


class FakeRenderer:
    def __init__(self) -> None:
        self.calls = 0

    def __call__(self, html: str, *, fields: dict[str, str]) -> bytes:
        self.calls += 1
        return f"%PDF-fake {self.calls} {len(html)}".encode()


class PdfCacheTest(unittest.TestCase):
    def test_render_only_when_html_changes_or_file_missing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "set"
            state = Path(tmp) / "build-state.json"
            renderer = FakeRenderer()

            cache = PdfCache(state, render=renderer)
            first = cache.resolve("mail:A", "<html>a</html>", out, "mail/0001-a.files/a.pdf")
            (out / "mail/0001-a.files").mkdir(parents=True)
            (out / "mail/0001-a.files/a.pdf").write_bytes(first)
            cache.save()
            self.assertEqual(1, renderer.calls)
            self.assertEqual({"mail:A": {"hash": json.loads(state.read_text())["mail:A"]["hash"], "file": "mail/0001-a.files/a.pdf"}},
                             json.loads(state.read_text()))

            cache = PdfCache(state, render=renderer)
            again = cache.resolve("mail:A", "<html>a</html>", out, "mail/0002-a.files/a.pdf")
            self.assertEqual(first, again, "stejné HTML → bajty ze složky, i po přečíslování")
            self.assertEqual(1, renderer.calls)
            self.assertEqual(["mail:A"], cache.reused)

            cache = PdfCache(state, render=renderer)
            changed = cache.resolve("mail:A", "<html>b</html>", out, "mail/0001-a.files/a.pdf")
            self.assertNotEqual(first, changed)
            self.assertEqual(2, renderer.calls)

            (out / "mail/0001-a.files/a.pdf").unlink()
            cache = PdfCache(state, render=renderer)
            cache.resolve("mail:A", "<html>a</html>", out, "mail/0001-a.files/a.pdf")
            self.assertEqual(3, renderer.calls, "chybějící soubor se renderuje znovu")

            cache = PdfCache(state, render=renderer, force=True)
            cache.resolve("mail:A", "<html>a</html>", out, "mail/0001-a.files/a.pdf")
            self.assertEqual(4, renderer.calls)
            cache.save()
            self.assertNotIn("mail:B", json.loads(state.read_text()))


if __name__ == "__main__":
    unittest.main()
