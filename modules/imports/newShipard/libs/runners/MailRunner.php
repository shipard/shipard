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
 * Import došlé pošty ze starého Shipardu (wkf_core_issues, issueType=1 =
 * Došlá pošta) do nového (core_mail_incoming_messages, tableId 303).
 *
 * Dvoufázový běh:
 *   (A) ensureMailboxes() — sekce s inbox zprávami → schránky core_mail_mailboxes
 *       (idempotentně přes ENTITY_MAILBOX). Plochá struktura, syntetické adresy.
 *   (B) processIssue()   — zprávy přes POST /_mail/import (message_id generuje
 *       beforeSave; CRUD pro zprávy nestačí). Vazba na doklad přes
 *       e10_base_doclinks (e10docs-inbox), best-effort.
 *
 * Pořadí: pošta je terminální fáze řetězce codebooks → persons → items → docs
 * → mail (doklady se importují PŘED poštou kvůli vazbě ENTITY_DOC).
 *
 * Viz tasks/07b-mail.md a nov_shipard:tasks/mail-phase4-import-endpoint.md.
 */
final class MailRunner extends ImportRunner
{
	/** wkf issues: issueType pro Došlou poštu. */
	private const ISSUE_TYPE_INBOX = 1;

	/** wkf issues docState "Smazáno" (viz wkf.issues.docStates.default.json). */
	private const ISSUE_STATE_TRASH = 9800;

	/**
	 * Starý issues.docState (wkf.issues.docStates.default) → nový docState
	 * (core.mail.docStatesIncoming). Nezávisle na navázání na doklad.
	 *   1000 Nově rozpracováno → 10 Nová
	 *   1001 Nová zpráva       → 10 Nová
	 *   1200 K řešení          → 10 Nová
	 *   4000 Vyřešeno          → 40 Zpracovaná
	 *   8000 V opravě          → 10 Nová
	 *   9000 Ukončeno          → 80 Archiv
	 *   9800 Smazáno           → (filtrováno ve fetchIssues)
	 * Neznámý stav → 10 (Nová).
	 */
	private const ISSUE_STATE_MAP = [
		1000 => 10, 1001 => 10, 1200 => 10, 4000 => 40, 8000 => 10, 9000 => 80,
	];

	/** e10doc.core.heads — ndx tabulky dokladů (statický, viz heads.json). */
	private const DOCS_HEADS_TABLE_NDX = 1078;

	/** Cílová tabulka zpráv v novém Shipardu — pro upload příloh. */
	private const MAIL_TABLE_ID = 303;

	/** Starý → nový source_type (core_mail_incoming_messages.source_type). */
	private const SOURCE_TYPE_MAP = [
		0 => 1,   // Ručně
		1 => 2,   // E-mail
		2 => 3,   // API
		3 => 1,   // Test → Ručně
	];

	/** Cache section ndx → mailbox kód (šetří per-zprávu dotaz na sekci). */
	private array $sectionCodeCache = [];

	public function run(): bool
	{
		$this->info("Importing incoming mail (issueType=1)...");

		// (A) schránky pro sekce, do kterých padá importovaná pošta
		if (!$this->ensureMailboxes())
			return false;

		// (B) zprávy
		$rows = $this->fetchIssues();
		$limit = (int) ($this->app()->arg('limit') ?? 0);
		if ($limit > 0)
			$rows = array_slice($rows, 0, $limit);

		$this->info("Found " . count($rows) . " inbox messages.");

		$stats = [
			'created' => 0, 'skipped' => 0, 'failed' => 0,
			'linked'  => 0, 'unlinked' => 0,
			'att_uploaded' => 0, 'att_duplicate' => 0, 'att_missing' => 0, 'att_failed' => 0,
		];

		foreach ($rows as $issue)
		{
			if (!$this->processIssue($issue, $stats) && !$this->isContinueOnError())
				return false;
		}

		$this->printDone($stats);
		return $stats['failed'] === 0;
	}

	// ── Fáze A — schránky ────────────────────────────────────────────────

