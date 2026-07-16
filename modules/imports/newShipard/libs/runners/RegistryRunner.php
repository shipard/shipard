<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\AttachmentImporter;
use imports\newShipard\libs\AttachmentReader;
use imports\newShipard\libs\AttachmentUploadClient;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\HttpException;
use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\LocalIdMap;

/**
 * Import starého modulu Dokumenty (`wkf_docs_documents`, `wkf_docs_folders`,
 * `wkf_docs_docsKinds`) do Spisovny nového Shipardu (`base_registry_documents`,
 * `base_registry_binders`, tableId 428 pro přílohy).
 *
 * Dvoufázový běh (vzor MailRunner):
 *   (A) buildBinders()      — živé kořeny stromu složek → šanony
 *       (`base_registry_binders`) přes generický CRUD, idempotentně přes
 *       ENTITY_BINDER. Zploštění hierarchie (design D3): šanon per živý kořen.
 *   (B) processDocument()   — dokumenty přes POST /_registry/import (zachované
 *       historické `created`, cílový docState, dedupe přes legacy.ndx). Přílohy
 *       endpointem netečou — nahrává je AttachmentImporter na tableId 428.
 *
 * Pořadí: registry je terminální datová fáze (za poštou) — nezávisí na
 * persons/docs/mail; autor se přenáší jen jako jméno (legacy.author), partner
 * se nemapuje.
 *
 * Viz tasks/18-registry-import.md, nov_shipard:docs/registry-mvp.md §10 a
 * nov_shipard:tasks/registry-import-endpoint.md.
 */
final class RegistryRunner extends ImportRunner
{
	/** wkf docs/folders/docsKinds docState "Smazáno" (plošná konvence runnerů). */
	private const DOC_STATE_TRASH = 9800;

	/** Cílová tabulka dokumentů Spisovny — pro upload příloh (design §10.1). */
	private const REGISTRY_TABLE_ID = 428;

	/** Aktivní docState šanonu — endpoint resolvuje binder jen mezi 10/40/80. */
	private const BINDER_STATE_ACTIVE = 40;

	/** Rozsah `base_registry_binders.order_pos` (SMALLINT) — staré `order` je int. */
	private const ORDER_POS_MIN = -32768;
	private const ORDER_POS_MAX = 32767;

	/** Exchange schéma dokumentu Spisovny (viz registry-import-endpoint.md). */
	private const SCHEMA_ID = 'shpd.registry.document.v1';

	/**
	 * Mapa druhů (M1): `docsKinds.fullName` (lowercase, trim) → nový docKind.
	 * Vše ostatní → `other`. Původní název vždy do legacy.kind.
	 */
	private const KIND_MAP = [
		'smlouva' => 'contract',
	];
	private const KIND_DEFAULT = 'other';

	/**
	 * Mapa stavů (M3): starý wkf docs docState → nový docState Spisovny
	 * (core.system.docStatesArchive). Neznámý stav → 40 (výchozí endpointu).
	 *   1000 → 10 (Koncept), 4000 → 40 (V pořádku), 8000 → 80 (V opravě),
	 *   9000 → 70 (V archívu; staré "V Archívu / Ukončit platnost").
	 * Smazané (9800) se filtrují ve fetchDocumentsBatch().
	 */
	private const STATE_MAP = [
		1000 => 10, 4000 => 40, 8000 => 80, 9000 => 70,
	];
	private const STATE_DEFAULT = 40;

	/** Cache: docsKinds.ndx → fullName (načteno jednou pro celý běh). */
	private array $docsKinds = [];

	/** Cache resolveRootFolder(): folder ndx → [rootNdx|null, fullPath|null]. */
	private array $folderResolveCache = [];

	/** Počet šanonů založených v tomto běhu (fáze A) — do souhrnu. */
	private int $bindersCreated = 0;

