from __future__ import annotations

import unittest

from invoicegen.collation import czech_key, robust_order, sort_czech


class CzechKeyTest(unittest.TestCase):
    def test_czech_alphabet_order(self) -> None:
        self.assertEqual(
            ["9 kol", "Cena", "Cukrárna", "Čas", "Hrad", "Chalupa", "Rýdl", "Řezník", "Účetní", "Velkoobchod", "Zelený", "Žába"],
            sort_czech(["Žába", "Zelený", "Chalupa", "Hrad", "Čas", "Cukrárna", "Cena", "Řezník", "Rýdl", "Účetní", "Velkoobchod", "9 kol"]),
        )

    def test_accents_and_case_are_secondary(self) -> None:
        self.assertEqual(czech_key("abc")[0], czech_key("ÁBČ".replace("Č", "C"))[0])
        self.assertNotEqual(czech_key("abc"), czech_key("ábc"))
        self.assertEqual(czech_key("Novák")[0], czech_key("novak")[0])
        self.assertLess(czech_key("Novák"), czech_key("Novák s.r.o."), "kratší text je prefixem — první")

    def test_robust_order_flags_fragile_pairs(self) -> None:
        self.assertEqual([], robust_order(["Alfa s.r.o.", "Beta a.s.", "Gama"]))
        problems = robust_order(["Novák", "Novak"])
        self.assertEqual(1, len(problems))
        self.assertIn("diakritikou", problems[0])
        problems = robust_order(["Alfa s.r.o.", "Alfa Soft s.r.o."])
        self.assertEqual(1, len(problems))
        self.assertIn("interpunkce", problems[0])


if __name__ == "__main__":
    unittest.main()
