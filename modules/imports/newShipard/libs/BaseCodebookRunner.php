<?php

namespace imports\newShipard\libs;

/**
 * Abstract base pro číselníky importované přes generický CRUD nového Shipardu.
 *
 * Životní cyklus:
 *   1. fetchSourceRows() — Dibi query proti starému DS.
 *   2. Per row → mapRow() vytvoří payload, doplní docState=40.
 *   3. Lookup v LocalIdMap:
 *        - existující → skip (re-import vyžaduje ruční forgetAll).
 *        - nový → POST přes CrudClient, výsledné id zaregistrovat v LocalIdMap.
 *   4. Souhrnný report (created / skipped / failed).
 *
 * Idempotence:
 *   Záznamy v docState=40 ("V pořádku") jsou v novém Shipardu readOnly —
 *   PATCH by vrátil 422 DOCUMENT_READONLY. Phase 02 proto NEpatchuje
 *   existující záznamy. Pokud uživatel chce re-import, smaže mapping přes
 *   LocalIdMap::forgetAll(<entityType>) — nový záznam pak vznikne paralelně
 *   se starým (unique constraint na 'code' to v případě konfliktu zastaví).
 */
abstract class BaseCodebookRunner extends ImportRunner
{
	/**
	 * Mapování starý docState (e10.base.defaultDocStatesArchive) → nový
	 * (core.system.docStatesArchive). Hodnoty mimo mapu spadnou na fallback
	 * 40 (V pořádku) — viz mapDocState().
	 *
	 * 9800 (Smazáno) nepatří do mapy — source query ho filtruje pryč ještě
	 * dřív, než se sem dostane.
	 */
	private const DOC_STATE_MAP = [
		1000 => 10,  // Rozpracováno  → Koncept
		4000 => 40,  // Potvrzeno     → V pořádku
		8000 => 80,  // V opravě      → V opravě
		9000 => 70,  // V archívu     → V archívu
	];

	abstract protected function entityType(): string;     // LocalIdMap entity type
	abstract protected function targetTable(): string;    // tabulka v novém Shipardu
	abstract protected function sourceQuery(): array;     // Dibi query (array form: ['SELECT ... %sql ...', ...args])

	/**
	 * Staré řádek → payload pro nový Shipard.
	 * Vrátí `null` pokud řádek nelze mapovat (např. neznámá enum hodnota) —
	 * processRow ho přeskočí se statusem 'skipped'/'unsupported'. Runner by
	 * měl předem v `mapRow` zalogovat důvod přes `$this->warn(...)`.
	 */
	abstract protected function mapRow(array $oldRow): ?array;

	abstract protected function entityLabel(): string;    // pro logy, např. "bank account"

	public function run(): bool
	{
		$this->info("Importing {$this->entityLabel()}...");
		$rows = $this->fetchSourceRows();
		$this->info("Found " . count($rows) . " source rows.");

		$crud = new CrudClient($this->http());
		$stats = ['created' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($rows as $oldRow)
		{
			try
			{
				$result = $this->processRow($oldRow, $crud);
				$stats[$result['status']]++;
				$this->context->stats->add($this->entityLabel(), $result['status']);
				$this->logRow($oldRow, $result);
			}
			catch (HttpException $e)
			{
				$stats['failed']++;
				$this->context->stats->add($this->entityLabel(), 'failed');
				$oldNdx = (int) ($oldRow['ndx'] ?? 0);
				$this->err("Failed {$this->entityLabel()} (old ndx={$oldNdx}): " . $e->getMessage());
				if (!$this->isContinueOnError())
				{
					$this->err("Aborting (use --continue-on-error to skip failed rows).");
					return false;
				}
			}
		}

		$this->info("");
		$this->info(sprintf(
			"Done %s: created=%d, skipped=%d, failed=%d",
			$this->entityLabel(),
			$stats['created'], $stats['skipped'], $stats['failed'],
		));

		return $stats['failed'] === 0;
	}

	/**
	 * Načte staré řádky a převede z Dibi Row na asoc. pole.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchSourceRows(): array
	{
		$rows = $this->db()->query($this->sourceQuery())->fetchAll();
		$out = [];
		foreach ($rows as $r)
			$out[] = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
		return $out;
	}

	/**
	 * @return array{status: 'created'|'skipped'|'failed', newId?: int, reason?: string}
	 */
	protected function processRow(array $oldRow, CrudClient $crud): array
	{
		$oldNdx = (int) $oldRow['ndx'];
		$payload = $this->mapRow($oldRow);

		if ($payload === null)
			return ['status' => 'skipped', 'reason' => 'unsupported'];

		// docState mapujeme ze starého záznamu; initDocState na serveru dopočítá
		// docStateMain. Nevkládáme docStateMain — je 'system: true' a
		// filterWritableFields ho stejně vyhodí.
		// Pokud mapRow explicitně nastavil docState, respektujeme.
		if (!isset($payload['docState']))
			$payload['docState'] = $this->mapDocState($oldRow);

		$existingNewId = $this->idMap()->lookup($this->entityType(), $oldNdx);
		if ($existingNewId !== null)
			return ['status' => 'skipped', 'reason' => 'already-imported', 'newId' => $existingNewId];

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would POST /api/v1/" . $this->targetTable()
				. " payload=" . json_encode($payload, JSON_UNESCAPED_UNICODE));
			return ['status' => 'skipped', 'reason' => 'dry-run'];
		}

