<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class VatRegistrationsRunner extends BaseCodebookRunner
{
	/** Bezpečný default — pokrývá všechny historické doklady v MVP. */
	private const DEFAULT_VALID_FROM = '2010-01-01';

	protected function entityType(): string  { return LocalIdMap::ENTITY_VAT_REGISTRATION; }
	protected function targetTable(): string { return 'economy_codebooks_vat_registrations'; }
	protected function entityLabel(): string { return 'vat-registration'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [title], [taxCountry], [payerKind], [taxId],'
			. ' [periodType], [periodTypeVatCS], [docState]'
			. ' FROM [e10doc_base_taxRegs]'
			. ' WHERE [taxArea] = %s', 'VAT',
			' AND [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		$oldNdx = (int) $oldRow['ndx'];

		$name = (string) ($oldRow['title'] ?? '');
		if (mb_strlen($name) > 50)
			$name = mb_substr($name, 0, 50);

		$country = strtolower(trim((string) ($oldRow['taxCountry'] ?? '')));
		if ($country === '')
		{
			$this->warn("vat-registration {$oldNdx}: empty taxCountry, defaulting to 'cz'");
			$country = 'cz';
		}

		$taxpayerKind = $this->mapPayerKind((int) ($oldRow['payerKind'] ?? 1), $oldNdx);
		$taxPeriodKind = $this->mapPeriodKind((int) ($oldRow['periodType'] ?? 0), $oldNdx, 'periodType');
		$reportPeriodKind = $this->mapPeriodKind((int) ($oldRow['periodTypeVatCS'] ?? 0), $oldNdx, 'periodTypeVatCS');

		$taxId = trim((string) ($oldRow['taxId'] ?? ''));

		return [
			'name'               => $name,
			'region'             => 'eu',
			'country'            => $country,
			'taxpayer_kind'      => $taxpayerKind,
			'vat_id'             => $taxId !== '' ? $taxId : null,
			'tax_period_kind'    => $taxPeriodKind,
			'report_period_kind' => $reportPeriodKind,
			'valid_from'         => self::DEFAULT_VALID_FROM,
			'valid_to'           => null,
		];
	}

	/**
	 * Old e10doc.base.tagsRegsPayerKinds → new economy.codebooks.vatTaxpayerKinds:
	 *   - 1 (běžný plátce) → 0 (Klasický plátce)
	 *   - jiné hodnoty → warning + default 0
	 *
	 * Nový cfgItem zná i 1 (OSS), ale automatický mapping není 1:1 — starý
	 * Shipard OSS řeší jinak. V MVP konzervativně mapujeme vše na 0.
	 */
	private function mapPayerKind(int $oldVal, int $oldNdx): int
	{
		if ($oldVal === 1)
			return 0;

		$this->warn("vat-registration {$oldNdx}: unknown payerKind={$oldVal}, defaulting to 0 (Standard taxpayer)");
		return 0;
	}

	/**
	 * Old periodType (0/1/2) → new vatPeriodKinds (1=Měsíční, 2=Čtvrtletní).
	 * Hodnota 0 ve starém znamená "---" (nevyplněno) → fallback na 1 + warning.
	 */
	private function mapPeriodKind(int $oldVal, int $oldNdx, string $colName): int
	{
		return match ($oldVal) {
			1 => 1,
			2 => 2,
			default => $this->warnAndDefault($oldNdx, $colName, $oldVal, 1),
		};
	}

	private function warnAndDefault(int $oldNdx, string $colName, int $oldVal, int $default): int
	{
		$this->warn("vat-registration {$oldNdx}: unsupported {$colName}={$oldVal}, defaulting to {$default}");
		return $default;
	}
}
