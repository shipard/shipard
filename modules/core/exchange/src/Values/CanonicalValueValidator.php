<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Values;

use Shipard\Module\World\Validations\ValueChecks;
use Shipard\Module\World\Validations\ValueFinding;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Validace hodnot canonicalu `shpd.docs.document` (#118 D3, D13): čistá
 * funkce `canonical → list<issue>` ve tvaru `DocumentValidator`
 * (`{severity, path, code, message}`), vše `warning` — nevalidní hodnota
 * zůstává, apply neblokuje, pásmo návrhu stropuje na review (D15).
 *
 * Kontroluje tvary po {@see CanonicalValueNormalizer::normalizeShapes()}
 * (hodnotu nemění): persistované canonicaly z doby před normalizací tak
 * nedostanou nález za mezery v IBAN nebo PSČ. Kontroly i texty nálezů
 * dává knihovna ({@see ValueChecks}, {@see ValueFinding}) — tytéž, které
 * hlásí formuláře adresáře (D19); podezření `account_number_checksum`
 * (mod 11, D30) je tu warning jako všechno ostatní a stropuje pásmo
 * stejně. Záznamy `_resolve.valueRepairs` čte
 * z původního vstupu a dělá z nich `bank_account_repaired`. Telefon,
 * e-mail, web a `taxId` nálezy nevyrábí (D12; `taxId` u cz = DIČ, nález
 * by se zdvojil).
 *
 * Volá ji `DocumentValidator::validate()` (`/validate`, `/preview`,
 * `/apply`) a strop pásma `AnalysisConfidenceResolver::capBand()`.
 */
final class CanonicalValueValidator
{
    public const COMPANY_ID_INVALID = ValueFinding::COMPANY_ID_INVALID;
    public const VAT_ID_INVALID = ValueFinding::VAT_ID_INVALID;
    public const VAT_ID_COMPANY_ID_MISMATCH = ValueFinding::VAT_ID_COMPANY_ID_MISMATCH;
    public const ZIP_INVALID = ValueFinding::ZIP_INVALID;
    public const ACCOUNT_NUMBER_INVALID = ValueFinding::ACCOUNT_NUMBER_INVALID;
    public const IBAN_INVALID = ValueFinding::IBAN_INVALID;
    public const BIC_INVALID = ValueFinding::BIC_INVALID;
    public const IBAN_ACCOUNT_MISMATCH = ValueFinding::IBAN_ACCOUNT_MISMATCH;
    public const ACCOUNT_NUMBER_CHECKSUM = ValueFinding::ACCOUNT_NUMBER_CHECKSUM;
    public const BANK_ACCOUNT_REPAIRED = 'bank_account_repaired';

    public const CODES = [
        self::COMPANY_ID_INVALID, self::VAT_ID_INVALID, self::VAT_ID_COMPANY_ID_MISMATCH, self::ZIP_INVALID,
        self::ACCOUNT_NUMBER_INVALID, self::IBAN_INVALID, self::BIC_INVALID, self::IBAN_ACCOUNT_MISMATCH,
        self::ACCOUNT_NUMBER_CHECKSUM, self::BANK_ACCOUNT_REPAIRED,
    ];

    private const PARTY_LABELS = [
        'supplier'     => 'dodavatele',
        'customer'     => 'odběratele',
        'balanceParty' => 'plátce',
    ];

    /** Cesty polí strany podle druhu hodnoty nálezu. */
    private const PATHS = [
        'companyId'     => 'companyId',
        'vatId'         => 'vatId',
        'zip'           => 'address.zip',
        'accountNumber' => 'bankAccount.accountNumber',
        'iban'          => 'bankAccount.iban',
        'bic'           => 'bankAccount.bic',
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

        $findings = ValueChecks::identity(self::text($party['companyId'] ?? null), self::text($party['vatId'] ?? null), $rules);

        $zip = self::text($party['address']['zip'] ?? null);
        if ($zip !== null) {
            $zipRules = ValueRulesRegistry::forCountry(self::text($party['address']['country'] ?? null) ?? $country);
            $findings[] = ValueChecks::zip($zip, $zipRules);
        }

        if (is_array($party['bankAccount'] ?? null)) {
            $bank = $party['bankAccount'];
            $findings = array_merge($findings, ValueChecks::bankAccount(
                self::text($bank['accountNumber'] ?? null),
                self::text($bank['iban'] ?? null),
                self::text($bank['bic'] ?? null),
                $rules->supportsDomesticAccount(),
            ));
        }

        foreach (array_filter($findings) as $finding) {
            $issues[] = self::warning("{$key}." . self::PATHS[$finding->kind], $finding->code, $finding->message($label));
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
