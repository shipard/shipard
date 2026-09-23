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
     * `vat_rate_date` = datum, ke kterému se hledá sazba kódu DPH: DUZP,
     * u nedaňového dokladu bez DUZP (zálohová faktura, #79 D1) datum
     * vystavení — stejné pravidlo jako `DocDocument::validateDeclaredRecap`.
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
            'vat_rate_date' => $head['vat_duzp'] ?? $head['issue_date'] ?? null,
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