	public function run(): bool
	{
		$this->info("Importing documents (wkf.docs) → Spisovna (base.registry)...");

		$this->loadDocsKinds();

		// (A) šanony = živé kořeny stromu složek — zakládají se PŘED dokumenty
		if (!$this->buildBinders())
			return false;

		// (B) dokumenty — čteno po dávkách (keyset přes ndx), aby paměťová špička
		// nerostla s počtem řádků (memo `text` může být velké → fetchAll bez limitu
		// materializuje celý result set; vzor tasků 16/17).
		$this->info("Found " . $this->countDocuments() . " documents (docState != 9800).");

		$limit = max(0, (int) ($this->app()->arg('limit') ?? 0));
		$batch = max(1, (int) ($this->app()->arg('batch') ?? 500));

		$stats = [
			'imported' => 0, 'skippedExisting' => 0, 'failed' => 0,
			'bindersCreated' => $this->bindersCreated,
			'unmappedKinds' => 0,
			'att_uploaded' => 0, 'att_duplicate' => 0, 'att_missing' => 0, 'att_failed' => 0,
		];

		$processed = 0;
		$afterNdx = 0;
		while (true)
		{
			$rows = $this->fetchDocumentsBatch($afterNdx, $batch);
			if ($rows === [])
				break;

			foreach ($rows as $doc)
			{
				// Kurzor = ndx posledního NAČTENÉHO řádku (i failed/skipped) —
				// posun i přes chyby, jinak nekonečná smyčka s --continue-on-error.
				$afterNdx = (int) $doc['ndx'];

				if (!$this->processDocument($doc, $stats) && !$this->isContinueOnError())
					return false;

				$this->tick('registry', ++$processed, [
					'imported' => $stats['imported'], 'skipped' => $stats['skippedExisting'], 'failed' => $stats['failed'],
				]);

				if ($limit > 0 && $processed >= $limit)
					break 2;   // --limit napříč dávkami
			}

			unset($rows);   // uvolnit dávku před načtením další
		}

		$this->printDone($stats);
		return true;   // chyby řádků → exit code 2 přes Logger::errorCount()
	}

	// ── Číselník druhů ───────────────────────────────────────────────────

	/** Načte docsKinds (ndx → fullName) — mapa pro mapKind(), jednou za běh. */
	private function loadDocsKinds(): void
	{
		foreach ($this->db()->query('SELECT [ndx], [fullName] FROM [wkf_docs_docsKinds]')->fetchAll() as $r)
		{
			$row = (array) $r;
			$this->docsKinds[(int) $row['ndx']] = (string) ($row['fullName'] ?? '');
		}
	}

	// ── Fáze A — šanony ──────────────────────────────────────────────────

	/**
	 * Pro každý živý kořen stromu složek (parentFolder=0, docState!=9800)
	 * zajistí šanon (`base_registry_binders`) idempotentně přes ENTITY_BINDER.
	 * Šanon endpoint nezakládá — dokumenty ho referencují jménem.
	 */
	private function buildBinders(): bool
	{
		$roots = $this->db()->query(
			'SELECT * FROM [wkf_docs_folders]'
			. ' WHERE [parentFolder] = %i', 0,
			' AND [docState] != %i', self::DOC_STATE_TRASH,
			' ORDER BY [order], [ndx]',
		)->fetchAll();

		$crud = new CrudClient($this->http());

		foreach ($roots as $r)
		{
			$root = (array) $r;
			$rootNdx = (int) $root['ndx'];

			if ($this->idMap()->lookup(LocalIdMap::ENTITY_BINDER, $rootNdx) !== null)
				continue;   // už založen (re-run)

			$name = $this->binderName($root);
			$payload = [
				'name'      => $name,
				'order_pos' => $this->clampOrderPos((int) ($root['order'] ?? 0), $rootNdx),
				'docState'  => self::BINDER_STATE_ACTIVE,   // 40 → nalezitelný resolverem endpointu
			];

			if ($this->isDryRun())
			{
				$this->debug("DRY-RUN: would create binder '{$name}' (folder {$rootNdx})");
				continue;
			}

			try
			{
				$newId = $crud->create('base_registry_binders', $payload);
				$this->idMap()->record(LocalIdMap::ENTITY_BINDER, $rootNdx, $newId);
				$this->bindersCreated++;
				$this->context->stats->add('binder', 'created');
				$this->ok("[binder] folder {$rootNdx} → {$newId} ('{$name}')");
			}
			catch (HttpException $e)
			{
				$this->err("Failed binder for folder {$rootNdx} ('{$name}'): {$e->getMessage()}");
				if (!$this->isContinueOnError())
					return false;
			}
		}
		return true;
	}

