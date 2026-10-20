from __future__ import annotations

import hashlib
import tempfile
import unittest
from pathlib import Path

from invoicegen.datasetio import (
    SECTIONS, DatasetBuildError, attachment_entry, dumps_dataset, file_name, manifest, sync_tree,
)


class FileNameTest(unittest.TestCase):
    def test_ordinal_and_slug_like_php(self) -> None:
        self.assertEqual("0001-tvarokov-s-r-o.jsonc", file_name(1, "Tvarokov s.r.o."))
        self.assertEqual("0042-koncept-invno.jsonc", file_name(42, "koncept invno"))
        self.assertEqual("0007-1260001", file_name(7, "1260001", ""))


class ManifestTest(unittest.TestCase):
    def test_key_order_with_all_optional_fields(self) -> None:
        m = manifest(name="it-servis", title="Demo", description="popis", created="2026-10-20T00:00:00Z",
                     country="cz", profile="vat-payer", provision={"accountingItems": "all"},
                     counts={s: 0 for s in SECTIONS})
        self.assertEqual(["format", "name", "title", "description", "dateMode", "created", "country", "profile",
                          "provision", "counts"], list(m))
        self.assertEqual("shpd.dataset.v1", m["format"])
        self.assertEqual("fixed", m["dateMode"])
        self.assertEqual(list(SECTIONS), list(m["counts"]))

    def test_optional_fields_are_omitted(self) -> None:
        m = manifest(name="x", title="X", description=None, created="2026-10-20T00:00:00Z",
                     counts={"setup": 3, "persons": 1, "mail": 2})
        self.assertEqual(["format", "name", "title", "dateMode", "created", "counts"], list(m))
        m = manifest(name="x", title="X", description=None, created="2026-10-20T00:00:00Z", provision={}, counts={})
        self.assertNotIn("provision", m)
        self.assertEqual("{}", dumps_dataset(m["counts"]).strip())


class AttachmentEntryTest(unittest.TestCase):
    def test_message_attachment_has_order(self) -> None:
        pdf = b"%PDF-1.4 test"
        self.assertEqual(
            {"file": "a.pdf", "name": "a.pdf", "mimeType": "application/pdf", "size": len(pdf),
             "sha256": hashlib.sha256(pdf).hexdigest(), "order": 1},
            attachment_entry("a.pdf", pdf, 1),
        )

    def test_registry_attachment_without_order(self) -> None:
        entry = attachment_entry("smlouva.pdf", b"x")
        self.assertEqual(["file", "name", "mimeType", "size", "sha256"], list(entry))


class SyncTreeTest(unittest.TestCase):
    MANAGED = ("manifest.jsonc", "docs", "mail")

    def test_writes_only_changed_removes_stale_keeps_unmanaged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            (out / "expected").mkdir()
            (out / "expected" / "keep.json").write_text("[]\n")
            (out / "docs").mkdir()
            (out / "docs" / "0001-stale.jsonc").write_text("{}\n")
            (out / "mail" / "0001-x.files").mkdir(parents=True)
            (out / "mail" / "0001-x.files" / "old.pdf").write_bytes(b"old")

            files = {
                "manifest.jsonc": b"{}\n",
                "docs/0001-a.jsonc": b"a\n",
                "mail/0001-x.jsonc": b"m\n",
                "mail/0001-x.files/new.pdf": b"pdf",
            }
            first = sync_tree(out, files, managed=self.MANAGED)
            self.assertEqual(tuple(sorted(files)), first.written)
            self.assertEqual((), first.unchanged)
            self.assertEqual(("docs/0001-stale.jsonc", "mail/0001-x.files/old.pdf"), first.removed)
            self.assertTrue((out / "expected" / "keep.json").is_file(), "nespravované soubory zůstávají")

            second = sync_tree(out, files, managed=self.MANAGED)
            self.assertEqual((), second.written)
            self.assertEqual(tuple(sorted(files)), second.unchanged)
            self.assertEqual((), second.removed)

            changed = dict(files)
            changed["docs/0001-a.jsonc"] = b"b\n"
            del changed["mail/0001-x.files/new.pdf"]
            del changed["mail/0001-x.jsonc"]
            third = sync_tree(out, changed, managed=self.MANAGED)
            self.assertEqual(("docs/0001-a.jsonc",), third.written)
            self.assertEqual(("mail/0001-x.files/new.pdf", "mail/0001-x.jsonc"), third.removed)
            self.assertFalse((out / "mail").exists(), "prázdná sekce zmizí i se sidecar složkou")

    def test_rejects_paths_outside_managed_or_escaping(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            with self.assertRaises(DatasetBuildError):
                sync_tree(out, {"expected/x.json": b""}, managed=self.MANAGED)
            with self.assertRaises(DatasetBuildError):
                sync_tree(out, {"docs/../x": b""}, managed=self.MANAGED)
            self.assertEqual([], list(out.iterdir()), "při odmítnutí se nic nezapíše")


if __name__ == "__main__":
    unittest.main()
