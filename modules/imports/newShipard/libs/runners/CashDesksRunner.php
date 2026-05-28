<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class CashDesksRunner extends BaseCodebookRunner
{
	protected function entityType(): string  { return LocalIdMap::ENTITY_CASH_DESK; }
	protected function targetTable(): string { return 'economy_codebooks_cash_desks'; }
	protected function entityLabel(): string { return 'cash-desk'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [id], [fullName], [currency], [order], [docState] FROM [e10doc_base_cashboxes]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		$currency = strtolower(trim((string) ($oldRow['currency'] ?? '')));
		if ($currency === '')
			$currency = 'czk';

		return [
			'code'       => $this->deriveCode($oldRow['id'] ?? null, (int) $oldRow['ndx'], 'CD'),
			'name'       => (string) ($oldRow['fullName'] ?? ''),
			'notice'     => null,
			'currency'   => $currency,
			'is_default' => 0,
			'valid_from' => null,
			'valid_to'   => null,
			'sort_order' => (int) ($oldRow['order'] ?? 0),
		];
	}
}
