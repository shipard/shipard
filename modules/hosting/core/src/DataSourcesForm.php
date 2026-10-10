<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class DataSourcesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        // ds_id a url_app nejsou required — u požadavku (lifecycle request)
        // je generuje HostingDataSourceDocument::beforeSave; povinnost polí
        // požadavku hlídá validate().
        $basic = $this->tab('basic', 'Základní údaje')
            ->section('Identifikace')
                ->col()
                    ->input('ds_id', hint: 'Formát xxxx-xxxx-xxxx-xxxx. Prázdné u požadavku = vygeneruje se.')
                    ->input('name', required: true)
                    ->input('web_id', hint: 'Slug pro mail adresy a URL aplikace. Prázdné = nepřidělen.')
                    ->select(
                        'language',
                        options: $this->resolveLanguageOptions(),
                        required: true,
                        hint: 'Výchozí jazyk aplikace nového zdroje dat. Uživatel si ho může přebít ve svém účtu.',
                    )
                    ->select(
                        'country',
                        options: $this->resolveStringOptions('world.base.countries', sortByLabel: true),
                        required: true,
                        hint: 'Země právního subjektu. Určuje registr firem, sazby DPH a formát adres. Po založení se nemění.',
                    )
            ->section('Umístění')
                ->col()
                    ->select('server', options: $this->resolveServerOptions())
                    ->input('url_app', hint: 'Prázdné u požadavku = odvodí se z web_id a základní domény.')
                    ->input('install_module')
                    ->select('lifecycle', options: $this->resolveLifecycleOptions(), required: true)
                    ->select('owner', options: $this->resolveOwnerOptions())
            ->section('Pošta')
                ->col()
                    // Sensitive pole (opt-in přes getEditableSensitiveColumns):
                    // data ho nikdy neobsahují, input startuje prázdný, prázdný
                    // submit hodnotu nemění. Slouží pro ruční backfill tokenů
                    // existujících DS; provisioning ho plní confirmem.
                    ->input(
                        'mail_token',
                        placeholder: '●●●●●● (zadat pro změnu)',
                        hint: 'Token DS pro příjem pošty (shpd_ak_…). Normálně ho hlásí provisioning; ručně jen backfill.',
                        inputType: 'password',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Zdroj dat',
            titleNew: 'Nový zdroj dat',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }

    /**
     * Sensitive pole editovatelná tímto formem — ruční backfill mail
     * tokenu (D4); šifrování řeší HostingDataSourceDocument.
     *
     * @return list<string>
     */
    public function getEditableSensitiveColumns(): array
    {
        return ['mail_token'];
    }

    /**
     * Jazyk omezený na cs/en — `ds-create --language` (ds-setup Task 01)
     * jiné hodnoty odmítne; plný ISO 639-1 seznam z cfgItemu by navíc byl
     * v selectu nepoužitelný. Labely zůstávají z cfgItemu (lokalizované).
     *
     * @return list<array{value: string, label: string}>
     */
    private function resolveLanguageOptions(): array
    {
        return array_values(array_filter(
            $this->resolveStringOptions('world.base.languages'),
            static fn(array $option): bool => in_array($option['value'], ['cs', 'en'], true),
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveStringOptions(string $cfgItemId, bool $sortByLabel = false): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        $options = EnumOptionsHelper::fromCfgData($cfgData, 'enumString', $cfgItemId);

        if ($sortByLabel) {
            usort($options, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        }

        return $options;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function resolveServerOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `fqdn` FROM `hosting_core_servers`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `name` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) $row['name'] . ' (' . $row['fqdn'] . ')',
            ];
        }
        return $options;
    }

    /**
     * Vlastník DS (U1) — záměrně select s přednačtenými options (vzor
     * DsUsersForm), ne lookup: LookupController nemá TableAccessGuard
     * a lookup na core_system_users by vystavil seznam uživatelů
     * ne-admin portálovým účtům.
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolveOwnerOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `full_name`, `login` FROM `core_system_users`'
            . ' WHERE `is_active` = 1 AND `is_system` = 0'
            . ' ORDER BY `full_name` ASC, `login` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $label = $name !== '' ? $name . ' (' . $row['login'] . ')' : (string) $row['login'];
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveLifecycleOptions(): array
    {
        $cfgData = $this->config?->cfgItem('hosting.core.dsLifecycle');
        if (!is_array($cfgData)) {
            return [];
        }
        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
