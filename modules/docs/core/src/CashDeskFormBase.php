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
    /** @var array<string, array{id: int, code: string, name: string, currency: string}|null> */
    private array $cashDeskCache = [];

    /**
     * HTTP cesta nového dokladu: schéma default payment_method = 1 (Převodem)
     * na pokladní doklad nepatří — hodnota mimo povolené (Hotovost, Kartou)
     * → Hotovost; explicitní prefill Kartou přežije. („Když neprefillnuto"
     * na této cestě poznat nejde, FormController vloží schéma default dřív.)
     * Měna = měna pokladny z řady (prefill vieweru). Pak base (datum,
     * registrace DPH); bankovní účet formulář nemá. Větve v
     * applyClientDefaults zůstávají pro renderování a recalculate.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        $paymentMethod = $data['payment_method'] ?? null;
        if ($paymentMethod === null || $paymentMethod === ''
            || !in_array((int) $paymentMethod, CashDeskDocumentBase::PAYMENT_METHODS_ALLOWED, true)
        ) {
            $data['payment_method'] = 0;
        }
        $desk = $this->resolveCashDesk($data);
        if ($desk !== null && $desk['currency'] !== '') {
            $data['doc_currency'] = $desk['currency'];
        }
        parent::applyNewRecordDefaults($data);
    }

    protected function newRecordUsesBankAccount(): bool
    {
        return false;
    }

    /** Jen renderování (viz DocsHeadsFormBase::applyClientDefaults). */
    protected function applyClientDefaults(array &$data, bool $isNew): void
    {
        $hadPaymentMethod = isset($data['payment_method']);
        parent::applyClientDefaults($data, $isNew);
        if (!$isNew) {
            return;
        }
        if (!$hadPaymentMethod) {
            $data['payment_method'] = 0;
        }
        $desk = $this->resolveCashDesk($data);
        if ($desk !== null && $desk['currency'] !== '') {
            $data['doc_currency'] = $desk['currency'];
        }
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
        }
        $this->applyPartnerBalancePreview($data);
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
        if ($this->db === null) {
            return null;
        }
        $deskId = (int) ($data['cash_desk'] ?? 0);
        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($deskId <= 0 && $seriesId <= 0) {
            return null;
        }
        $cacheKey = $deskId > 0 ? "desk:{$deskId}" : "series:{$seriesId}";
        if (array_key_exists($cacheKey, $this->cashDeskCache)) {
            return $this->cashDeskCache[$cacheKey];
        }

        if ($deskId > 0) {
            $row = $this->db->fetchRow(
                'SELECT `id`, `code`, `name`, `currency` FROM `economy_codebooks_cash_desks` WHERE `id` = %i',
                $deskId,
            );
        } else {
            $row = $this->db->fetchRow(
                'SELECT cd.`id`, cd.`code`, cd.`name`, cd.`currency`'
                . ' FROM `docs_core_number_series` s'
                . ' JOIN `economy_codebooks_cash_desks` cd ON cd.`id` = s.`cash_desk`'
                . ' WHERE s.`id` = %i',
                $seriesId,
            );
        }

        $desk = is_array($row) && !empty($row['id']) ? [
            'id'       => (int) $row['id'],
            'code'     => (string) ($row['code'] ?? ''),
            'name'     => (string) ($row['name'] ?? ''),
            'currency' => strtolower(trim((string) ($row['currency'] ?? ''))),
        ] : null;
        $this->cashDeskCache[$cacheKey] = $desk;
        return $desk;
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

    /** Sekce „Pokladna" (readOnly text): kód — název · měna. */
    protected function addCashDeskSection(TabBuilder $tab, array $data): TabBuilder
    {
        $desk = $this->resolveCashDesk($data);
        $text = $desk === null
            ? 'Řada nemá pokladnu — zkontroluj číselnou řadu dokladu.'
            : trim(sprintf('%s — %s · %s', $desk['code'], $desk['name'], strtoupper($desk['currency'])), ' —·');
        return $tab
            ->section(title: 'Pokladna')
                ->col()
                    ->html('<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>');
    }
}