	/**
	 * Pro každou sekci referencovanou importovanými inbox zprávami zajistí
	 * schránku (idempotentně přes ENTITY_MAILBOX).
	 */
	private function ensureMailboxes(): bool
	{
		$q = [
			'SELECT DISTINCT i.[section] FROM [wkf_core_issues] i'
			. ' WHERE i.[issueType] = %i', self::ISSUE_TYPE_INBOX,
			' AND i.[docState] != %i', self::ISSUE_STATE_TRASH,
			' AND i.[section] > %i', 0,
		];
		$this->appendDateWindow($q, 'i');

		$sectionNdxs = [];
		foreach ($this->db()->query($q)->fetchAll() as $r)
			$sectionNdxs[] = (int) ((array) $r)['section'];

		$crud = new CrudClient($this->http());

		foreach ($sectionNdxs as $secNdx)
		{
			if ($this->idMap()->lookup(LocalIdMap::ENTITY_MAILBOX, $secNdx) !== null)
				continue;   // už existuje

			$secRow = $this->db()->query('SELECT * FROM [wkf_base_sections] WHERE [ndx] = %i', $secNdx)->fetch();
			if ($secRow === null)
				continue;
			$sec = (array) $secRow;

			$mailboxCode = $this->mailboxCode($sec);
			$payload = [
				'mailbox_id'    => $mailboxCode,
				'name'          => $this->emptyToNull($sec['fullName'] ?? null) ?? $mailboxCode,
				'email_address' => $mailboxCode . '@imported.invalid',
				'is_default'    => false,
				'docState'      => 40,   // aktivní (V pořádku) — jako default schránka z MailRouterProvisioner
			];

			if ($this->isDryRun())
			{
				$this->debug("DRY-RUN: would create mailbox {$mailboxCode} (section {$secNdx})");
				continue;
			}

			try
			{
				$newId = $crud->create('core_mail_mailboxes', $payload);
				$this->idMap()->record(LocalIdMap::ENTITY_MAILBOX, $secNdx, $newId);
				$this->ok("[mailbox] section {$secNdx} → {$newId} ({$mailboxCode})");
			}
			catch (HttpException $e)
			{
				$this->err("Failed mailbox for section {$secNdx}: {$e->getMessage()}");
				if (!$this->isContinueOnError())
					return false;
			}
		}
		return true;
	}

	/** mailbox_id kód deterministicky ze sekce: shipardEmailId, fallback sec-{ndx}. */
	private function mailboxCode(array $section): string
	{
		$eid = trim((string) ($section['shipardEmailId'] ?? ''));
		return $eid !== '' ? $eid : 'sec-' . (int) $section['ndx'];
	}

	/**
	 * mailbox kód pro danou sekci (z cache / DB). Prázdné pro section <= 0 nebo
	 * neexistující sekci → endpoint použije default schránku.
	 */
	private function mailboxCodeForSection(int $secNdx): string
	{
		if ($secNdx <= 0)
			return '';
		if (array_key_exists($secNdx, $this->sectionCodeCache))
			return $this->sectionCodeCache[$secNdx];

		$secRow = $this->db()->query('SELECT * FROM [wkf_base_sections] WHERE [ndx] = %i', $secNdx)->fetch();
		$code = $secRow !== null ? $this->mailboxCode((array) $secRow) : '';
		return $this->sectionCodeCache[$secNdx] = $code;
	}

	// ── Fáze B — zprávy ──────────────────────────────────────────────────

	private function processIssue(array $issue, array &$stats): bool
	{
		$oldNdx = (int) $issue['ndx'];

		if ($this->idMap()->lookup(LocalIdMap::ENTITY_MESSAGE, $oldNdx) !== null)
		{
			$stats['skipped']++;
			$this->debug("[mail] {$oldNdx} skipped (already-imported)");
			return true;
		}

		// vazba na doklad
		[$targetTableId, $targetRow, $linkedDocOldNdx] = $this->resolveDocLink($oldNdx, $issue);
		if ((bool) $this->app()->arg('require-linked-doc') && $targetRow === null)
		{
			$stats['skipped']++;
			$this->debug("[mail] {$oldNdx} skipped (no linked doc)");
			return true;
		}
		$targetRow === null ? $stats['unlinked']++ : $stats['linked']++;

		// odesílatel
		[$senderEmail, $senderName] = $this->resolveSender($issue);
		$senderPerson = null;
		$authorNdx = (int) ($issue['author'] ?? 0);
		if ($authorNdx > 0)
			$senderPerson = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $authorNdx);

