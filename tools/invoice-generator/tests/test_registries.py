from __future__ import annotations

import json
import unittest

from invoicegen.registries import (
    ARES_BASE, RegistryUnavailable, ares_ico_exists, ares_name_hits, update_verified_at, verify_identities,
    vies_vat_valid,
)
from tests.fixtures import IDENTITIES


class FakeFetch:
    """Odpovědi podle URL; zaznamenává volání."""

    def __init__(self, responses: dict[str, tuple[int, object]]):
        self.responses = responses
        self.calls: list[tuple[str, str]] = []

    def __call__(self, url, method, body, headers):
        self.calls.append((method, url))
        for prefix, (status, payload) in self.responses.items():
            if url.startswith(prefix) or url == prefix:
                data = payload if isinstance(payload, bytes) else json.dumps(payload).encode("utf-8")
                return status, data
        raise AssertionError(f"neočekávaný dotaz {url}")


class AresTest(unittest.TestCase):
    def test_ico(self) -> None:
        self.assertFalse(ares_ico_exists("08227985", FakeFetch({ARES_BASE: (404, {"kod": "NENALEZENO"})})))
        self.assertTrue(ares_ico_exists("08227985", FakeFetch({ARES_BASE: (200, {"ico": "08227985"})})))
        with self.assertRaises(ValueError):
            ares_ico_exists("x", FakeFetch({ARES_BASE: (400, {"kod": "CHYBA"})}))
        with self.assertRaises(RegistryUnavailable):
            ares_ico_exists("08227985", FakeFetch({ARES_BASE: (429, b"")}))
        with self.assertRaises(RegistryUnavailable):
            ares_ico_exists("08227985", FakeFetch({ARES_BASE: (503, b"")}))

    def test_name(self) -> None:
        fetch = FakeFetch({ARES_BASE + "/vyhledat": (200, {"pocetCelkem": 0, "ekonomickeSubjekty": []})})
        self.assertEqual(0, ares_name_hits("Tvarokov s.r.o.", fetch))
        self.assertEqual(("POST", ARES_BASE + "/vyhledat"), fetch.calls[0])
        self.assertEqual(3, ares_name_hits("x", FakeFetch({ARES_BASE: (200, {"pocetCelkem": 3})})))


class ViesTest(unittest.TestCase):
    def test_answers(self) -> None:
        vies = "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/DE/vat/315700795"
        self.assertFalse(vies_vat_valid("DE315700795", FakeFetch({vies: (200, {"isValid": False, "userError": "INVALID"})})))
        self.assertFalse(vies_vat_valid("DE315700795", FakeFetch({vies: (200, {"isValid": False, "userError": "VALID"})})))
        self.assertTrue(vies_vat_valid("DE315700795", FakeFetch({vies: (200, {"isValid": True, "userError": "VALID"})})))
        with self.assertRaises(RegistryUnavailable):
            vies_vat_valid("DE315700795", FakeFetch({vies: (200, {"isValid": False, "userError": "MS_UNAVAILABLE"})}))
        with self.assertRaises(ValueError):
            vies_vat_valid("DE315700795", FakeFetch({vies: (200, {"isValid": False, "userError": "INVALID_INPUT"})}))
        with self.assertRaises(RegistryUnavailable):
            vies_vat_valid("DE315700795", FakeFetch({vies: (500, b"")}))


class VerifyTest(unittest.TestCase):
    def test_aggregates_and_skips_us(self) -> None:
        fetch = FakeFetch({
            ARES_BASE + "/vyhledat": (200, {"pocetCelkem": 0}),
            ARES_BASE: (404, {}),
            "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/": (200, {"isValid": False, "userError": "VALID"}),
        })
        sleeps: list[float] = []
        checks = verify_identities(IDENTITIES, fetch, sleep=sleeps.append)
        self.assertTrue(all(c.ok for c in checks), [c for c in checks if not c.ok])
        kinds = [(c.subject, c.kind) for c in checks]
        self.assertIn(("profiles.vat-payer", "ARES IČO"), kinds)
        self.assertIn(("profiles.vat-payer", "VIES"), kinds)
        self.assertIn(("suppliers.de", "VIES"), kinds)
        self.assertNotIn(("suppliers.de", "ARES IČO"), kinds)
        self.assertFalse(any(c.subject == "suppliers.us" for c in checks))
        self.assertNotIn(("profiles.non-payer", "VIES"), kinds)
        self.assertEqual(len(checks), len(sleeps))

    def test_failures_reported(self) -> None:
        fetch = FakeFetch({
            ARES_BASE + "/vyhledat": (200, {"pocetCelkem": 2}),
            ARES_BASE: (200, {}),
            "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/": (200, {"isValid": True, "userError": "VALID"}),
        })
        checks = verify_identities(IDENTITIES, fetch, sleep=lambda _: None)
        self.assertTrue(all(not c.ok for c in checks))
        self.assertIn("2 zásahů", [c.detail for c in checks])
        self.assertIn("platné", [c.detail for c in checks])

    def test_unavailable_propagates(self) -> None:
        with self.assertRaises(RegistryUnavailable):
            verify_identities(IDENTITIES, FakeFetch({ARES_BASE: (503, b"")}), sleep=lambda _: None)


class VerifiedAtTest(unittest.TestCase):
    def test_replaces_single_line(self) -> None:
        text = '// komentář\n{\n\t"verifiedAt": "2026-09-01", // datum\n\t"profiles": {},\n}\n'
        out = update_verified_at(text, "2026-10-14")
        self.assertEqual('// komentář\n{\n\t"verifiedAt": "2026-10-14", // datum\n\t"profiles": {},\n}\n', out)
        with self.assertRaises(ValueError):
            update_verified_at('{"x": 1}', "2026-10-14")
        with self.assertRaises(ValueError):
            update_verified_at('"verifiedAt": "2026-01-01"\n"verifiedAt": "2026-01-02"\n', "2026-10-14")


if __name__ == "__main__":
    unittest.main()