	/** Název šanonu: shortName, fallback fullName, fallback "Složka {ndx}". */
	private function binderName(array $folder): string
	{
		return $this->emptyToNull($folder['shortName'] ?? null)
			?? $this->emptyToNull($folder['fullName'] ?? null)
			?? ('Složka ' . (int) $folder['ndx']);
	}

	/**
	 * Staré `order` (int) → `order_pos` (SMALLINT). Hodnoty mimo rozsah osekává
	 * na krajní mez (řazení tabů je tím zachované pro naprostou většinu; přeteklé
	 * skončí na kraji) a zaloguje.
	 */
	private function clampOrderPos(int $order, int $folderNdx): int
	{
		if ($order < self::ORDER_POS_MIN)
		{
			$this->warn("[binder] folder {$folderNdx}: order {$order} mimo rozsah SMALLINT → " . self::ORDER_POS_MIN);
			return self::ORDER_POS_MIN;
		}
		if ($order > self::ORDER_POS_MAX)
		{
			$this->warn("[binder] folder {$folderNdx}: order {$order} mimo rozsah SMALLINT → " . self::ORDER_POS_MAX);
			return self::ORDER_POS_MAX;
		}
		return $order;
	}

	// ── Fáze B — dokumenty ───────────────────────────────────────────────

	private function processDocument(array $doc, array &$stats): bool
	{
		$oldNdx = (int) $doc['ndx'];

		if ($this->idMap()->lookup(LocalIdMap::ENTITY_REGISTRY_DOC, $oldNdx) !== null)
		{
			$stats['skippedExisting']++;
			$this->context->stats->add('registry', 'skipped');
			$this->debug("[registry] {$oldNdx} skipped (already-imported)");
			return true;
		}

		// složka → kořen (binder jménem) + plná cesta do legacy.folder
		[$rootNdx, $folderPath, $rootBinderName] = $this->resolveRootFolder((int) ($doc['folder'] ?? 0));
		// binder existuje jen pro živý kořen (M2); jinak dokument jde bez šanonu,
		// legacy.folder cestu si nese pořád.
		$binderName = ($rootNdx !== null && $this->idMap()->lookup(LocalIdMap::ENTITY_BINDER, $rootNdx) !== null)
			? $rootBinderName
			: null;

		// druh (M1)
		[$docKind, $legacyKind, $kindUnmapped] = $this->mapKind((int) ($doc['documentKind'] ?? 0));
		if ($kindUnmapped)
			$stats['unmappedKinds']++;

		$payload = $this->buildPayload($doc, $docKind, $legacyKind, $binderName, $folderPath);

		if ($this->isDryRun())
		{
			$stats['imported']++;
			$this->debug("DRY-RUN: would import document (old ndx={$oldNdx}, kind={$docKind}, binder=" . ($binderName ?? '—') . ")");
			return true;
		}

		try
		{
			$resp = $this->http()->post('/_registry/import', $payload);
			$newId = (int) ($resp['data']['id'] ?? 0);
			if ($newId <= 0)
			{
				$stats['failed']++;
				$this->context->stats->add('registry', 'failed');
				$this->err("[registry] {$oldNdx} no id in response");
				return $this->isContinueOnError();
			}

			$this->idMap()->record(LocalIdMap::ENTITY_REGISTRY_DOC, $oldNdx, $newId);

			if (!empty($resp['data']['existed']))
			{
				$stats['skippedExisting']++;
				$this->context->stats->add('registry', 'skipped');
				$this->debug("[registry] {$oldNdx} → {$newId} (existed on target)");
			}
			else
			{
				$stats['imported']++;
				$this->context->stats->add('registry', 'created');
				$this->ok("[registry] {$oldNdx} → {$newId} ({$docKind})");
			}

			if (isset($resp['data']['warning']))
				$this->warn("[registry] {$oldNdx}: " . (string) $resp['data']['warning'] . " (binder='" . ($binderName ?? '') . "')");

			// přílohy (Fáze 07a) — tableId 428
			if (!(bool) $this->app()->arg('no-attachments'))
				$this->importAttachments($oldNdx, $newId, $stats);

			return true;
		}
		catch (HttpException $e)
		{
			$stats['failed']++;
			$this->context->stats->add('registry', 'failed');
			$this->err("[registry] {$oldNdx} FAILED: {$e->getMessage()}");
			return $this->isContinueOnError();
		}
	}

