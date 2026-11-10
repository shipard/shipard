<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * DPH kontext hlavičky dokladu pro formuláře dětských tabulek (řádek
 * dokladu, řádek rekapitulace). Sub-formulář zná jen `doc_head`, ale
 * potřebuje z hlavičky režim DPH, měnu a hlavně **zemi, směr a místo
 * plnění**, podle kterých se nabízejí DPH kódy.
 *
 * Sdílejí ho `DocRowsForm` a `VatRecapForm` — nabídka kódů musí být
 * v řádku i v rekapitulaci stejná.
 */
final class DocHeadVatContext
{
    /**
     * Datum, ke kterému se hledá sazba kódu DPH (#92 D4, U6) — jediné místo
     * pravidla: u opravného dokladu s vyplněným datem plnění původního
     * dokladu toto datum (oprava používá sazbu původního plnění), jinak DUZP
     * a bez něj datum vystavení (nedaňový doklad DUZP nemá, #79 D1).
     * Konzumenti: `load()` (→ `DocRowsForm`, `VatRecapForm`)
     * a `DocDocument::validateDeclaredRecap()`. Zařazení do období DPH, KH
     * a zámky období se dál řídí `vat_duzp` — tohle datum tam nepatří.
     *
     * @param array<string, mixed> $head hlavička z DB nebo data k uložení
     */
    public static function vatRateDate(array $head): ?string
    {
        if (!empty($head['corrective'])) {
            $corrected = self::dateString($head['corrected_doc_date'] ?? null);
            if ($corrected !== null) {
                return $corrected;
            }
        }
        return self::dateString($head['vat_duzp'] ?? null) ?? self::dateString($head['issue_date'] ?? null);
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? substr($text, 0, 10) : null;
    }

    /**
     * `vat_rate_date` = datum, ke kterému se hledá sazba kódu DPH
     * ({@see vatRateDate}: datum plnění původního dokladu u opravného
     * dokladu, jinak DUZP, bez něj datum vystavení).
     *
     * @return array{doc_type: string, cash_dir: int, vat_place: int,
     *     vat_duzp: mixed, vat_rate_date: mixed, vat_mode: int,
     *     doc_currency: string, home_currency: string, exchange_rate: float,
     *     country: ?string, direction: ?string, place: string}|null
     */
    public static function load(?DataSourceConnection $db, ?ConfigRuntime $config, mixed $docHeadId): ?array
    {
        if ($docHeadId === null || $docHeadId === '' || $db === null) {
            return null;
        }
        $head = $db->fetchRow(
            'SELECT `vat_registration`, `doc_type`, `cash_dir`, `vat_place`, `vat_duzp`, `issue_date`,'
            . ' `corrective`, `corrected_doc_date`,'
            . ' `vat_mode`, `doc_currency`, `home_currency`, `exchange_rate`'
            . ' FROM `docs_core_heads` WHERE `id` = %i',
            (int) $docHeadId,
        );
        if ($head === null) {
            return null;
        }

        $context = [
            'doc_type'      => (string) ($head['doc_type'] ?? ''),
            'cash_dir'      => (int) ($head['cash_dir'] ?? 0),
            'vat_place'     => (int) ($head['vat_place'] ?? 0),
            'vat_duzp'      => $head['vat_duzp'] ?? null,
            'vat_rate_date' => self::vatRateDate($head),
            'vat_mode'      => (int) ($head['vat_mode'] ?? 1),
            'doc_currency'  => (string) ($head['doc_currency'] ?? ''),
            'home_currency' => (string) ($head['home_currency'] ?? ''),
            'exchange_rate' => (float) ($head['exchange_rate'] ?? 1.0),
            'country'       => null,
            'direction'     => null,
            'place'         => 'domestic',
        ];

        if (!empty($head['vat_registration'])) {
            $reg = $db->fetchRow(
                'SELECT `country` FROM `economy_codebooks_vat_registrations` WHERE `id` = %i',
                (int) $head['vat_registration'],
            );
            if ($reg !== null && !empty($reg['country'])) {
                $context['country'] = (string) $reg['country'];
            }
        }

        // Směr DPH kódů = směr obchodu dokladu (per typ, u pokladního
        // dokladu per doklad z cash_dir) — jediná autorita DocDocument.
        $context['direction'] = match (DocDocument::resolveTradeDir($head, $config)) {
            1 => 'output',
            2 => 'input',
            default => null,
        };

        $context['place'] = match ($context['vat_place']) {
            0 => 'domestic',
            1 => 'intracom',
            2 => 'foreign',
            default => 'domestic',
        };

        return $context;
    }

    /**
     * Nabídka DPH kódů pro sub-formulář: kódy země, směru a místa plnění
     * hlavičky. Bez země nebo směru (neplátce, nedohledaná registrace)
     * prázdná — pole pak nemá co nabídnout.
     *
     * @param array<string, mixed>|null $context
     * @return list<array{value: string, label: string}>
     */
    public static function vatCodeOptions(?array $context, ?ConfigRuntime $config): array
    {
        if ($context === null
            || empty($context['country'])
            || empty($context['direction'])
            || $config === null
        ) {
            return [];
        }
        try {
            $codes = (new VatRateResolver($config))->getVatCodes(
                (string) $context['country'],
                (string) $context['direction'],
                (string) $context['place'],
                includeHidden: false,
            );
        } catch (\LogicException) {
            return [];
        }
        $options = [];
        foreach ($codes as $key => $code) {
            $options[] = [
                'value' => (string) $key,
                'label' => (string) ($code['fullName'] ?? $code['name'] ?? $key),
            ];
        }
        return $options;
    }
}
