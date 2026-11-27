<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář druhu smlouvy (Nastavení → Budovy, #174 D18): název, typ,
 * poznámka a číslování — kód druhu, vzorec čísla a restart čítače.
 *
 * Typ jde zvolit jen u konceptu, který ještě nepoužívá žádná smlouva
 * (ContractKindDocument to hlídá i na serveru); potvrzený druh ho má jen ke
 * čtení. Nový druh dostane výchozí vzorec a roční restart.
 */
class ContractKindsForm extends TableForm
{
    public function applyNewRecordDefaults(array &$data): void
    {
        $data['number_pattern'] ??= ContractKindsProvisioner::DEFAULT_PATTERN;
        $data['reset_scope'] ??= ContractKindDocument::RESET_YEARLY;
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = new ContractTypes($this->config);
        $confirmed = !$isNew && (int) ($data['docState'] ?? ContractKindDocument::STATE_DRAFT) !== ContractKindDocument::STATE_DRAFT;
        $used = !$isNew && !$confirmed && $this->isKindUsed((int) ($data['id'] ?? 0));
        $typeHint = match (true) {
            $confirmed => $this->formText('facility.buildings.formTexts', 'kind.typeLockedHint', 'The type of a confirmed kind cannot be changed.'),
            $used => $this->formText('facility.buildings.formTexts', 'kind.typeUsedHint', 'The type cannot be changed while contracts use this kind.'),
            default => $this->formText('facility.buildings.formTexts', 'kind.typeHint', 'The type decides which places a contract of this kind may have. It cannot be changed once the kind is confirmed or used by a contract.'),
        };

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $types->options(),
                        required: true,
                        readOnly: $confirmed || $used,
                        hint: $typeHint,
                    )
                    ->textarea('notice')
                    ->separator('Číslování')
                    ->input(
                        'number_code',
                        hint: $this->formText('facility.buildings.formTexts', 'kind.codeHint', 'Short code used as %C in the number pattern, unique among kinds.'),
                    )
                    ->input(
                        'number_pattern',
                        required: true,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.patternHint', '%C kind code, %y / %Y year the contract starts (2 / 4 digits), %3 to %6 sequence padded with zeros. The sequence is required.'),
                    )
                    ->select(
                        'reset_scope',
                        options: $this->resetScopeOptions(),
                        required: true,
                        hint: $this->formText('facility.buildings.formTexts', 'kind.resetHint', 'Yearly = the sequence starts again every calendar year a contract starts in; the pattern must then contain the year.'),
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Druh smlouvy',
            titleNew: 'Nový druh smlouvy',
            tabs: [$tab],
        );
    }

    /** @return list<array{value: int|string, label: string}> */
    private function resetScopeOptions(): array
    {
        $cfg = $this->config?->cfgItem(ContractKindDocument::RESET_SCOPES_CFG_ITEM);
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', ContractKindDocument::RESET_SCOPES_CFG_ITEM);
    }

    /** Používá druh nějaká smlouva mimo koš? (přepsatelné v testech) */
    protected function isKindUsed(int $kindId): bool
    {
        if ($this->db === null || $kindId <= 0) {
            return false;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM `' . ContractDocument::TABLE . '` WHERE `kind` = %i AND `docState` <> %i',
            $kindId,
            ContractDocument::STATE_DELETED,
        ) > 0;
    }
}
