<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class CostCentersRunner extends BaseCodebookRunner
{
	protected function entityType(): string  { return LocalIdMap::ENTITY_COST_CENTER; }
	protected function targetTable(): string { return 'economy_codebooks_cost_centers'; }
	protected function entityLabel(): string { return 'cost-center'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [id], [fullName], [docState] FROM [e10doc_base_centres]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		return [
			'code'       => $this->deriveCode($oldRow['id'] ?? null, (int) $oldRow['ndx'], 'CC'),
			'name'       => (string) ($oldRow['fullName'] ?? ''),
			'valid_from' => null,
			'valid_to'   => null,
			'sort_order' => 0,
		];
	}
}
