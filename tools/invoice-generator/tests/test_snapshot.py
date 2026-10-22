from __future__ import annotations

import unittest
from decimal import Decimal

from invoicegen.datasetio import DatasetBuildError, attachment_entry, dumps_dataset
from invoicegen.snapshot import analysis_record, classification_json, message_record

D = Decimal
PDF = b"%PDF-1.4 snapshot"


class MessageRecordTest(unittest.TestCase):
    def test_processed_message_with_target_and_resolution(self) -> None:
        analysis = analysis_record(
            analyzed_at="2026-03-06T08:01:00", proposed_type="invoiceReceived", content_tag="it.hosting",
            analysis_json={"overall_confidence": D("1.0")}, canonical_json={"docType": "invoiceReceived", "x": None},
            resolution=40, resolved_at="2026-03-06T09:00:00",
        )
        m = message_record(
            message_id="MSG-DEMO-HOST-02", subject="Faktura 2026030042", sender_email="fakturace@hosting.example",
            sender_name="Hosting s.r.o.", partner_name="Hosting s.r.o.", received_at="2026-03-06T08:00:00",
            body="Dobrý den", primary_type="invoiceReceived", doc_state=40, ai_title="Faktura 2026030042 — Hosting",
            target={"table": "docs_core_heads", "docNumber": "2260003"},
            attachments=[attachment_entry("f.pdf", PDF, 1)], analyses=[analysis],
        )
        self.assertEqual(["format", "formatVersion", "messageId", "mailbox", "primaryType", "primaryTypeSource", "subject",
                          "senderEmail", "senderName", "partnerName", "receivedAt", "bodyPlain", "sourceType", "docState",
                          "analysisState", "aiTitle", "created", "target", "attachments", "analyses"], list(m))
        self.assertEqual(("default", "ai", 2, 30, m["receivedAt"]),
                         (m["mailbox"], m["primaryTypeSource"], m["sourceType"], m["analysisState"], m["created"]))
        a = m["analyses"][0]
        self.assertEqual(["analyzedAt", "status", "modelName", "promptVersion", "profile", "confidence", "proposedType",
                          "contentTag", "resolution", "resolvedAt", "tokensInput", "tokensOutput", "durationMs", "costUsd",
                          "created", "analysisJson", "canonicalJson"], list(a))
        self.assertEqual((40, "2026-03-06T09:00:00", a["analyzedAt"]), (a["resolution"], a["resolvedAt"], a["created"]))
        # Bloby: čísla jako po PHP, null zůstává.
        self.assertIs(int, type(a["analysisJson"]["overall_confidence"]))
        self.assertIn("x", a["canonicalJson"])
        self.assertIn('"x": null', dumps_dataset(a))

    def test_open_proposal_has_no_resolution_or_target(self) -> None:
        analysis = analysis_record(analyzed_at="2026-10-18T09:13:00", proposed_type="invoiceReceived",
                                   analysis_json={}, canonical_json={"docType": "invoiceReceived"})
        m = message_record(message_id="MSG-DEMO-TELCO-00", subject="Faktura", sender_email="a@b.example",
                           received_at="2026-10-18T09:12:00", primary_type="invoiceReceived", doc_state=20,
                           analyses=[analysis])
        self.assertNotIn("target", m)
        self.assertNotIn("attachments", m)
        self.assertNotIn("senderName", m)
        self.assertEqual(20, m["docState"])
        self.assertNotIn("resolution", m["analyses"][0])
        self.assertNotIn("resolvedAt", m["analyses"][0])

    def test_other_message_with_attention_and_deadline(self) -> None:
        classification = classification_json("Expirace domén", attention="action",
                                             action_note="Prodloužit 3 domény.", due_date="2026-11-01",
                                             party={"name": "Registr Domén s.r.o.", "companyId": None, "email": "p@r.example"})
        self.assertEqual(["overall_confidence", "message_classification", "document"], list(classification))
        self.assertIsNone(classification["document"])
        self.assertEqual(["primary_type", "confidence", "title", "attention", "action_note", "due_date", "party"],
                         list(classification["message_classification"]))
        m = message_record(message_id="MSG-DEMO-DOMENY", subject="Expirace domén", sender_email="p@r.example",
                           received_at="2026-10-18T10:40:00", primary_type="other", doc_state=10,
                           ai_title="Expirace domén", attention="action", action_note="Prodloužit 3 domény.",
                           action_due="2026-11-01",
                           analyses=[analysis_record(analyzed_at="2026-10-18T10:41:00", proposed_type="other",
                                                     analysis_json=classification)])
        self.assertEqual(["format", "formatVersion", "messageId", "mailbox", "primaryType", "primaryTypeSource", "subject",
                          "senderEmail", "receivedAt", "sourceType", "docState", "analysisState", "aiTitle", "attention",
                          "actionNote", "actionDue", "created", "analyses"], list(m))
        a = m["analyses"][0]
        self.assertNotIn("canonicalJson", a)
        self.assertNotIn("contentTag", a)
        self.assertIn('"document": null', dumps_dataset(a))

    def test_message_id_length_is_checked(self) -> None:
        with self.assertRaises(DatasetBuildError):
            message_record(message_id="M" * 31, subject="s", sender_email="a@b.example",
                           received_at="2026-01-01T00:00:00", primary_type="other", doc_state=10)


if __name__ == "__main__":
    unittest.main()
