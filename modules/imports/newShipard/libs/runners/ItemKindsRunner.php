<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\LocalIdMap;

final class ItemKindsRunner extends BaseCodebookRunner
{
	/**
	 * Seedované system_code hodnoty z nov_shipard:modules/economy/items/config/itemKindsSeed.jsonc.
	 * Pokud starý `id` přesně matchuje, znovu seed nezakládáme — najdeme existující
	 * záznam v novém Shipardu a jen ho zapíšeme do LocalIdMap.
	 */
	private const SEEDED_SYSTEM_CODES = ['service', 'stock', 'accounting', 'other'];

	protected function entityType(): string  { return LocalIdMap::ENTITY_ITEM_KIND; }
	protected function targetTable(): string { return 'economy_items_kinds'; }
	protected function entityLabel(): string { return 'item-kind'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [id], [fullName], [type], [validFrom], [validTo], [docState]'
			. ' FROM [e10_witems_itemtypes]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		$name = (string) ($oldRow['fullName'] ?? '');
		if (mb_strlen($name) > 100)
			$name = mb_substr($name, 0, 100);

		$systemCode = $this->sanitizeSystemCode((string) ($oldRow['id'] ?? ''), (int) $oldRow['ndx']);

		// itemType je 1:1 mapping (0=Služba, 1=Zásoba, 2=Účetní, 3=Ostatní v obou).
		$itemType = (int) ($oldRow['type'] ?? 3);

		return [
			'name'        => $name,
			'item_type'   => $itemType,
			'valid_from'  => $this->dateToString($oldRow['validFrom'] ?? null),
			'valid_to'    => $this->dateToString($oldRow['validTo'] ?? null),
			'system_code' => $systemCode,
		];
	}

	/**
	 * Override standardního processRow — item-kinds mají speciální flow:
	 *
	 *   1. Pokud existující mapping v LocalIdMap → skip ('already-imported').
	 *   2. Pokud `system_code` (sanitized old `id`) je vyplněný:
	 *        - findOneBy('system_code', X) → match → idMap.record, skip
	 *          ('matched-seeded' pokud X je v SEEDED_SYSTEM_CODES, jinak
	 *          'matched-by-system-code'). Žádný POST.
	 *   3. Jinak (nebo žádný match) → POST + idMap.record.
	 */
	protected function processRow(array $oldRow, CrudClient $crud): array
	{
		$oldNdx = (int) $oldRow['ndx'];

		$existingNewId = $this->idMap()->lookup($this->entityType(), $oldNdx);
		if ($existingNewId !== null)
			return ['status' => 'skipped', 'reason' => 'already-imported', 'newId' => $existingNewId];

		$payload = $this->mapRow($oldRow);
		$payload['docState'] = $this->mapDocState($oldRow);

		$systemCode = $payload['system_code'] ?? null;

		if (is_string($systemCode) && $systemCode !== '')
		{
			$isSeeded = in_array($systemCode, self::SEEDED_SYSTEM_CODES, true);

			if ($this->isDryRun())
			{
				$hint = $isSeeded
					? "would match seeded or POST"
					: "would findOneBy(system_code='{$systemCode}') or POST";
				$this->debug("DRY-RUN: item-kind {$oldNdx}: {$hint}");
				return ['status' => 'skipped', 'reason' => 'dry-run'];
			}

			$existing = $crud->findOneBy($this->targetTable(), 'system_code', $systemCode);
			if ($existing !== null && isset($existing['id']))
			{
				$newId = (int) $existing['id'];
				$this->idMap()->record($this->entityType(), $oldNdx, $newId);
				return [
					'status' => 'skipped',
					'reason' => $isSeeded ? 'matched-seeded' : 'matched-by-system-code',
					'newId'  => $newId,
				];
			}
		}

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would POST /api/v1/" . $this->targetTable()
				. " payload=" . json_encode($payload, JSON_UNESCAPED_UNICODE));
			return ['status' => 'skipped', 'reason' => 'dry-run'];
		}

		$newId = $crud->create($this->targetTable(), $payload);
		$this->idMap()->record($this->entityType(), $oldNdx, $newId);
		return ['status' => 'created', 'newId' => $newId];
	}

	/**
	 * Old `id` (string len 15) → new `system_code` (varchar 25, nullable).
	 * Prázdné → null. Příliš dlouhé → truncate na 25 + debug.
	 */
	private function sanitizeSystemCode(string $rawId, int $oldNdx): ?string
	{
		$code = trim($rawId);
		if ($code === '')
			return null;

		if (mb_strlen($code) > 25)
		{
			$truncated = mb_substr($code, 0, 25);
			$this->debug("item-kind {$oldNdx}: system_code '{$code}' truncated to '{$truncated}'");
			$code = $truncated;
		}

		return $code;
	}
}
