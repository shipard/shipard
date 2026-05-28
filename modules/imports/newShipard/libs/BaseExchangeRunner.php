<?php

namespace imports\newShipard\libs;

/**
 * Abstract base pro entity importované přes exchange applier nového Shipardu
 * (persons / items / docs). Sourozenec `BaseCodebookRunner` pro generic CRUD.
 *
 * Životní cyklus:
 *   1. fetchSourceRows() — Dibi query proti starému DS.
 *   2. Volitelný `--limit=N` ořeže pole na prvních N řádků (testing).
 *   3. Per row → buildCanonical() vytvoří canonical payload.
 *   4. Pokud LocalIdMap má hit, nastaví `_resolve.header.userAction =
 *      "useExisting:<cachedNewId>"` — applier respektuje a uloží do existující.
 *   5. POST `/api/v1/_exchange/{flow}/{type}/apply`.
 *   6. Mapping výsledku na status:
 *        - 2xx + _resolve.header.matchedBy === 'created' → created
 *        - 2xx + _resolve.header.matchedBy !== 'created' → updated
 *        - HTTP 422 unresolved_required → skipped (ambiguous-header)
 *        - jiné HTTP errory → failed
 *
 * `buildCanonical` může vrátit `null` pro řádek, který nelze namapovat (např.
 * fyzická osoba bez křestního jména) → processOneRow ho přeskočí se statusem
 * 'skipped' / 'incomplete'.
 *
 * Idempotence: druhý běh nad stejnými daty zapojí na existující záznamy
 * (LocalIdMap hit → useExisting). Applier s mergeStrategy 'fullSync'
 * provede update existujících sub-collections.
 */
abstract class BaseExchangeRunner extends ImportRunner
{
	abstract protected function entityType(): string;        // LocalIdMap entity type
	abstract protected function exchangeFlow(): string;      // "persons" | "items" | "docs"
	abstract protected function exchangeType(): string;      // "person" | "item" | "document"
	abstract protected function savedIdKey(): string;        // "savedPersonId" | "savedItemId" | "savedDocId"
	abstract protected function sourceQuery(): array;        // Dibi array form
	abstract protected function entityLabel(): string;       // pro logy

	/**
	 * Mapping starého řádku → canonical payload pro nový Shipard.
	 * Vrátí null pokud řádek nelze namapovat (skip s reason 'incomplete').
	 */
	abstract protected function buildCanonical(array $oldRow): ?array;

	public function run(): bool
	{
		$this->info("Importing {$this->entityLabel()} via exchange flow...");
		$rows = $this->fetchSourceRows();

		$limit = (int) ($this->app()->arg('limit') ?? 0);
		if ($limit > 0)
		{
			$rows = array_slice($rows, 0, $limit);
			$this->info("Limit applied: processing first {$limit} rows.");
		}

		$this->info("Found " . count($rows) . " source rows.");

		$exchange = new ExchangeClient($this->http());
		$stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($rows as $oldRow)
		{
			try
			{
				$result = $this->processOneRow($oldRow, $exchange);
				$stats[$result['status']]++;
				$this->logRow($oldRow, $result);
			}
			catch (HttpException $e)
			{
				// 422 unresolved_required — ambiguous header bez userAction.
				// Runner ho přeskakuje a pokračuje (nevadí na ostatní).
				if ($e->errorCode === 'unresolved_required')
				{
					$stats['skipped']++;
					$this->logRow($oldRow, ['status' => 'skipped', 'reason' => 'ambiguous-header']);
					continue;
				}

				$stats['failed']++;
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
			"Done %s: created=%d, updated=%d, skipped=%d, failed=%d",
			$this->entityLabel(),
			$stats['created'], $stats['updated'], $stats['skipped'], $stats['failed'],
		));

		return $stats['failed'] === 0;
	}

