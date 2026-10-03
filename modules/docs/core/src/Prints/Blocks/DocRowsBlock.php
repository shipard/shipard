<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Blok `rows` — řádky dokladu v pořadí tisku. Textový řádek nese jen popis.
 * Odpočty záloh zůstávají mezi řádky s příznakem `advanceDeduction`
 * (#90 D18); souhrn je v bloku `advances`.
 */
final class DocRowsBlock implements DocPrintBlock
{
    private const ROW_KIND_TEXT = 0;

    /** Jazyk zkratek jednotek v `core_units` (seed je český). */
    private const DATA_LANGUAGE = 'cs';

    public function build(DocPrintContext $context): array
    {
        $rows = [];
        foreach ($context->rows as $row) {
            $description = (string) ($row['description'] ?? '');

            if ((int) ($row['row_kind'] ?? 1) === self::ROW_KIND_TEXT) {
                $rows[] = ['kind' => 'text', 'description' => $description];
                continue;
            }

            $unitId = (int) ($row['unit'] ?? 0);
            $discountPct = DocPrintContext::number($row['discount_pct'] ?? null);

            $rows[] = [
                'kind'                 => 'item',
                'description'          => $description,
                'quantity'             => DocPrintContext::number($row['quantity'] ?? null),
                'unit'                 => isset($context->units[$unitId])
                    ? ['id' => $unitId, 'label' => $this->unitLabel($context, $context->units[$unitId])]
                    : null,
                'unitPrice'            => DocPrintContext::number($row['unit_price'] ?? null),
                'unitPriceIncludesVat' => $context->vatMode() === 2,
                'discountPct'          => $discountPct === 0.0 ? null : $discountPct,
                'vat'                  => $this->vat($context, $row),
                'base'                 => DocPrintContext::number($row['vat_base'] ?? null),
                'vatAmount'            => DocPrintContext::number($row['vat_amount'] ?? null),
                'total'                => DocPrintContext::number($row['vat_total'] ?? null),
                'advanceDeduction'     => $context->isAdvanceDeduction($row),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Zkratka jednotky (#90 D31). Česky ta z dat — zkratku lze ve zdroji dat
     * upravit. V ostatních jazycích má systémová jednotka zkratku
     * v `core.units.printShortcuts`; jednotka založená ve zdroji dat se
     * tiskne, jak je.
     *
     * @param array{shortcut: string, systemCode: ?string} $unit
     */
    private function unitLabel(DocPrintContext $context, array $unit): string
    {
        if ($unit['systemCode'] !== null && $context->translator->language !== self::DATA_LANGUAGE) {
            $shortcuts = $context->config?->cfgItem('core.units.printShortcuts');
            $label     = is_array($shortcuts) ? ($shortcuts[$unit['systemCode']]['shortcut'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }
        return $unit['shortcut'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{code: string, pct: ?float, label: string, noteMark: ?string}|null
     */
    private function vat(DocPrintContext $context, array $row): ?array
    {
        $code = DocPrintContext::text($row['vat_code'] ?? null);
        if ($code === null || !$context->showsVat()) {
            return null;
        }
        return [
            'code'     => $code,
            'pct'      => DocPrintContext::number($row['vat_pct'] ?? null),
            'label'    => $context->vatCodes->label($code),
            'noteMark' => $context->vatCodes->noteMark($code),
        ];
    }
}
