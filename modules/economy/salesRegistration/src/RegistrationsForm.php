<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář registrace k evidenci tržeb (#171 D3, D10).
 *
 * Typ evidence je výběr z registru {@see SalesRegistrationTypes}; po nahrání
 * certifikátu je jen ke čtení (certifikát patří typu). Identifikátor
 * poplatníka a údaje certifikátu formulář jen ukazuje — plní je akce
 * „Nahrát certifikát“ v detailu, soukromý klíč formulář nezná vůbec.
 * Výchozí evidenční jednotka se vybírá z jednotek této registrace, proto až
 * u uložené registrace.
 */
class RegistrationsForm extends TableForm
{
    public const string UNITS_FORM_ID = 'economy.salesRegistration.units';

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $types = SalesRegistrationTypes::fromConfig($this->config);
        $typeId = trim((string) ($data['type'] ?? ''));
        $type = $typeId !== '' ? $types->get($typeId) : null;
        $recordId = (int) ($data['id'] ?? 0);
        $hasCertificate = trim((string) ($data['cert_pem'] ?? '')) !== '';

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'type',
                        options: $this->typeOptions($types, $typeId),
                        required: true,
                        readOnly: $hasCertificate,
                    )
                    ->select(
                        'mode',
                        options: $this->modeOptions(),
                        required: true,
                        hint: $this->formText('economy.salesRegistration.formTexts', 'registration.modeHint', 'Verification sends messages to the production service in verification mode — nothing is registered. Where messages really go also depends on the server safety switch.'),
                    )
                    ->inline()
                        ->date('valid_from')
                        ->date('valid_to')
                    ->endInline()
                    ->select(
                        'default_unit',
                        options: $this->unitOptions($recordId),
                        required: false,
                        hidden: $isNew,
                        hint: $this->formText('economy.salesRegistration.formTexts', 'registration.defaultUnitHint', 'Used when the document does not say otherwise. Units are managed on the Registration units tab.'),
                    )
                    ->textarea('notice')

            ->section(title: 'Certifikát', hidden: $isNew)
                ->col()
                    ->content([$this->certificateBlock($data, $type, $hasCertificate)])
                    ->content([[
                        'type' => 'hint',
                        'text' => $this->formText('economy.salesRegistration.formTexts', 'registration.certificateHint', 'The certificate is uploaded with the Upload certificate action in the registration detail. The taxpayer identifier and validity are read from it.'),
                    ]])
            ->build();

        $units = $this->subtableTab(
            'units',
            'Evidenční jednotky',
            UnitDocument::TABLE,
            'registration',
            self::UNITS_FORM_ID,
            sort: 'unit_id',
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Registrace k evidenci tržeb',
            titleNew: 'Nová registrace k evidenci tržeb',
            tabs: [$basic, $units],
        );
    }

    /**
     * Typy z registru; uložená hodnota mimo registr (vypnutý modul země)
     * zůstává v nabídce, aby ji formulář neztratil.
     *
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(SalesRegistrationTypes $types, string $current): array
    {
        $options = array_map(
            static fn(SalesRegistrationType $t): array => ['value' => $t->id, 'label' => $t->name],
            $types->all(),
        );
        if ($current !== '' && $types->get($current) === null) {
            $options[] = ['value' => $current, 'label' => $current];
        }
        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private function modeOptions(): array
    {
        $cfg = $this->config?->cfgItem(RegistrationDocument::MODES_CFG);
        if (is_array($cfg) && $cfg !== []) {
            return EnumOptionsHelper::fromCfgData($cfg, 'enumString', RegistrationDocument::MODES_CFG);
        }
        return array_map(static fn(string $m): array => ['value' => $m, 'label' => $m], RegistrationDocument::MODES);
    }

    /**
     * Jednotky této registrace pro výběr výchozí jednotky.
     *
     * @return list<array{value: int, label: string}>
     */
    private function unitOptions(int $registrationId): array
    {
        $options = [];
        foreach ($this->loadUnits($registrationId) as $unit) {
            $name = trim((string) ($unit['name'] ?? ''));
            $options[] = [
                'value' => (int) $unit['id'],
                'label' => $name !== '' ? $unit['unit_id'] . ' — ' . $name : (string) $unit['unit_id'],
            ];
        }
        return $options;
    }

    /**
     * Jednotky registrace (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadUnits(int $registrationId): array
    {
        if ($registrationId <= 0 || $this->db === null) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT `id`, `unit_id`, `name` FROM `' . UnitDocument::TABLE . '` WHERE `registration` = %i ORDER BY `unit_id` ASC, `id` ASC',
            $registrationId,
        );
    }

    /**
     * Údaje certifikátu jen ke čtení, nebo prázdný stav.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function certificateBlock(array $data, ?SalesRegistrationType $type, bool $hasCertificate): array
    {
        if (!$hasCertificate) {
            return [
                'type' => 'empty',
                'text' => $this->formText('economy.salesRegistration.formTexts', 'registration.noCertificate', 'No certificate has been uploaded yet.'),
            ];
        }

        $items = [];
        $add = static function (string $label, ?string $value) use (&$items): void {
            if ($value !== null && $value !== '') {
                $items[] = ['label' => $label, 'value' => $value];
            }
        };
        $add(
            $type?->taxpayerIdLabel ?? $this->subtableLabel($this->table, 'taxpayer_id', 'Taxpayer identifier'),
            trim((string) ($data['taxpayer_id'] ?? '')),
        );
        $add($this->subtableLabel($this->table, 'cert_valid_from', 'Certificate valid from'), $this->formatDateTime($data['cert_valid_from'] ?? null));
        $add($this->subtableLabel($this->table, 'cert_valid_to', 'Certificate valid to'), $this->formatDateTime($data['cert_valid_to'] ?? null));
        $add($this->subtableLabel($this->table, 'cert_serial', 'Certificate serial number'), trim((string) ($data['cert_serial'] ?? '')));
        $add($this->subtableLabel($this->table, 'cert_uploaded_at', 'Certificate uploaded'), $this->formatDateTime($data['cert_uploaded_at'] ?? null));

        return ['type' => 'properties', 'groups' => [['title' => '', 'items' => $items]]];
    }
}