	/**
	 * Druh dokumentu (M1): documentKind (ndx) → docsKinds.fullName → docKind.
	 * Lowercase+trim název, mapa KIND_MAP, jinak `other`. Původní název vždy
	 * do legacy.kind. Neznámý/prázdný ndx nebo nemapovaný název → unmapped=true.
	 *
	 * @return array{0: string, 1: ?string, 2: bool}  [docKind, legacyKind, unmapped]
	 */
	private function mapKind(int $documentKind): array
	{
		$fullName = $this->docsKinds[$documentKind] ?? null;
		$legacyKind = $this->emptyToNull($fullName);

		if ($legacyKind === null)
			return [self::KIND_DEFAULT, null, true];   // neznámý ndx / prázdný název

		$key = mb_strtolower(trim($legacyKind));
		if (isset(self::KIND_MAP[$key]))
			return [self::KIND_MAP[$key], $legacyKind, false];

		return [self::KIND_DEFAULT, $legacyKind, true];   // nemapovaný → other + počítadlo
	}

	/**
	 * Starý wkf docs docState → nový docState Spisovny. Neznámý → 40.
	 */
	private function mapState(int $oldState): int
	{
		if (isset(self::STATE_MAP[$oldState]))
			return self::STATE_MAP[$oldState];
		$this->warn("[registry] neznámý docState {$oldState} → mapuji na výchozí " . self::STATE_DEFAULT);
		return self::STATE_DEFAULT;
	}

	/**
	 * Sestaví payload pro POST /_registry/import (shpd.registry.document.v1 +
	 * import blok). Prázdné validity se vynechávají; legacy blok je kompletní.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>
	 */
	private function buildPayload(array $doc, string $docKind, ?string $legacyKind, ?string $binderName, ?string $folderPath): array
	{
		$oldNdx = (int) $doc['ndx'];

		$payload = [
			'schema'   => self::SCHEMA_ID,
			'docKind'  => $docKind,
			'title'    => $this->emptyToNull($doc['title'] ?? null) ?? ('Dokument #' . $oldNdx),
			'notice'   => $this->emptyToNull($doc['text'] ?? null),
			'docState' => $this->mapState((int) ($doc['docState'] ?? 0)),
			'created'  => $this->dateTimeToIso($doc['dateCreate'] ?? null) ?? date('Y-m-d\TH:i:sP'),
			'legacy'   => [
				'ndx'    => $oldNdx,
				'id'     => $this->emptyToNull($doc['documentId'] ?? null),
				'kind'   => $legacyKind,
				'author' => $this->loadPersonName((int) ($doc['author'] ?? 0)),
				'folder' => $folderPath,
			],
		];

		$validFrom = $this->dateOnly($doc['validFrom'] ?? null);
		if ($validFrom !== null)
			$payload['validFrom'] = $validFrom;
		$validTo = $this->dateOnly($doc['validTo'] ?? null);
		if ($validTo !== null)
			$payload['validTo'] = $validTo;

		if ($binderName !== null)
			$payload['binder'] = $binderName;

		return $payload;
	}

