from __future__ import annotations

import unittest
from decimal import Decimal

from invoicegen.jsonc import loads_jsonc


class JsoncTest(unittest.TestCase):
    def test_comments_and_trailing_commas(self) -> None:
        doc = loads_jsonc('// hlavička\n{\n\t"a": 1, // za hodnotou\n\t"b": [1, 2,],\n}\n')
        self.assertEqual({"a": 1, "b": [1, 2]}, doc)

    def test_double_slash_inside_string_kept(self) -> None:
        doc = loads_jsonc('{"url": "www.x.example//cesta", "e": "a\\"b//c"}')
        self.assertEqual("www.x.example//cesta", doc["url"])
        self.assertEqual('a"b//c', doc["e"])

    def test_numbers(self) -> None:
        doc = loads_jsonc('{"qty": 1, "price": 486.50, "neg": -0.49}')
        self.assertIs(int, type(doc["qty"]))
        self.assertEqual(Decimal("486.50"), doc["price"])
        self.assertIs(Decimal, type(doc["price"]))
        self.assertEqual(Decimal("-0.49"), doc["neg"])


if __name__ == "__main__":
    unittest.main()