		$newId = $crud->create($this->targetTable(), $payload);
		$this->idMap()->record($this->entityType(), $oldNdx, $newId);
		$this->afterRowImported($oldRow, $newId, $crud);
		return ['status' => 'created', 'newId' => $newId];
	}

	/**
	 * Hook volaný po úspěšném vytvoření nového záznamu. Default no-op.
	 *
	 * Override v runnerech s sub-tabulkami (např. fiscal_years → fiscal_months),
	 * kde po importu parent record potřebujeme importovat související děti.
	 *
	 * Pro skipped (already-imported) řádky se nevolá — předpokládá se, že
	 * sub-import byl proveden při původním běhu. Pokud byl tehdy neúplný,
	 * uživatel smaže mapping přes forgetAll a re-importuje celé.
	 */
	protected function afterRowImported(array $oldRow, int $newId, CrudClient $crud): void
	{
		// no-op default
	}

	protected function logRow(array $oldRow, array $result): void
	{
		$label = $this->entityLabel();
		$oldNdx = $oldRow['ndx'] ?? '?';
		$name = $oldRow['fullName'] ?? $oldRow['title'] ?? $oldRow['name'] ?? '';

		switch ($result['status'])
		{
			case 'created':
				$this->ok(sprintf("[%s] %s → %d  %s", $label, $oldNdx, $result['newId'] ?? 0, $name));
				break;
			case 'skipped':
				$reason = $result['reason'] ?? '?';
				if ($reason === 'dry-run')
					$this->info(sprintf("[%s] %s (dry-run, would create)  %s", $label, $oldNdx, $name));
				else
					$this->debug(sprintf("[%s] %s skipped (%s, new id=%s)  %s",
						$label, $oldNdx, $reason, $result['newId'] ?? '?', $name));
				break;
		}
	}

	protected function isContinueOnError(): bool
	{
		return (bool) $this->app()->arg('continue-on-error');
	}

	// ── Helpers pro per-runner mapping ───────────────────────────────────

	/**
	 * Odvodí `code` (varchar 10, NOT NULL) pro entity, kde starý `id` může být
	 * prázdný nebo příliš dlouhý.
	 *
	 * Strategie:
	 *   1. Vyplněný `$rawCode` a délka ≤ $maxLen → trim/lowercase, vrátit.
	 *   2. Vyplněný ale příliš dlouhý → truncate, debug warning.
	 *   3. Prázdný → fallback "{$prefix}{$oldNdx}".
	 */
	protected function deriveCode(?string $rawCode, int $oldNdx, string $prefix, int $maxLen = 10): string
	{
		$raw = $rawCode !== null ? trim($rawCode) : '';

		if ($raw === '')
		{
			$fallback = $prefix . $oldNdx;
			if (mb_strlen($fallback) > $maxLen)
				$fallback = mb_substr($fallback, 0, $maxLen);
			$this->debug("deriveCode: empty rawCode for old ndx={$oldNdx}, using fallback '{$fallback}'");
			return $fallback;
		}

		if (mb_strlen($raw) > $maxLen)
		{
			$truncated = mb_substr($raw, 0, $maxLen);
			$this->debug("deriveCode: truncated '{$raw}' → '{$truncated}' (old ndx={$oldNdx})");
			return $truncated;
		}

		return $raw;
	}

	/**
	 * Dibi DateTime / string / null → ISO 'Y-m-d' nebo null.
	 */
	protected function dateToString(mixed $date): ?string
	{
		if ($date === null || $date === '' || $date === '0000-00-00')
			return null;

		if ($date instanceof \DateTimeInterface)
			return $date->format('Y-m-d');

		if (is_string($date))
		{
			$ts = strtotime($date);
			return $ts !== false ? date('Y-m-d', $ts) : null;
		}

		return null;
	}

	/**
	 * Resolve oldFK → newId přes LocalIdMap. Pokud nenalezen:
	 *   - $required=false: emit warning, vrátit null.
	 *   - $required=true:  vyhodit ImportException.
	 */
	protected function resolveFk(string $entityType, ?int $oldFkValue, bool $required = false): ?int
	{
		if ($oldFkValue === null || $oldFkValue === 0)
			return null;

		$newId = $this->idMap()->lookup($entityType, $oldFkValue);
		if ($newId !== null)
			return $newId;

		$msg = "FK unresolved: entity='{$entityType}' oldFkValue={$oldFkValue} not found in LocalIdMap";
		if ($required)
			throw new ImportException($msg);

		$this->warn($msg);
		return null;
	}

	/**
	 * Starý docState (e10.base.defaultDocStatesArchive: 1000/4000/8000/9000)
	 * → nový (core.system.docStatesArchive: 10/40/80/70).
	 *
	 * Neznámé hodnoty padají na 40 (V pořádku) — bezpečný default pro
	 * číselníky. Pokud zdroj má unmapped hodnotu, runner zaloguje warning.
	 */
	protected function mapDocState(array $oldRow): int
	{
		$old = (int) ($oldRow['docState'] ?? 0);
		if (isset(self::DOC_STATE_MAP[$old]))
			return self::DOC_STATE_MAP[$old];

		$oldNdx = $oldRow['ndx'] ?? '?';
		$this->warn("{$this->entityLabel()} {$oldNdx}: unknown old docState={$old}, defaulting to 40 (V pořádku)");
		return 40;
	}
}
