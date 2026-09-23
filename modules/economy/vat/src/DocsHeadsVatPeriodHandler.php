<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * beforeSave handler na `docs_core_heads` (issue #55, D8/D9/D13): materializuje
 * zařazení dokladu do instancí tvrzení do sloupců `vat_period` /
 * `cs_period` / `rs_period` (extension economy.vat) — uvnitř save transakce,
 * s recapem už spočítaným v `DocDocument::beforeSave` (`$data['vatRecap']`).
 *
 * Ručně změněné pole (payload se liší od původního řádku, u nového záznamu
 * ne-null hodnota) handler respektuje — jen ověří, že instance existuje,
 * má správný typ a patří registraci dokladu. Nedotčené pole se přepočítá
 * pravidlem, takže ruční přesun drží jen do dalšího uložení dokladu s jinou
 * hodnotou v payloadu (formulář posílá aktuální hodnotu, ta se rovná
 * originálu → přepočet). Import mód žádnou výjimku nemá — věrnost dávají
 * reálné rozsahy importovaných instancí (D12).
 *
 * Chybějící instance → on-demand koncept (10) přes ReportPeriodsProvisioner;
 * alert `economy.vat.draft_report_periods` ho nabídne ke kontrole.
 *
 * Nedaňový typ dokladu (`docTypes[].tax_document: false`, zálohová faktura,
 * #79 D1) do tvrzení nepatří: všechna tři období null, i ruční hodnota
 * z payloadu — výjimka není. Totéž pravidlo zrcadlí VatPeriodLockProvider.
 */
final class DocsHeadsVatPeriodHandler extends AbstractDocumentEventHandler
{
    public const COLUMN_TYPES = [
        'vat_period' => VatPeriodAssigner::TYPE_RETURN,
        'cs_period'  => VatPeriodAssigner::TYPE_CS,
        'rs_period'  => VatPeriodAssigner::TYPE_RS,
    ];

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
        // Nedaňový typ (#79 D1): doc_type může u částečného save chybět (bez
        // number_series ho DocDocument nedenormalizuje) → fallback na původní
        // řádek. Před DB — pravidlo platí i bez připojení.
        $docType = (string) ($data['doc_type'] ?? $originalData['doc_type'] ?? '');
        if (!DocTypes::isTaxDocument($this->config, $docType)) {
            foreach (array_keys(self::COLUMN_TYPES) as $column) {
                $data[$column] = null;
            }
            return;
        }

        if ($this->db === null) {
            return;
        }

        $manual = self::manualOverrides($data, $originalData);

        $regId = (int) ($data['vat_registration'] ?? 0);
        $duzp = VatPeriodAssigner::isoDate($data['vat_duzp'] ?? null);

        $connection = new DataSourceConnection($this->db);
        $mapping = VatOutputsMapping::fromConfig($this->config);
        $provisioner = new ReportPeriodsProvisioner($connection, $mapping?->validFromByType() ?? []);
        $provisioner->setCreateMissing(true, 10);

        $computed = ['vat_period' => null, 'cs_period' => null, 'rs_period' => null];
        if ($regId > 0 && $duzp !== null) {
            $recap = is_array($data['vatRecap'] ?? null)
                ? $data['vatRecap']
                : $this->loadRecap($connection, (int) ($data['id'] ?? 0));
            $computed = (new VatPeriodAssigner($provisioner, $mapping))->compute($data, $recap);
        }

        foreach (self::COLUMN_TYPES as $column => $type) {
            if (array_key_exists($column, $manual)) {
                if ($manual[$column] !== null) {
                    $this->assertInstance($connection, $manual[$column], $type, $regId, $column);
                }
                $data[$column] = $manual[$column];
                continue;
            }
            $data[$column] = $computed[$column];
        }
    }

    /**
     * Pole, která payload změnil oproti původnímu řádku (u insertu ne-null).
     *
     * @param array<string, mixed> $data
     * @param ?array<string, mixed> $originalData
     * @return array<string, ?int>
     */
    public static function manualOverrides(array $data, ?array $originalData): array
    {
        $manual = [];
        foreach (self::COLUMN_TYPES as $column => $_) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            $normalized = ($value === null || $value === '') ? null : (int) $value;
            if ($originalData === null) {
                if ($normalized !== null) {
                    $manual[$column] = $normalized;
                }
                continue;
            }
            $original = $originalData[$column] ?? null;
            $originalNormalized = ($original === null || $original === '') ? null : (int) $original;
            if ($normalized !== $originalNormalized) {
                $manual[$column] = $normalized;
            }
        }
        return $manual;
    }

    private function assertInstance(DataSourceConnection $db, int $instanceId, string $type, int $regId, string $column): void
    {
        $row = $db->fetchRow(
            'SELECT [report_type], [vat_registration] FROM [economy_vat_report_periods]'
            . ' WHERE [id] = %i AND [docState] != 90',
            $instanceId,
        );
        if ($row === null) {
            throw new \DomainException("Instance tvrzení #{$instanceId} ({$column}) neexistuje.");
        }
        if ((string) $row['report_type'] !== $type) {
            throw new \DomainException("Instance tvrzení #{$instanceId} není typu '{$type}' ({$column}).");
        }
        if ($regId > 0 && (int) $row['vat_registration'] !== $regId) {
            throw new \DomainException("Instance tvrzení #{$instanceId} patří jiné registraci DPH než doklad ({$column}).");
        }
    }

    /** @return list<array<string, mixed>> */
    private function loadRecap(DataSourceConnection $db, int $headId): array
    {
        if ($headId <= 0) {
            return [];
        }
        return $db->fetchAll(
            'SELECT [vat_code] FROM [docs_core_vat_recap] WHERE [doc_head] = %i',
            $headId,
        );
    }
}