	/**
	 * Průchod folder → kořen stromu (parentFolder), s cycle guardem a cache.
	 * Vrací [rootNdx, plná cesta "Rodič / Dítě", jméno šanonu kořene] nebo
	 * [null, null, null] pro folder=0 / neexistující složku. Hloubka reálně ≤2,
	 * smyčka je obecná.
	 *
	 * @return array{0: ?int, 1: ?string, 2: ?string}  [rootNdx, fullPath, rootBinderName]
	 */
	private function resolveRootFolder(int $folderNdx): array
	{
		if ($folderNdx <= 0)
			return [null, null, null];
		if (array_key_exists($folderNdx, $this->folderResolveCache))
			return $this->folderResolveCache[$folderNdx];

		$names   = [];   // od listu ke kořeni; obrátíme na konci
		$visited = [];
		$rootNdx = null;
		$rootBinderName = null;
		$cur     = $folderNdx;

		while ($cur > 0)
		{
			if (isset($visited[$cur]))
			{
				$this->warn("[registry] cyklus ve složkách u ndx {$folderNdx} (přeťato u {$cur})");
				break;
			}
			$visited[$cur] = true;

			$row = $this->db()->query('SELECT [ndx], [fullName], [shortName], [parentFolder] FROM [wkf_docs_folders] WHERE [ndx] = %i', $cur)->fetch();
			if ($row === null)
				break;   // rozbitá reference; co jsme nasbírali, stačí
			$f = (array) $row;

			$name = $this->emptyToNull($f['fullName'] ?? null);
			if ($name !== null)
				$names[] = $name;

			$rootNdx        = (int) $f['ndx'];
			$rootBinderName = $this->binderName($f);   // přepíše se až kořenem (poslední iterace)
			$cur            = (int) ($f['parentFolder'] ?? 0);
		}

		$path = $names !== [] ? implode(' / ', array_reverse($names)) : null;
		return $this->folderResolveCache[$folderNdx] = [$rootNdx, $path, $rootBinderName];
	}

	private function loadPersonName(int $personNdx): ?string
	{
		if ($personNdx <= 0)
			return null;
		$r = $this->db()->query('SELECT [fullName] FROM [e10_persons_persons] WHERE [ndx] = %i', $personNdx)->fetch();
		return $r !== null ? $this->emptyToNull(((array) $r)['fullName'] ?? null) : null;
	}

	// ── Přílohy ──────────────────────────────────────────────────────────

	private function importAttachments(int $docNdx, int $newDocId, array &$stats): void
	{
		$importer = new AttachmentImporter(
			new AttachmentReader($this->db(), __APP_DIR__),
			new AttachmentUploadClient($this->http()),
			$this->logger(),
		);
		$r = $importer->importFor('wkf.docs.documents', $docNdx, self::REGISTRY_TABLE_ID, $newDocId);

		$stats['att_uploaded']  += $r['uploaded'];
		$stats['att_duplicate'] += $r['duplicate'];
		$stats['att_missing']   += $r['missing'];
		$stats['att_failed']    += $r['failed'];

		if (array_sum($r) > 0)
			$this->debug("[registry] doc {$docNdx}: attachments "
				. "uploaded={$r['uploaded']} dup={$r['duplicate']} missing={$r['missing']} failed={$r['failed']}");
	}

	// ── Source query + helpers ───────────────────────────────────────────

	/** Počet dokumentů (bez smazaných, volitelně okno na dateCreate). */
	private function countDocuments(): int
	{
		$q = [
			'SELECT COUNT(*) FROM [wkf_docs_documents] d'
			. ' WHERE d.[docState] != %i', self::DOC_STATE_TRASH,
		];
		$this->appendDateWindow($q, 'd');
		return (int) $this->db()->query($q)->fetchSingle();
	}

