<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class NumberSeriesRunner extends BaseCodebookRunner
{
	/**
	 * Known docTypes nového Shipardu — viz nov_shipard:modules/docs/core/config/docTypes.jsonc.
	 *
	 * Klíč = old e10.docs.types hodnota, value = ['newDocType', 'patternDefault'].
	 * pattern_default odpovídá cfgItem `doc_number_pattern_default` per typ.
	 *
	 * Nový cfgItem zná `invno`, `invni` a `cmnbkp` (účetní doklad). Identity
	 * mapping — old hodnoty jsou kompatibilní. Nové docTypes budou doplněny postupně.
	 *
	 * Pro starý typ, který v novém ještě není (cash/bank/prfmin/...), runner
	 * řádek skipne s warningem.
	 */
	private const DOC_TYPE_MAP = [
		'invno'  => ['invno', '%D%y%C%4'],
		'invni'  => ['invni', '%D%y%C%4'],
		'cmnbkp' => ['cmnbkp', '%D%y%C%4'],
	];

	protected function entityType(): string  { return LocalIdMap::ENTITY_NUMBER_SERIES; }
	protected function targetTable(): string { return 'docs_core_number_series'; }
	protected function entityLabel(): string { return 'number-series'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [docType], [fullName], [docKeyId], [docState]'
			. ' FROM [e10doc_base_docnumbers]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];
		$oldDocType = (string) ($oldRow['docType'] ?? '');

		$mapping = self::DOC_TYPE_MAP[$oldDocType] ?? null;
		if ($mapping === null)
		{
			$this->warn("number-series {$oldNdx}: unsupported docType='{$oldDocType}' (not in new Shipard cfgItem yet), skipping");
			return null;
		}
		[$newDocType, $patternDefault] = $mapping;

		$docKeyId = trim((string) ($oldRow['docKeyId'] ?? ''));
		if (mb_strlen($docKeyId) > 10)
			$docKeyId = mb_substr($docKeyId, 0, 10);

		$name = (string) ($oldRow['fullName'] ?? '');
		if (mb_strlen($name) > 100)
			$name = mb_substr($name, 0, 100);

		return [
			'doc_type'           => $newDocType,
			'name'               => $name,
			'notice'             => null,
			'doc_number_code'    => $docKeyId !== '' ? $docKeyId : null,
			'doc_number_pattern' => $patternDefault,
			'reset_scope'        => 'fiscal_year',
			'valid_from'         => null,
			'valid_to'           => null,
		];
	}
}
