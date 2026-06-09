<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

/**
 * Import účtového rozvrhu (Fáze 08): starý `e10doc_debs_accounts` →
 * nový `economy_accounting_accounts` přes generický CRUD.
 *
 * Idempotence přes LocalIdMap (klíč = starý `ndx`), POST create, žádný
 * find-by-number ani PATCH. Migrovaný DS nemá naseedovanou standardní osnovu
 * (provisioning vypnutý), takže UNIQUE `number` nekoliduje.
 *
 * `account_level` / `g1` / `g2` / `g3` počítá runner sám v mapRow()
 * (mirror AccountDocument::deriveStructure), protože generický
 * CrudController::create() nevolá beforeSave/setupData. Sloupce nejsou
 * `system`, takže projdou filterWritableFields a vloží se z payloadu.
 */
final class AccountsRunner extends BaseCodebookRunner
{
	protected function entityType(): string  { return LocalIdMap::ENTITY_ACCOUNT; }
	protected function targetTable(): string { return 'economy_accounting_accounts'; }
	protected function entityLabel(): string { return 'account'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [id], [fullName], [shortName], [accountKind], [costsType],'
			. ' [resultsType], [validFrom], [validTo], [note], [docState]'
			. ' FROM [e10doc_debs_accounts] WHERE [docState] != %i', 9800,
			' ORDER BY [id]',
		];
	}

	protected function mapRow(array $oldRow): ?array
	{
		$number = trim((string) ($oldRow['id'] ?? ''));
		if ($number === '')
		{
			$this->warn('account (old ndx=' . (int) ($oldRow['ndx'] ?? 0) . '): prázdné číslo účtu, skip');
			return null;
		}
		$name  = (string) ($oldRow['fullName'] ?? '');
		$short = trim((string) ($oldRow['shortName'] ?? ''));
		$st    = $this->structure($number);

		$payload = [
			'number'        => $number,
			'name'          => $name,
			'short_name'    => $short !== '' ? $short : $name,
			'account_level' => $st['account_level'],
			'g1'            => $st['g1'],
			'g2'            => $st['g2'],
			'g3'            => $st['g3'],
			'is_system'     => 0,
			'valid_from'    => $this->dateToString($oldRow['validFrom'] ?? null),
			'valid_to'      => $this->dateToString($oldRow['validTo'] ?? null),
		];

		$note = trim((string) ($oldRow['note'] ?? ''));
		if ($note !== '')
			$payload['note'] = $note;

		$ak = (int) ($oldRow['accountKind'] ?? 99);
		if ($ak !== 99)             // 0 = Aktiva se vkládá; 99 → NULL
			$payload['account_kind'] = $ak;

		$ct = (int) ($oldRow['costsType'] ?? 0);
		if ($ct > 0)
			$payload['costs_type'] = $ct;

		$rt = (int) ($oldRow['resultsType'] ?? 0);
		if ($rt > 0)                // vč. 3 = Mimořádný
			$payload['results_type'] = $rt;

		// docState NEnastavujeme — base->processRow ho doplní z mapDocState($oldRow).
		return $payload;
	}

	/**
	 * Mirror nového AccountDocument::deriveStructure().
	 * g1/g2/g3 se re-derivují z `number` (jediný zdroj pravdy, konzistence s UI
	 * i provisionerem) — staré g1/g2/g3 se nepřebírají.
	 *
	 * @return array{account_level:int, g1:?string, g2:?string, g3:?string}
	 */
	private function structure(string $number): array
	{
		$len   = strlen($number);
		$level = match (true) {
			$len === 1 => 1, // třída
			$len === 2 => 2, // skupina
			$len === 3 => 3, // syntetika
			default    => 4, // analytický účet
		};
		return [
			'account_level' => $level,
			'g1' => $len >= 1 ? substr($number, 0, 1) : null,
			'g2' => $len >= 2 ? substr($number, 0, 2) : null,
			'g3' => $len >= 3 ? substr($number, 0, 3) : null,
		];
	}
}
