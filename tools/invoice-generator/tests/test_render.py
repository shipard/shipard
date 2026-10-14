from __future__ import annotations

import unittest
from decimal import Decimal

from invoicegen.compute import compute
from invoicegen.render import (
    GOTENBERG_FIELDS, NNBSP, fmt_date, fmt_iban, fmt_money, fmt_pct, fmt_phone, fmt_qty, fmt_zip,
    pdf_options_from_html, render_hash, render_html, render_kv_rows, render_rows, render_vat_recap,
)

D = Decimal


class FormatTest(unittest.TestCase):
    def test_money(self) -> None:
        self.assertEqual(f"4{NNBSP}268,00", fmt_money(D("4268")))
        self.assertEqual("-0,49", fmt_money(D("-0.49")))
        self.assertEqual("1,234.56", fmt_money(D("1234.56"), "en"))
        self.assertEqual("0,13", fmt_money(D("0.125")))

    def test_qty(self) -> None:
        self.assertEqual("0,643", fmt_qty(D("0.643")))
        self.assertEqual("12,5", fmt_qty(D("12.5")))
        self.assertEqual("1", fmt_qty(D("1")))
        self.assertEqual("100", fmt_qty(D("1E+2")))
        self.assertEqual("1.25", fmt_qty(D("1.250"), "en"))

    def test_date_pct_iban(self) -> None:
        self.assertEqual("2. 9. 2026", fmt_date("2026-09-02"))
        self.assertEqual("2026-09-02", fmt_date("2026-09-02", "en"))
        self.assertEqual(f"21{NNBSP}%", fmt_pct(21))
        self.assertEqual("21%", fmt_pct(21, "en"))
        self.assertEqual("CZ85 2010 0000 0023 0841 7557", fmt_iban("CZ8520100000002308417557"))

    def test_zip_phone(self) -> None:
        self.assertEqual("760 01", fmt_zip("76001", "cz"))
        self.assertEqual("10115", fmt_zip("10115", "de"))
        self.assertEqual("+420 577 123 456", fmt_phone("+420577123456"))
        self.assertEqual("+49 301 234 5678", fmt_phone("+493012345678"))
        self.assertEqual("+1 415 555 0132", fmt_phone("+14155550132"))
        self.assertEqual("+420 603 111 22", fmt_phone("+42060311122"))


class RenderTest(unittest.TestCase):
    computed = compute({"rows": [
        {"text": "Deska <dub>", "unit": "m2", "qty": D("12.5"), "unitPrice": D("486.50"), "vatPct": 21},
        {"text": "Doprava", "unit": "ks", "qty": 1, "unitPrice": D("650.00"), "vatPct": 21, "discountPct": 10},
    ]})

    def test_rows_demo_layout(self) -> None:
        html = render_rows(self.computed)
        self.assertEqual(
            '\t\t\t<tr class="row"><td class="c-text">Deska &lt;dub&gt;</td><td class="c-qty">12,5</td>'
            f'<td class="c-unit">m²</td><td class="c-price">486,50</td><td class="c-vat">21{NNBSP}%</td>'
            f'<td class="c-total">6{NNBSP}081,25</td></tr>',
            html.split("\n")[0],
        )

    def test_rows_options(self) -> None:
        html = render_rows(self.computed, unit_display=None, discount_column=True, vat_column=False, locale="en")
        lines = html.split("\n")
        self.assertIn('<td class="c-unit">m2</td>', lines[0])
        self.assertIn('<td class="c-disc"></td>', lines[0])
        self.assertIn('<td class="c-disc">10%</td>', lines[1])
        self.assertNotIn("c-vat", html)
        self.assertIn('<td class="c-total">585.00</td>', lines[1])

    def test_vat_recap_and_kv(self) -> None:
        html = render_vat_recap(self.computed)
        self.assertEqual(
            f'\t\t\t<tr class="vr"><td class="v-pct">21{NNBSP}%</td><td class="v-base">6{NNBSP}666,25</td>'
            f'<td class="v-tax">1{NNBSP}399,91</td><td class="v-total">8{NNBSP}066,16</td></tr>', html)
        self.assertEqual('\t\t\t<tr><td class="l">IBAN</td><td class="v">CZ…</td></tr>',
                         render_kv_rows([("IBAN", "CZ…")]))

    def test_render_html(self) -> None:
        self.assertEqual("a-b", render_html("{{x}}-{{y}}", {"x": "a", "y": "b", "z": "unused"}))
        with self.assertRaises(KeyError):
            render_html("{{missing}}", {})
        with self.assertRaises(AssertionError):
            render_html("{{x}}", {"x": "{{nested}}"})

    def test_pdf_options(self) -> None:
        self.assertEqual(GOTENBERG_FIELDS, pdf_options_from_html("<!DOCTYPE html><html></html>"))
        fields = pdf_options_from_html("<!DOCTYPE html>\n<!-- pdf: paperWidth=80mm paperHeight=200mm marginTop=4mm -->")
        self.assertEqual("80mm", fields["paperWidth"])
        self.assertEqual("200mm", fields["paperHeight"])
        self.assertEqual("4mm", fields["marginTop"])
        self.assertEqual("0", fields["marginLeft"])
        with self.assertRaises(AssertionError):
            pdf_options_from_html("<!-- pdf: paperSize=A4 -->")

    def test_render_hash(self) -> None:
        a = render_hash("<html>", GOTENBERG_FIELDS)
        self.assertEqual(a, render_hash("<html>", dict(GOTENBERG_FIELDS)))
        self.assertNotEqual(a, render_hash("<html>", {**GOTENBERG_FIELDS, "paperHeight": "200mm"}))
        self.assertNotEqual(a, render_hash("<html> ", GOTENBERG_FIELDS))


if __name__ == "__main__":
    unittest.main()
