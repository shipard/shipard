<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Výchozí účetní datum dokladu (#103 D78, docs/exchange-rates.md §4):
 * přijatá faktura patří do období DUZP (k němu se dohledává i kurz),
 * ostatní typy k datu vystavení. Jedno pravidlo pro
 * {@see DocDocument::applyDateDefaults()} (hlavička),
 * {@see \Shipard\Module\Core\Exchange\Document\DocumentApplier} (canonical:
 * účetní datum i datum kurzu) a exporter kurzovních lístků (návrhy z analýz
 * zpráv — wiring přes closure v `DatasetDumpCommand`, protože
 * economy.exchangeRates na docs.core nezávisí).
 */
final class AccountingDateRule
{
    /** Typy dokladů hlavičky (`doc_type`), jejichž výchozí účetní datum je DUZP. */
    public const DUZP_DOC_TYPES = ['invni'];

    /** Totéž pro `docType` výměnného formátu `shpd.docs.document.v1`. */
    public const DUZP_CANONICAL_DOC_TYPES = ['invoiceReceived'];

    /** Má typ dokladu hlavičky výchozí účetní datum = DUZP? */
    public static function usesDuzp(string $docType): bool
    {
        return in_array($docType, self::DUZP_DOC_TYPES, true);
    }

    /**
     * Účetní datum canonicalu: explicitní `dates.accountingDate`, jinak DUZP
     * (`dates.taxPointDate`) u přijaté faktury, jinak datum vystavení.
     *
     * @param array<string, mixed> $canonical
     */
    public static function fromCanonical(array $canonical): ?string
    {
        $dates = is_array($canonical['dates'] ?? null) ? $canonical['dates'] : [];
        $explicit = self::date($dates['accountingDate'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        if (in_array((string) ($canonical['docType'] ?? ''), self::DUZP_CANONICAL_DOC_TYPES, true)) {
            $duzp = self::date($dates['taxPointDate'] ?? null);
            if ($duzp !== null) {
                return $duzp;
            }
        }
        return self::date($dates['issueDate'] ?? null);
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
