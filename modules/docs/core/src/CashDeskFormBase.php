<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TabBuilder;

/**
 * Společná báze formulářů dokladů vázaných na pokladnu (pokladní doklad,
 * prodejka) — protějšek CashDeskDocumentBase.
 *
 * Pokladna se na dokladu nezadává: přijde z číselné řady (záložka vieweru
 * = pokladna, `Viewer.svelte` posílá `number_series` do defaults) a na
 * uložení ji DocDocument denormalizuje do `cash_desk`. Formulář ji proto
 * jen ukazuje (sekce „Pokladna") a řídí podle ní měnu dokladu.
 *
 * Oproti DocsHeadsFormBase:
 *   - způsob úhrady jen Hotovost / Kartou, default Hotovost,
 *   - `recalculate('payment_method')` NEMAŽE `cash_desk` (u faktur to
 *     base dělá záměrně — tam je pokladna uživatelská, tady systémová),
 *   - `number_series` je viditelný select „Pokladna" jen dokud není
 *     vyplněný (formulář otevřený mimo viewer, DS bez pokladny).
 */
abstract class CashDeskFormBase extends DocsHeadsFormBase
{
    /**
     * HTTP cesta nového dokladu: výchozí hodnoty dává společná
     * {@see \Shipard\Module\Docs\Core\Defaults\DocHeadDefaults} — typ
     * vázaný na pokladnu začíná Hotovostí a měnou pokladny z řady (prefill
     * vieweru), bankovní účet nenese. Formulář přidává jen jedno: způsob
     * úhrady mimo povolené úhrady formuláře ({@see allowedPaymentMethods()})
     * se bere jako nezadaný → Hotovost; jiný povolený prefill (Kartou;
     * u prodejky i Dobírkou, Bránou) přežije. Schéma default Převodem je
     * „nezadáno“ už v bázi — i u prodejky, která převod povoluje.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        $paymentMethod = $data['payment_method'] ?? null;
        if ($paymentMethod !== null && $paymentMethod !== ''
            && !in_array((int) $paymentMethod, $this->allowedPaymentMethods(), true)
        ) {
            unset($data['payment_method']);
        }
        parent::applyNewRecordDefaults($data);
    }

    /**
     * Změna způsobu úhrady / směru / řady jen přestaví definici (a u řady
     * přepne měnu na měnu pokladny). Ostatní sloupce (partner, issue_date)
     * řeší cascade rodiče.
     */
    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if (!in_array($changedColumn, ['payment_method', 'cash_dir', 'number_series'], true)) {
            return parent::recalculate($changedColumn, $data);
        }
        if ($changedColumn === 'number_series') {
            $desk = $this->resolveCashDesk($data);
            if ($desk !== null && $desk['currency'] !== '') {
                $data['doc_currency'] = $desk['currency'];
            }
            // Měna pokladny → kurz k účetnímu datu (#121 D6).
            $this->applyExchangeRate($data);
        }
        $this->applyPartnerBalancePreview($data);
        // Způsob úhrady, směr i měna pokladny z řady mění mód automatiky (#73 U7).
        $this->applyTotalRoundingAuto($data);
        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * Pokladna z řady už pro náhled plátce a filtr terminálu (#72): nový
     * doklad má `cash_desk` až po uložení (denormalizace v DocDocument),
     * ale default terminál pokladny a filtr lookupu ji potřebují hned.
     */
    protected function intermediaryData(array $data): array
    {
        if (empty($data['cash_desk'])) {
            $desk = $this->resolveCashDesk($data);
            if ($desk !== null) {
                $data['cash_desk'] = $desk['id'];
            }
        }
        return $data;
    }

    /**
     * Pokladna dokladu: z `cash_desk` hlavičky (existující doklad), jinak
     * z řady (`number_series` → docs_core_number_series.cash_desk).
     *
     * @return array{id: int, code: string, name: string, currency: string}|null
     */
    protected function resolveCashDesk(array $data): ?array
    {
        return $this->headDefaults()->cashDesk($data);
    }

    /** Způsoby úhrady povolené na dokladu (default CashDeskDocumentBase::PAYMENT_METHODS_ALLOWED; formulář prodejky přepíše). */
    protected function allowedPaymentMethods(): array
    {
        return CashDeskDocumentBase::PAYMENT_METHODS_ALLOWED;
    }

    /** Způsoby úhrady povolené na pokladním dokladu (allowedPaymentMethods). */
    protected function paymentMethodOptions(): array
    {
        $allowed = $this->allowedPaymentMethods();
        return array_values(array_filter(
            $this->resolveCfgItemOptions('docs.core.paymentMethods'),
            static fn(array $o) => in_array((int) $o['value'], $allowed, true),
        ));
    }

    /** Má doklad uložené řádky? (změna směru by zneplatnila jejich pohyby) */
    protected function hasRows(array $data): bool
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || $this->db === null) {
            return false;
        }
        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS `cnt` FROM `docs_core_rows` WHERE `doc_head` = %i',
            $id,
        );
        return is_array($row) && (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Select řady jako „Pokladna": skrytý, když je řada známá (z vieweru),
     * viditelný jinak — nikdy se nevybírá potichu první řada (to by doklad
     * zařadilo do libovolné pokladny).
     */
    protected function addSeriesSelect(TabBuilder $tab, array $data, bool $isNew): TabBuilder
    {
        $options = $this->resolveNumberSeriesOptions(
            !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
        );
        return $tab->select(
            'number_series',
            label: 'Pokladna',
            options: $options,
            triggers: 'reload',
            required: true,
            readOnly: !$isNew,
            hidden: !empty($data['number_series']),
            hint: $options === [] ? 'Nejdřív založ pokladnu v číselníku pokladen — řada vznikne automaticky' : null,
        );
    }

    /**
     * Sekce „Pokladna" jen ke čtení (#150 D83): pokladna jako blok `text`
     * (kód — název · měna); řada bez pokladny = hláška `warning` s textem
     * z katalogu `docs.core.formTexts`.
     */
    protected function addCashDeskSection(TabBuilder $tab, array $data): TabBuilder
    {
        $desk = $this->resolveCashDesk($data);
        $block = $desk === null
            ? [
                'type' => 'notice',
                'tone' => 'warning',
                'text' => $this->formText(
                    'docs.core.formTexts',
                    'cashDesk.missing',
                    'The number series has no cash desk — check the number series of the document.',
                ),
            ]
            : [
                'type' => 'text',
                'text' => trim(sprintf('%s — %s · %s', $desk['code'], $desk['name'], strtoupper($desk['currency'])), ' —·'),
            ];
        return $tab
            ->section(title: 'Pokladna')
                ->col()
                    ->content([$block]);
    }
}
