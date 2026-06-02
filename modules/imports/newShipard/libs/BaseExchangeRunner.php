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
				$desc = $this->rowDescriptor($oldRow);
				$this->err("Failed {$this->entityLabel()} (old ndx={$oldNdx}"
					. ($desc !== '' ? ", {$desc}" : '') . "): " . $e->getMessage());
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
			if ($this->isDumpPayload())
				$this->dumpJson("canonical payload (old ndx={$oldNdx}, {$this->rowDescriptor($oldRow)})", $canonical);
			return ['status' => 'skipped', 'reason' => 'dry-run'];
		}

		if ($this->isDumpPayload())
			$this->dumpJson("canonical payload (old ndx={$oldNdx}, {$this->rowDescriptor($oldRow)})", $canonical);

		try
		{
			$response = $exchange->apply(
				$this->exchangeFlow(),
				$this->exchangeType(),
				$canonical,
				$this->savedIdKey(),
			);
		}
		catch (HttpException $e)
		{
			// Genuine failure (ne očekávaný unresolved_required skip) → vypiš
			// odeslaný payload + response body, ať jde ověřit, na které straně
			// je chyba (importér vs. applier). Bez flagu, protože failures jsou
			// vzácné a payload je přesně to, co se k ladění potřebuje.
			if ($e->errorCode !== 'unresolved_required')
			{
				$this->dumpJson("FAILED request canonical (old ndx={$oldNdx}, {$this->rowDescriptor($oldRow)})", $canonical);
				if ($e->responseBody !== null)
					$this->dumpJson("FAILED response body (old ndx={$oldNdx})", $e->responseBody);
			}
			throw $e;
		}

		$savedId = $response['savedId'];
		if ($savedId === null)
			return ['status' => 'failed', 'reason' => 'apply-returned-no-id'];

		$this->idMap()->record($this->entityType(), $oldNdx, $savedId);

		// Hook pro post-apply operace (např. PATCH na docState přes generic
		// CRUD pro stavy, které applier neumí v `applyOptions.targetDocState`).
		$this->afterApplied($oldRow, $savedId, new CrudClient($this->http()));

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
		$desc = $this->rowDescriptor($oldRow);

		switch ($result['status'])
		{
			case 'created':
				$this->ok(sprintf("[%s] %s → %d  %s",
					$label, $oldNdx, $result['newId'] ?? 0, $desc));
				break;

			case 'updated':
				$by = $result['matchedBy'] ?? '?';
				$this->info(sprintf("[%s] %s ↻ %d (matched by %s)  %s",
					$label, $oldNdx, $result['newId'] ?? 0, $by, $desc));
				break;

			case 'skipped':
				$reason = $result['reason'] ?? '?';
				if ($reason === 'dry-run')
					$this->info(sprintf("[%s] %s (dry-run, would apply)  %s", $label, $oldNdx, $desc));
				elseif ($reason === 'incomplete')
					$this->warn(sprintf("[%s] %s skipped (incomplete data)  %s", $label, $oldNdx, $desc));
				elseif ($reason === 'already-imported')
					$this->debug(sprintf("[%s] %s skipped (already-imported, new id=%s)  %s",
						$label, $oldNdx, $result['newId'] ?? '?', $desc));
				else
					$this->warn(sprintf("[%s] %s skipped (%s)  %s", $label, $oldNdx, $reason, $desc));
				break;

			case 'failed':
				$this->err(sprintf("[%s] %s FAILED (%s)  %s",
					$label, $oldNdx, $result['reason'] ?? '?', $desc));
				break;
		}
	}

	/**
	 * Lidsky čitelný popisek řádku pro logy (za ndx). Default: fullName/name
	 * ze starého řádku. Override v runnerech, kde dává smysl jiný identifikátor
	 * (např. DocsRunner → docType + docNumber).
	 */
	protected function rowDescriptor(array $oldRow): string
	{
		return (string) ($oldRow['fullName'] ?? $oldRow['name'] ?? '');
	}

	protected function isContinueOnError(): bool
	{
		return (bool) $this->app()->arg('continue-on-error');
	}

	protected function isDumpPayload(): bool
	{
		return (bool) $this->app()->arg('dump-payload');
	}

	/**
	 * Vypíše data jako pretty-printed JSON (pro ladění odesílaných payloadů /
	 * error response). Unicode i lomítka nezescapované kvůli čitelnosti.
	 */
	protected function dumpJson(string $label, array $data): void
	{
		$this->logger()->block("── {$label} ──\n"
			. json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Hook volaný po úspěšném apply (po LocalIdMap::record). Default no-op.
	 *
	 * Override v runnerech, které potřebují post-apply operace přes generic
	 * CRUD — typicky když applier nemá kontrolu nad nějakým polem (např.
	 * PersonsRunner posune docState na 70/80, které `applyOptions.targetDocState`
	 * schema neumožňuje).
	 *
	 * V dry-run módu se hook nevolá vůbec (apply se taky neprovádí).
	 */
	protected function afterApplied(array $oldRow, int $newId, CrudClient $crud): void
	{
		// no-op default
	}
}
