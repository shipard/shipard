<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class WarehousesRunner extends BaseCodebookRunner
{
	protected function entityType(): string  { return LocalIdMap::ENTITY_WAREHOUSE; }
	protected function targetTable(): string { return 'economy_codebooks_warehouses'; }
	protected function entityLabel(): string { return 'warehouse'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [id], [fullName], [order], [docState] FROM [e10doc_base_warehouses]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		return [
			'code'       => $this->deriveCode($oldRow['id'] ?? null, (int) $oldRow['ndx'], 'WH'),
			'name'       => (string) ($oldRow['fullName'] ?? ''),
			'valid_from' => null,
			'valid_to'   => null,
			'sort_order' => (int) ($oldRow['order'] ?? 0),
		];
	}
}