		[$bodyPlain, $bodyHtml] = $this->splitBody(
			$this->emptyToNull($issue['body'] ?? null) ?? $this->emptyToNull($issue['text'] ?? null)
		);

		$payload = [
			'mailbox'         => $this->mailboxCodeForSection((int) ($issue['section'] ?? 0)),
			'subject'         => $this->emptyToNull($issue['subject'] ?? null) ?? '(bez předmětu)',
			'sender_email'    => $senderEmail,
			'sender_name'     => $senderName,
			'sender_person'   => $senderPerson,
			'received_at'     => $this->dateTimeToIso($issue['dateIncoming'] ?? null)
								   ?? $this->dateTimeToIso($issue['dateCreate'] ?? null),
			'body_plain'      => $bodyPlain,
			'body_html'       => $bodyHtml,
			'source_type'     => self::SOURCE_TYPE_MAP[(int) ($issue['source'] ?? 0)] ?? 1,
			'primary_type'    => $this->primaryTypeFor($linkedDocOldNdx),
			'target_table_id' => $targetTableId,
			'target_row'      => $targetRow,
			'docState'        => $this->mapDocState((int) ($issue['docState'] ?? 0)),
		];

		if ($this->isDryRun())
		{
			$stats['created']++;
			$this->debug("DRY-RUN: would import message (old ndx={$oldNdx})");
			return true;
		}

