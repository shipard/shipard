<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Stav zaúčtování v detailu záznamu (#137 D30): štítek stavu a hláška
 * s hlášeními enginu pro tab Zaúčtování dokladu i bankovního pohybu —
 * jedno místo místo dvou kopií HTML ve viewerech. Žije v `src/Core`,
 * protože `docs.core` na `economy.accounting` záviset nesmí a oba viewery
 * odsud sdílený kód už čtou ({@see JournalDimensionSet}).
 *
 * Výstup jsou bloky pro `composite` ({@see \Shipard\Core\Viewer\Detail\DetailContent}):
 * `badges` se stavem (0 Nezaúčtováno → warning, 1 Zaúčtováno → success,
 * 2 Chyba účtování → danger; název z cfgItem `economy.accounting.accountingStates`,
 * bez konfigurace anglicky) a, jsou-li hlášení u stavu ≥ 1, `notice`
 * s položkami — stav 2 danger, jinak warning (hlášení úrovně `warning`
 * u zaúčtovaného dokladu, #79 D3b). Hlášení s `rowId` dostane příponu
 * z katalogu `economy.accounting.viewerDetailLabels` `texts.rowReference`
 * („(řádek N)“); bankovní engine `rowId` nedává, u banky se neobjeví.
 *
 * `accounting_messages` je JSON seznam `{code, message, rowId, level?}`
 * (`AccountingEngine::addMessage`, `BankTransactionAccountingEngine::addMessage`).
 */
final class AccountingStatusContent
{
    public const STATES_CFG_ITEM = 'economy.accounting.accountingStates';
    public const LABELS_CFG_ITEM = 'economy.accounting.viewerDetailLabels';

    private const STATE_STYLES = [0 => 'warning', 1 => 'success', 2 => 'danger'];
    private const STATE_FALLBACKS = [0 => 'Not accounted', 1 => 'Accounted', 2 => 'Accounting error'];
    private const ROW_REFERENCE_FALLBACK = '(row {n})';

    /**
     * @param mixed $rawMessages sloupec `accounting_messages` — JSON, pole, nebo null
     * @return list<array<string, mixed>> blok `badges`, za ním případný blok `notice`
     */
    public static function blocks(?ConfigRuntime $config, int $state, mixed $rawMessages): array
    {
        $blocks = [[
            'type'  => 'badges',
            'items' => [[
                'label' => self::stateName($config, $state),
                'style' => self::STATE_STYLES[$state] ?? 'warning',
            ]],
        ]];

        $messages = $state >= 1 ? self::decodeMessages($rawMessages) : [];
        if ($messages === []) {
            return $blocks;
        }

        $rowReference = self::rowReference($config);
        $items = [];
        foreach ($messages as $message) {
            $text = (string) ($message['message'] ?? '');
            $rowId = $message['rowId'] ?? null;
            if ($rowId !== null) {
                $text .= ' ' . str_replace('{n}', (string) (int) $rowId, $rowReference);
            }
            $items[] = $text;
        }
        $blocks[] = [
            'type'  => 'notice',
            'tone'  => $state === 2 ? 'danger' : 'warning',
            'items' => $items,
        ];

        return $blocks;
    }

    /**
     * Dekódovaný sloupec `accounting_messages`: JSON řetězec, nebo už pole;
     * cokoli jiného (null, prázdný řetězec, nevalidní JSON) = žádná hlášení.
     *
     * @return list<array<string, mixed>>
     */
    public static function decodeMessages(mixed $raw): array
    {
        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, 'is_array'));
    }

    /** Název stavu z cfgItem `accountingStates`; bez konfigurace anglicky. */
    public static function stateName(?ConfigRuntime $config, int $state): string
    {
        $states = $config?->cfgItem(self::STATES_CFG_ITEM);
        $name = is_array($states) ? (string) ($states[(string) $state]['name'] ?? '') : '';
        return $name !== '' ? $name : (self::STATE_FALLBACKS[$state] ?? (string) $state);
    }

    private static function rowReference(?ConfigRuntime $config): string
    {
        $labels = $config?->cfgItem(self::LABELS_CFG_ITEM);
        $text = is_array($labels) ? (string) ($labels['texts']['rowReference']['name'] ?? '') : '';
        return $text !== '' ? $text : self::ROW_REFERENCE_FALLBACK;
    }
}
