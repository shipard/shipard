from __future__ import annotations

import copy
import unittest

from invoicegen.checks import (
    account_valid, bic_valid, de_vat_check_digit, de_vat_valid, example_domain,
    iban_check_digits, iban_from_account, iban_matches_account, iban_valid, ico_valid,
    phone_canonical, phone_parts, phone_unassigned, validate_identities, vat_id_format_valid,
)
from tests.fixtures import DEMO_RESERVED, IDENTITIES


class ChecksumTest(unittest.TestCase):
    def test_ico(self) -> None:
        self.assertTrue(ico_valid("52654150"))
        self.assertTrue(ico_valid("07123451"))
        self.assertFalse(ico_valid("52654151"))
        self.assertFalse(ico_valid("5265415"))

    def test_account_and_iban(self) -> None:
        self.assertTrue(account_valid("2308417557/2010"))
        self.assertTrue(account_valid("19-2000145399/0800"))
        self.assertFalse(account_valid("2308417558/2010"))
        self.assertFalse(account_valid("2308417557"))
        self.assertTrue(iban_valid("CZ8520100000002308417557"))
        self.assertFalse(iban_valid("CZ8520100000002308417558"))
        self.assertTrue(iban_matches_account("CZ8520100000002308417557", "2308417557/2010"))
        self.assertFalse(iban_matches_account("CZ8520100000002308417557", "1156072849/0100"))
        self.assertEqual("CZ8520100000002308417557", iban_from_account("2308417557/2010"))
        self.assertEqual("CZ6508000000192000145399", iban_from_account("19-2000145399/0800"))

    def test_iban_check_digits_foreign(self) -> None:
        bban = "100700000532013000"
        iban = "DE" + iban_check_digits("DE", bban) + bban
        self.assertTrue(iban_valid(iban))
        self.assertEqual("DE72100700000532013000", iban)

    def test_de_vat(self) -> None:
        self.assertTrue(de_vat_valid("DE136695976"))  # referenční číslo ISO 7064 MOD 11,10
        self.assertFalse(de_vat_valid("DE136695975"))
        self.assertFalse(de_vat_valid("DE13669597"))
        self.assertEqual(6, de_vat_check_digit("13669597"))

    def test_vat_id_format(self) -> None:
        self.assertTrue(vat_id_format_valid("CZ52654150"))
        self.assertTrue(vat_id_format_valid("DE136695976"))
        self.assertTrue(vat_id_format_valid("ATU12345678"))
        self.assertFalse(vat_id_format_valid("DE1366959"))
        self.assertFalse(vat_id_format_valid("cz52654150"))
        self.assertFalse(vat_id_format_valid("CZ 52654150"))

    def test_bic_domain_phone(self) -> None:
        self.assertTrue(bic_valid("GIBACZPX"))
        self.assertTrue(bic_valid("TESTDEBBXXX"))
        self.assertFalse(bic_valid("GIBACZ"))
        self.assertTrue(example_domain("fakturace@dodavatel.example"))
        self.assertTrue(example_domain("www.dodavatel.example"))
        self.assertTrue(example_domain("www.dodavatel.example/faktury"))
        self.assertFalse(example_domain("fakturace@dodavatel.cz"))
        self.assertFalse(example_domain("Fakturace@dodavatel.example"))
        self.assertFalse(example_domain("x@.example"))
        self.assertTrue(phone_canonical("+420577123456"))
        self.assertFalse(phone_canonical("+420 577 123 456"))
        self.assertFalse(phone_canonical("577123456"))

    def test_phone_parts_and_unassigned(self) -> None:
        self.assertEqual(("420", "577123456"), phone_parts("+420577123456"))
        self.assertEqual(("49", "30123456"), phone_parts("+4930123456"))
        self.assertEqual(("1", "4155550199"), phone_parts("+14155550199"))
        self.assertIsNone(phone_parts("+420 577 123 456"))
        self.assertTrue(phone_unassigned("+420000201304"))
        self.assertTrue(phone_unassigned("+490008874410"))
        self.assertFalse(phone_unassigned("+420577123456"))   # národní číslo bez 000
        self.assertFalse(phone_unassigned("+4930123456"))     # národní číslo bez 000
        self.assertTrue(phone_unassigned("+14155550199"))     # vyhrazený rozsah 555-01xx
        self.assertFalse(phone_unassigned("+14155551234"))    # 555-12xx může být přidělené
        self.assertFalse(phone_unassigned("577123456"))


class ValidateIdentitiesTest(unittest.TestCase):
    def test_fixture_is_clean(self) -> None:
        self.assertEqual([], validate_identities(IDENTITIES, reserved=DEMO_RESERVED))

    def mutated(self, path: str, value) -> dict:
        ids = copy.deepcopy(IDENTITIES)
        node = ids
        keys = path.split(".")
        for key in keys[:-1]:
            node = node[key]
        node[keys[-1]] = value
        return ids

    def test_one_problem_per_mutation(self) -> None:
        cases = {
            "suppliers.cz-payer.companyId": "06388452",
            "suppliers.cz-payer.vatId": "CZ06388452",
            "suppliers.cz-payer.taxId": None,
            "suppliers.cz-payer.bankAccount.iban": "CZ8503000000005638829718",
            "suppliers.cz-payer.address.zip": "616 00",
            "suppliers.cz-payer.contact.email": "fakturace@dodavatelna.cz",
            "suppliers.cz-payer.contact.phone": "+420 577 123 456",
            "suppliers.cz-nonpayer.contact.phone": "+420603111222",
            "suppliers.us.contact.phone": "+14155551234",
            "suppliers.cz-payer.contact.web": "https://www.dodavatelna.example/",
            "suppliers.cz-payer.courtRegistration": "Spisová značka C 99999 vedená u Krajského soudu v Brně",
            "suppliers.cz-nonpayer.vatPayer": True,
            "suppliers.de.vatId": "DE293847561",
            "suppliers.de.taxId": "DE293847560",   # opis VAT ID místo národního čísla (D115)

            "suppliers.us.address.houseNumber": "500",
            "suppliers.us.vatId": "US123",
            "profiles.vat-payer.address.country": "sk",
        }
        for path, value in cases.items():
            with self.subTest(path=path):
                problems = validate_identities(self.mutated(path, value))
                self.assertGreaterEqual(len(problems), 1, path)
                self.assertTrue(all(path.split(".")[1] in p for p in problems), problems)

    def test_duplicates_and_reserved(self) -> None:
        dup = self.mutated("suppliers.cz-nonpayer.companyId", "06388451")
        self.assertTrue(any("shodné s" in p for p in validate_identities(dup)))
        clash = self.mutated("profiles.vat-payer.companyId", "52654150")
        clash["profiles"]["vat-payer"]["vatId"] = clash["profiles"]["vat-payer"]["taxId"] = "CZ52654150"
        self.assertEqual([], validate_identities(clash))
        self.assertTrue(any("demo" in p for p in validate_identities(clash, reserved=DEMO_RESERVED)))

    def test_verified_at(self) -> None:
        self.assertTrue(any("verifiedAt" in p for p in validate_identities(self.mutated("verifiedAt", "včera"))))


if __name__ == "__main__":
    unittest.main()
