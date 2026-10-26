<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Values;

use Shipard\Module\World\Validations\Bic;
use Shipard\Module\World\Validations\DomesticAccount;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\ValueRulesRegistry;
use Shipard\Module\World\Validations\VatId;

/**
 * Validace hodnot canonicalu `shpd.docs.document` (#118 D3, D13): čistá
 * funkce `canonical → list<issue>` ve tvaru `DocumentValidator`
 * (`{severity, path, code, message}`), vše `warning` — nevalidní hodnota
 * zůstává, apply neblokuje, pásmo návrhu stropuje na review (D15).
 *
 * Kontroluje tvary po {@see CanonicalValueNormalizer::normalizeShapes()}
 * (hodnotu nemění): persistované canonicaly z doby před normalizací tak
 * nedostanou nález za mezery v IBAN nebo PSČ. Záznamy
 * `_resolve.valueRepairs` čte z původního vstupu a dělá z nich
 * `bank_account_repaired`. Telefon, e-mail, web a `taxId` nálezy
 * nevyrábí (D12; `taxId` u cz = DIČ, nález by se zdvojil).
 *
 * Volá ji `DocumentValidator::validate()` (`/validate`, `/preview`,
 * `/apply`) a strop pásma `AnalysisConfidenceResolver::capBand()`.
 */
final class CanonicalValueValidator
{
    public const COMPANY_ID_INVALID = 'company_id_invalid';
    public const VAT_ID_INVALID = 'vat_id_invalid';
    public const VAT_ID_COMPANY_ID_MISMATCH = 'vat_id_company_id_mismatch';
    public const ZIP_INVALID = 'zip_invalid';
    public const ACCOUNT_NUMBER_INVALID = 'account_number_invalid';
    public const IBAN_INVALID = 'iban_invalid';
    public const BIC_INVALID = 'bic_invalid';
    public const IBAN_ACCOUNT_MISMATCH = 'iban_account_mismatch';
    public const BANK_ACCOUNT_REPAIRED = 'bank_account_repaired';

    public const CODES = [
        self::COMPANY_ID_INVALID, self::VAT_ID_INVALID, self::VAT_ID_COMPANY_ID_MISMATCH, self::ZIP_INVALID,
        self::ACCOUNT_NUMBER_INVALID, self::IBAN_INVALID, self::BIC_INVALID, self::IBAN_ACCOUNT_MISMATCH,
        self::BANK_ACCOUNT_REPAIRED,
    ];

    private const PARTY_LABELS = [
        'supplier'     => 'dodavatele',
        'customer'     => 'odběratele',
        'balanceParty' => 'plátce',
    ];

    /** Popis tvaru čísla DIČ za prefixem u států s třídou (do zprávy nálezu). */
    private const VAT_NUMBER_RULES = [
        'CZ' => '8–10 číslic',
        'SK' => '10 číslic',
        'DE' => '9 číslic s kontrolní číslicí',
    ];

    /**
     * @param array<string, mixed> $canonical
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    public static function validate(array $canonical): array
    {
        $issues = [];
        $shaped = CanonicalValueNormalizer::normalizeShapes($canonical);
        foreach (CanonicalValueNormalizer::PARTIES as $key) {
            if (is_array($shaped[$key] ?? null)) {
                self::validateParty($key, $shaped[$key], $issues);
            }
        }
        foreach (CanonicalValueNormalizer::repairsOf($canonical) as $repair) {
            $issues[] = self::repairIssue($repair);
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $party normalizovaná strana
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     */
    private static function validateParty(string $key, array $party, array &$issues): void
    {
        $label = self::PARTY_LABELS[$key] ?? $key;
        $country = CanonicalValueNormalizer::partyCountry($party);
        $rules = ValueRulesRegistry::forCountry($country);

        $companyId = self::text($party['companyId'] ?? null);
        $companyIdValid = $companyId !== null && $rules->isValidCompanyId($companyId);
        if ($companyId !== null && !$companyIdValid) {
            $reason = preg_match('/^[0-9]{8}$/', $companyId) === 1
                ? 'nemá platný kontrolní součet'
                : 'nemá tvar osmi číslic';
            $issues[] = self::warning("{$key}.companyId", self::COMPANY_ID_INVALID, "IČO {$label} „{$companyId}“ {$reason}.");
        }

        $vatId = self::text($party['vatId'] ?? null);
        $vatIdValid = $vatId !== null && VatId::isValid($vatId);
        if ($vatId !== null && !$vatIdValid) {
            $prefix = VatId::prefixOf($vatId);
            $reason = $prefix !== null && isset(self::VAT_NUMBER_RULES[$prefix])
                ? "neodpovídá pravidlu státu {$prefix} (" . self::VAT_NUMBER_RULES[$prefix] . ')'
                : 'nemá tvar: prefix státu a 2–13 znaků';
            $issues[] = self::warning("{$key}.vatId", self::VAT_ID_INVALID, "DIČ {$label} „{$vatId}“ {$reason}.");
        }
        if ($vatIdValid && $companyIdValid) {
            $implied = $rules->companyIdFromVatId((string) $vatId);
            if ($implied !== null && $implied !== $companyId) {
                $issues[] = self::warning(
                    "{$key}.vatId",
                    self::VAT_ID_COMPANY_ID_MISMATCH,
                    "DIČ {$label} „{$vatId}“ neodpovídá IČO „{$companyId}“ — osmimístné DIČ je {$rules->vatIdPrefix()} + IČO.",
                );
            }
        }

        $zip = self::text($party['address']['zip'] ?? null);
        if ($zip !== null) {
            $zipCountry = self::text($party['address']['country'] ?? null) ?? $country;
            $zipRules = ValueRulesRegistry::forCountry($zipCountry);
            if (!$zipRules->isValidZip($zip)) {
                $issues[] = self::warning("{$key}.address.zip", self::ZIP_INVALID, "PSČ {$label} „{$zip}“ nemá pět číslic ({$zipRules->country()}).");
            }
        }

        if (is_array($party['bankAccount'] ?? null)) {
            self::validateBankAccount($key, $label, $party['bankAccount'], $rules->supportsDomesticAccount(), $issues);
        }
    }