	/**
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
	 * @return array{status: 'created'|'updated'|'skipped'|'failed', newId?: int, reason?: string, matchedBy?: string}
	 */
	protected function processOneRow(array $oldRow, ExchangeClient $exchange): array
	{
		$oldNdx = (int) $oldRow['ndx'];

		// Idempotence: existující záznam v LocalIdMap přeskakujeme. Důvod:
		// PersonResolver volá PartyResolver, který hledá osobu přes business
		// klíče (companyId/vatId/taxId/name); ne přes náš `_resolve.header.
		// userAction = useExisting`. Pokud business match selže (typicky FO
		// bez IČO), sub-resolvery dostanou $personId = null → všechny
		// sub-records canCreate → duplikace.
		//
		// Pro re-import smaž mapping: LocalIdMap::forgetAll('<entity>').
		$cachedNewId = $this->idMap()->lookup($this->entityType(), $oldNdx);
		if ($cachedNewId !== null)
			return ['status' => 'skipped', 'reason' => 'already-imported', 'newId' => $cachedNewId];

		$canonical = $this->buildCanonical($oldRow);
		if ($canonical === null)
			return ['status' => 'skipped', 'reason' => 'incomplete'];

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would POST /_exchange/" . $this->exchangeFlow() . "/" . $this->exchangeType() . "/apply"
				. " for old ndx={$oldNdx}");
			return ['status' => 'skipped', 'reason' => 'dry-run'];
		}

		$response = $exchange->apply(
			$this->exchangeFlow(),
			$this->exchangeType(),
			$canonical,
			$this->savedIdKey(),
		);

		$savedId = $response['savedId'];
		if ($savedId === null)
			return ['status' => 'failed', 'reason' => 'apply-returned-no-id'];

		$this->idMap()->record($this->entityType(), $oldNdx, $savedId);

		$matchedBy = $response['canonical']['_resolve']['header']['matchedBy'] ?? null;

		// Po apply applier anotuje canCreate → matched s matchedBy='created'.
		// Existující match (companyId/vatId/taxId/useExisting) má jiný matchedBy.
		$status = ($matchedBy === 'created') ? 'created' : 'updated';

		return [
			'status'    => $status,
			'newId'     => $savedId,
			'matchedBy' => is_string($matchedBy) ? $matchedBy : null,
		];
	}

	protected function logRow(array $oldRow, array $result): void
	{
		$label = $this->entityLabel();
		$oldNdx = $oldRow['ndx'] ?? '?';
		$name = $oldRow['fullName'] ?? $oldRow['name'] ?? '';

		switch ($result['status'])
		{
			case 'created':
				$this->ok(sprintf("[%s] %s → %d  %s",
					$label, $oldNdx, $result['newId'] ?? 0, $name));
				break;

			case 'updated':
				$by = $result['matchedBy'] ?? '?';
				$this->info(sprintf("[%s] %s ↻ %d (matched by %s)  %s",
					$label, $oldNdx, $result['newId'] ?? 0, $by, $name));
				break;

			case 'skipped':
				$reason = $result['reason'] ?? '?';
				if ($reason === 'dry-run')
					$this->info(sprintf("[%s] %s (dry-run, would apply)  %s", $label, $oldNdx, $name));
				elseif ($reason === 'incomplete')
					$this->warn(sprintf("[%s] %s skipped (incomplete data)  %s", $label, $oldNdx, $name));
				elseif ($reason === 'already-imported')
					$this->debug(sprintf("[%s] %s skipped (already-imported, new id=%s)  %s",
						$label, $oldNdx, $result['newId'] ?? '?', $name));
				else
					$this->warn(sprintf("[%s] %s skipped (%s)  %s", $label, $oldNdx, $reason, $name));
				break;

			case 'failed':
				$this->err(sprintf("[%s] %s FAILED (%s)  %s",
					$label, $oldNdx, $result['reason'] ?? '?', $name));
				break;
		}
	}

	protected function isContinueOnError(): bool
	{
		return (bool) $this->app()->arg('continue-on-error');
	}
}