		try
		{
			$resp = $this->http()->post('/_mail/import', $payload);
			$newId = (int) ($resp['data']['ndx'] ?? 0);
			if ($newId <= 0)
			{
				$stats['failed']++;
				$this->err("[mail] {$oldNdx} no ndx in response");
				return $this->isContinueOnError();
			}

			$this->idMap()->record(LocalIdMap::ENTITY_MESSAGE, $oldNdx, $newId);
			$stats['created']++;
			$messageId = (string) ($resp['data']['message_id'] ?? '');
			$this->ok("[mail] {$oldNdx} → {$newId} ({$messageId})");

			// přílohy (Fáze 07a) — table_id 303
			if (!(bool) $this->app()->arg('no-attachments'))
				$this->importAttachments($oldNdx, $newId, $stats);

			return true;
		}
		catch (HttpException $e)
		{
			$stats['failed']++;
			$this->err("[mail] {$oldNdx} FAILED: {$e->getMessage()}");
			return $this->isContinueOnError();
		}
	}

	/**
	 * Starý issues.docState → nový docState (core.mail.docStatesIncoming).
	 * Nezávisle na navázání na doklad. Neznámý stav → 10 (Nová).
	 */
	private function mapDocState(int $oldState): int
	{
		return self::ISSUE_STATE_MAP[$oldState] ?? 10;
	}

	/**
	 * Formát těla — mirror ContentRenderer::createCodeText('auto'):
	 * obsahuje-li <html | <span | <div | <p → HTML, jinak plain.
	 *
	 * @return array{0: ?string, 1: ?string}  [body_plain, body_html]
	 */
	private function splitBody(?string $body): array
	{
		if ($body === null)
			return [null, null];
		$isHtml = str_contains($body, '<html')
			|| str_contains($body, '<span')
			|| str_contains($body, '<div')
			|| str_contains($body, '<p');
		return $isHtml ? [null, $body] : [$body, null];   // HTML → body_plain zůstává NULL
	}

	/**
	 * Vazba zpráva → doklad ze dvou zdrojů (sloučeno, dedup):
	 *   1) e10_base_doclinks (e10docs-inbox; doklad=src, zpráva=dst) — primární
	 *   2) issues.tableNdx == 1078 && recNdx > 0 — obecný ukazatel na doklad
	 * Vrací první kandidát, který je naimportovaný (ENTITY_DOC). Doclink má přednost.
	 *
	 * @param array<string, mixed> $issue
	 * @return array{0: ?string, 1: ?int, 2: ?int}  [target_table_id, target_row, pickedOldDocNdx]
	 */
	private function resolveDocLink(int $issueNdx, array $issue): array
	{
		$candidates = [];

		// 1) doclinks e10docs-inbox
		foreach ($this->db()->query(
			'SELECT [srcRecId] FROM [e10_base_doclinks]'
			. ' WHERE [linkId] = %s', 'e10docs-inbox',
			' AND [dstTableId] = %s', 'wkf.core.issues',
			' AND [dstRecId] = %i', $issueNdx,
			' AND [srcTableId] = %s', 'e10doc.core.heads',
			' ORDER BY [ndx]',
		)->fetchAll() as $r)
			$candidates[] = (int) ((array) $r)['srcRecId'];

		// 2) tableNdx/recNdx → doklad (heads, ndx 1078)
		if ((int) ($issue['tableNdx'] ?? 0) === self::DOCS_HEADS_TABLE_NDX
			&& (int) ($issue['recNdx'] ?? 0) > 0)
			$candidates[] = (int) $issue['recNdx'];

		$candidates = array_values(array_unique($candidates));
		if ($candidates === [])
			return [null, null, null];
		if (count($candidates) > 1)
			$this->warn("[mail] issue {$issueNdx}: " . count($candidates)
				. " linked doc candidates, picking first resolvable");

		foreach ($candidates as $oldDocNdx)
		{
			$newId = $this->idMap()->lookup(LocalIdMap::ENTITY_DOC, $oldDocNdx);
			if ($newId !== null)
				return ['docs_core_heads', $newId, $oldDocNdx];
		}
		// kandidát existuje, ale mimo importovaný rozsah → unlinked (známe starý ndx)
		$this->debug("[mail] issue {$issueNdx}: linked doc {$candidates[0]} not imported (out of scope)");
		return [null, null, $candidates[0]];
	}

	/** Navázaný doklad je faktura přijatá (invni) → invoiceReceived; jinak other. */
	private function primaryTypeFor(?int $linkedDocOldNdx): string
	{
		if ($linkedDocOldNdx === null)
			return 'other';
		$r = $this->db()->query('SELECT [docType] FROM [e10doc_core_heads] WHERE [ndx] = %i', $linkedDocOldNdx)->fetch();
		$docType = $r !== null ? (string) ((array) $r)['docType'] : '';
		return $docType === 'invni' ? 'invoiceReceived' : 'other';
	}

	// ── Odesílatel ───────────────────────────────────────────────────────

	/**
	 * Odesílatel: systemInfo (email.from[0] | webForm.from) → e-mail autora
	 * (osoby) → placeholder. Endpoint vyžaduje validní e-mail.
	 *
	 * @return array{0: string, 1: ?string}  [sender_email, sender_name]
	 */
	private function resolveSender(array $issue): array
	{
		// 1) systemInfo JSON: email.from[0] | webForm.from
		$si = $this->emptyToNull($issue['systemInfo'] ?? null);
		if ($si !== null)
		{
			$j = json_decode($si, true);
			if (is_array($j))
			{
				$from = $j['email']['from'][0] ?? $j['webForm']['from'] ?? null;
				if (is_array($from))
				{
					$addr = $this->emptyToNull($from['address'] ?? null);
					$name = $this->emptyToNull($from['name'] ?? null);
					if ($addr !== null && filter_var($addr, FILTER_VALIDATE_EMAIL))
						return [strtolower($addr), $name];
				}
			}
		}

		// 2) e-mail autora (osoby) z e10_base_properties
		$authorNdx = (int) ($issue['author'] ?? 0);
		if ($authorNdx > 0)
		{
			$email = $this->loadPersonEmail($authorNdx);
			if ($email !== null)
				return [strtolower($email), $this->loadPersonName($authorNdx)];
		}

		// 3) placeholder (validní e-mail, projde validací endpointu)
		return ['unknown@imported.invalid', null];
	}

	/**
	 * E-mail osoby z e10_base_properties (property='email', group='contacts').
	 * Stejný zdroj jako IssueEmailForwardEngine. První validní e-mail, nebo null.
	 */
	private function loadPersonEmail(int $personNdx): ?string
	{
		$rows = $this->db()->query(
			'SELECT [valueString] FROM [e10_base_properties]'
			. ' WHERE [tableid] = %s', 'e10.persons.persons',
			' AND [recid] = %i', $personNdx,
			' AND [property] = %s', 'email',
			' AND [group] = %s', 'contacts',
			' ORDER BY [ndx]',
		)->fetchAll();

		foreach ($rows as $r)
		{
			$email = trim((string) ((array) $r)['valueString']);
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
				return $email;
		}
		return null;
	}

	private function loadPersonName(int $personNdx): ?string
	{
		$r = $this->db()->query('SELECT [fullName] FROM [e10_persons_persons] WHERE [ndx] = %i', $personNdx)->fetch();
		return $r !== null ? $this->emptyToNull(((array) $r)['fullName'] ?? null) : null;
	}

	// ── Přílohy ──────────────────────────────────────────────────────────

	private function importAttachments(int $issueNdx, int $newMsgId, array &$stats): void
	{
		$importer = new AttachmentImporter(
			new AttachmentReader($this->db(), __APP_DIR__),
			new AttachmentUploadClient($this->http()),
			$this->logger(),
		);
		$r = $importer->importFor('wkf.core.issues', $issueNdx, self::MAIL_TABLE_ID, $newMsgId);

		$stats['att_uploaded']  += $r['uploaded'];
		$stats['att_duplicate'] += $r['duplicate'];
		$stats['att_missing']   += $r['missing'];
		$stats['att_failed']    += $r['failed'];

		if (array_sum($r) > 0)
			$this->debug("[mail] issue {$issueNdx}: attachments "
				. "uploaded={$r['uploaded']} dup={$r['duplicate']} missing={$r['missing']} failed={$r['failed']}");
	}

	// ── Source query + helpers ───────────────────────────────────────────

	/**
	 * Inbox zprávy (issueType=1), bez smazaných, volitelně okno na dateIncoming.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchIssues(): array
	{
		$q = [
			'SELECT i.* FROM [wkf_core_issues] i'
			. ' WHERE i.[issueType] = %i', self::ISSUE_TYPE_INBOX,
			' AND i.[docState] != %i', self::ISSUE_STATE_TRASH,
		];
		$this->appendDateWindow($q, 'i');
		$q[] = ' ORDER BY i.[ndx]';

		$out = [];
		foreach ($this->db()->query($q)->fetchAll() as $r)
			$out[] = (array) $r;
		return $out;
	}

	/** Připojí --from/--to filtr na {alias}.dateIncoming do dibi query pole. */
	private function appendDateWindow(array &$q, string $alias): void
	{
		$from = $this->dateArg('from');
		$to   = $this->dateArg('to');
		if ($from !== null)
		{
			$q[] = " AND {$alias}.[dateIncoming] >= %d";
			$q[] = $from;
		}
		if ($to !== null)
		{
			$q[] = " AND {$alias}.[dateIncoming] <= %d";
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

	/** DateTime/string → ISO8601; 0000-…/prázdné → null. */
	private function dateTimeToIso(mixed $value): ?string
	{
		if ($value === null)
			return null;
		if ($value instanceof \DateTimeInterface)
			return $value->format('Y-m-d\TH:i:s');
		$s = trim((string) $value);
		if ($s === '' || str_starts_with($s, '0000-00-00'))
			return null;
		$ts = strtotime($s);
		return $ts !== false ? date('Y-m-d\TH:i:s', $ts) : null;
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
		$this->info("");
		$this->info(sprintf(
			"Done mail: created=%d, skipped=%d, failed=%d | linked=%d, unlinked=%d",
			$stats['created'], $stats['skipped'], $stats['failed'],
			$stats['linked'], $stats['unlinked'],
		));
		$this->info(sprintf(
			"  attachments: uploaded=%d, duplicate=%d, missing=%d, failed=%d",
			$stats['att_uploaded'], $stats['att_duplicate'], $stats['att_missing'], $stats['att_failed'],
		));
	}
}