	/**
	 * Jedna dávka dokumentů (bez smazaných, volitelně okno na dateCreate).
	 * Keyset pagination přes ndx (PK): jen řádky s ndx > $afterNdx, seřazené,
	 * max $batchSize. Paměťová špička je tak omezená velikostí dávky.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchDocumentsBatch(int $afterNdx, int $batchSize): array
	{
		$q = [
			'SELECT d.* FROM [wkf_docs_documents] d'
			. ' WHERE d.[docState] != %i', self::DOC_STATE_TRASH,
			' AND d.[ndx] > %i', $afterNdx,
		];
		$this->appendDateWindow($q, 'd');
		$q[] = ' ORDER BY d.[ndx]';
		$q[] = ' LIMIT %i';
		$q[] = $batchSize;

		$out = [];
		foreach ($this->db()->query($q)->fetchAll() as $r)
			$out[] = (array) $r;
		return $out;
	}

	/** Připojí --from/--to filtr na {alias}.dateCreate do dibi query pole. */
	private function appendDateWindow(array &$q, string $alias): void
	{
		$from = $this->dateArg('from');
		$to   = $this->dateArg('to');
		if ($from !== null)
		{
			$q[] = " AND {$alias}.[dateCreate] >= %d";
			$q[] = $from;
		}
		if ($to !== null)
		{
			$q[] = " AND {$alias}.[dateCreate] <= %d";
			$q[] = $to;
		}
	}

	/** Parse --from/--to jako YYYY-MM-DD; null pokud chybí/nevalidní (s warningem). */
	private function dateArg(string $name): ?string
	{
		$raw = $this->app()->arg($name);
		if (!is_string($raw) || $raw === '')
			return null;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw))
		{
			$this->warn("Invalid --{$name} date '{$raw}' (expected YYYY-MM-DD), ignoring.");
			return null;
		}
		return $raw;
	}

	/** DateTime/string → ISO8601 s offsetem; 0000-…/prázdné → null. */
	private function dateTimeToIso(mixed $value): ?string
	{
		if ($value === null)
			return null;
		if ($value instanceof \DateTimeInterface)
			return $value->format('Y-m-d\TH:i:sP');
		$s = trim((string) $value);
		if ($s === '' || str_starts_with($s, '0000-00-00'))
			return null;
		$ts = strtotime($s);
		return $ts !== false ? date('Y-m-d\TH:i:sP', $ts) : null;
	}

	/** DateTime/string → YYYY-MM-DD; 0000-…/prázdné → null (validity). */
	private function dateOnly(mixed $value): ?string
	{
		if ($value === null)
			return null;
		if ($value instanceof \DateTimeInterface)
			return $value->format('Y-m-d');
		$s = trim((string) $value);
		if ($s === '' || str_starts_with($s, '0000-00-00'))
			return null;
		$ts = strtotime($s);
		return $ts !== false ? date('Y-m-d', $ts) : null;
	}

	private function emptyToNull(mixed $value): ?string
	{
		if ($value === null)
			return null;
		$trimmed = trim((string) $value);
		return $trimmed === '' ? null : $trimmed;
	}

	private function isContinueOnError(): bool
	{
		return (bool) $this->app()->arg('continue-on-error');
	}

	/**
	 * @param array<string, int> $stats
	 */
	private function printDone(array $stats): void
	{
		$this->summary("");
		$this->summary(sprintf(
			"Done registry: imported=%d, skippedExisting=%d, failed=%d | binders created=%d, unmappedKinds=%d",
			$stats['imported'], $stats['skippedExisting'], $stats['failed'],
			$stats['bindersCreated'], $stats['unmappedKinds'],
		));
		$this->summary(sprintf(
			"  attachments: uploaded=%d, duplicate=%d, missing=%d, failed=%d",
			$stats['att_uploaded'], $stats['att_duplicate'], $stats['att_missing'], $stats['att_failed'],
		));
		$this->summary("  » post-import na CÍLI (doplní fulltexty z příloh): shpd-ds registry-extract-texts");
	}
}