    /**
     * @param array<string, mixed> $bank
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     */
    private static function validateBankAccount(string $key, string $label, array $bank, bool $partyDomestic, array &$issues): void
    {
        $accountNumber = self::text($bank['accountNumber'] ?? null);
        $account = $accountNumber !== null ? DomesticAccount::parse($accountNumber) : null;
        $accountValid = false;
        if ($accountNumber !== null) {
            if ($account !== null) {
                $accountValid = $account->isValid();
                if (!$accountValid) {
                    $issues[] = self::warning("{$key}.bankAccount.accountNumber", self::ACCOUNT_NUMBER_INVALID, "Číslo účtu {$label} „{$accountNumber}“ nesplňuje kontrolu mod 11.");
                }
            } elseif ($partyDomestic) {
                $issues[] = self::warning("{$key}.bankAccount.accountNumber", self::ACCOUNT_NUMBER_INVALID, "Číslo účtu {$label} „{$accountNumber}“ nemá tvar [předčíslí-]číslo/kód banky.");
            }
        }

        $iban = self::text($bank['iban'] ?? null);
        $ibanValid = $iban !== null && Iban::isValid($iban);
        if ($iban !== null && !$ibanValid) {
            if (!Iban::hasShape($iban)) {
                $reason = 'nemá tvar IBAN';
            } elseif (!Iban::checksumOk($iban)) {
                $reason = 'nemá platnou kontrolní číslici';
            } else {
                $reason = 'neodpovídá pravidlu státu ' . strtoupper((string) Iban::countryOf($iban)) . ' (délka nebo číslo účtu uvnitř)';
            }
            $issues[] = self::warning("{$key}.bankAccount.iban", self::IBAN_INVALID, "IBAN {$label} „{$iban}“ {$reason}.");
        }

        $bic = self::text($bank['bic'] ?? null);
        if ($bic !== null && !Bic::isValid($bic)) {
            $issues[] = self::warning("{$key}.bankAccount.bic", self::BIC_INVALID, "BIC {$label} „{$bic}“ nemá tvar 8 nebo 11 znaků.");
        }

        if ($ibanValid && $accountValid && $account !== null) {
            $fromIban = ValueRulesRegistry::forIban((string) $iban)->domesticAccountFromIban((string) $iban);
            if ($fromIban !== null && $fromIban->toBban() !== $account->toBban()) {
                $issues[] = self::warning(
                    "{$key}.bankAccount.iban",
                    self::IBAN_ACCOUNT_MISMATCH,
                    "IBAN {$label} „{$iban}“ neodpovídá číslu účtu „{$accountNumber}“ — podle IBAN je účet „{$fromIban}“.",
                );
            }
        }
    }

    /**
     * @param array{path: string, from: string, to: string, basis: string} $repair
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private static function repairIssue(array $repair): array
    {
        $partyKey = explode('.', $repair['path'])[0];
        $label = self::PARTY_LABELS[$partyKey] ?? $partyKey;
        $message = $repair['basis'] === CanonicalValueNormalizer::BASIS_IBAN
            ? "Číslo účtu {$label} „{$repair['from']}“ nebylo platné — doplněno podle IBAN: „{$repair['to']}“."
            : "IBAN {$label} „{$repair['from']}“ nebyl platný — doplněn podle čísla účtu: „{$repair['to']}“.";
        return self::warning($repair['path'], self::BANK_ACCOUNT_REPAIRED, $message);
    }

    /** @return array{severity: string, path: string, code: string, message: string} */
    private static function warning(string $path, string $code, string $message): array
    {
        return ['severity' => 'warning', 'path' => $path, 'code' => $code, 'message' => $message];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}

