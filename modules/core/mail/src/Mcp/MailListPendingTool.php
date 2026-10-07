<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Module\Core\Mail\IncomingMessageTitle;

/**
 * Čtecí MCP nástroj: došlá pošta čekající na pozornost (`docState != 40`,
 * nezpracovaná). U každé zprávy stav AI analýzy (`none/pending/success/failed`)
 * z „current" běhu a flag otevřeného dokumentového návrhu
 * (`canonical_json` bez verdiktu). `only_actionable` zúží na zprávy
 * s otevřeným návrhem.
 *
 * `full_name` položky = lidský titulek zprávy (předmět; u generického /
 * prázdného předmětu a ručních zpráv `ai_title` — pravidlo D3
 * `IncomingMessageTitle`), `partner` = protistrana dokumentu (Osoba zprávy,
 * jinak snapshot jména z canonicalu) odděleně od `sender`
 * (tasks/mail-message-title-partner.md D4/D7).
 */
final class MailListPendingTool implements McpTool
{
	private const int DEFAULT_LIMIT = 20;
	private const int MAX_LIMIT = 50;

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'mail_list_pending';
	}

	public function description(): string
	{
		return 'Vrátí došlou poštu, která ještě čeká na pozornost (není '
			. 'zpracovaná). U každé zprávy uvádí titulek (u skenů a nahraných '
			. 'souborů odvozený z obsahu, ne generický předmět), partnera '
			. 'dokumentu (dodavatele / protistranu — ne odesílatele e-mailu), '
			. 'odesílatele, stav AI analýzy a zda má otevřený dokumentový návrh '
			. 'čekající na akci (potvrzení/zamítnutí). U zprávy bez dokladu '
			. '`attention`: `action` = chce lidskou akci nebo rozhodnutí '
			. '(expirace, výzva k platbě, žádost; `action_note` říká co, '
			. '`action_due` dokdy), `info` = jen informuje, `promo` = obchodní '
			. 'sdělení, null = neurčeno. '
			. '`only_actionable=true` zúží na zprávy s otevřeným návrhem — '
			. 'typicky to, co má agent vyřešit.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'only_actionable' => ['type' => 'boolean', 'default' => false, 'description' => 'Jen zprávy s otevřeným dokumentovým návrhem čekajícím na akci'],
				'limit'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
				'offset'          => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
			],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$limit          = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
		$offset         = max(0, (int) ($arguments['offset'] ?? 0));
		$onlyActionable = !empty($arguments['only_actionable']);

		// „Current" analýza = MAX(analyzed_at) per message (žádné N+1);
		// otevřený návrh = poslední úspěšný běh s canonical_json bez verdiktu.
		// only_actionable filtruje nad derived tabulkou, ať LIMIT/OFFSET
		// (a has_more) sedí.
		$inner = 'SELECT `m`.`id`, `m`.`subject`, `m`.`ai_title`, `m`.`source_type`,'
			. ' `m`.`attention`, `m`.`action_note`, `m`.`action_due`,'
			. ' `m`.`sender_name`, `m`.`sender_email`,'
			. ' `m`.`sender_person`, `m`.`partner_person`, `m`.`partner_name`,'
			. ' (SELECT `p`.`full_name` FROM `base_persons_persons` `p` WHERE `p`.`id` = `m`.`partner_person`) AS `partner_full_name`,'
			. ' `m`.`received_at`, `m`.`mailbox`, `mb`.`name` AS `mailbox_name`,'
			. ' `m`.`docState`,'
			. ' (SELECT `a`.`status` FROM `core_mail_message_analyses` `a`'
			. '    WHERE `a`.`message` = `m`.`id` ORDER BY `a`.`analyzed_at` DESC LIMIT 1) AS `analysis_status_raw`,'
			. ' (SELECT `a`.`canonical_json` IS NOT NULL AND `a`.`resolution` IS NULL'
			. '    FROM `core_mail_message_analyses` `a`'
			. '    WHERE `a`.`message` = `m`.`id` AND `a`.`status` = 2'
			. '    ORDER BY `a`.`analyzed_at` DESC, `a`.`id` DESC LIMIT 1) AS `has_open_proposal`'
			. ' FROM `core_mail_incoming_messages` `m`'
			. ' LEFT JOIN `core_mail_mailboxes` `mb` ON `mb`.`id` = `m`.`mailbox`'
			. ' WHERE `m`.`docState` != 40';

		$sql = "SELECT * FROM ({$inner}) `t`"
			. ($onlyActionable ? ' WHERE `t`.`has_open_proposal` = 1' : '')
			. ' ORDER BY `t`.`received_at` DESC'
			. ' LIMIT %i OFFSET %i';

		$rows = $ctx->db->fetchAll($sql, $limit + 1, $offset);

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		$stateCfg = DocStateConfig::fromCfgItem($ctx->config?->cfgItem('core.mail.docStatesIncoming'));
		$patterns = IncomingMessageTitle::patternsFrom($ctx->config);

		$actionableMsgs = 0;
		$items = array_map(function (array $r) use ($stateCfg, $patterns, &$actionableMsgs): array {
			$docState = (int) ($r['docState'] ?? 0);
			$hasProposal = (bool) ($r['has_open_proposal'] ?? false);
			if ($hasProposal) {
				$actionableMsgs++;
			}

			$partnerName = trim((string) ($r['partner_full_name'] ?? ''));
			if ($partnerName === '') {
				$partnerName = trim((string) ($r['partner_name'] ?? ''));
			}

			return [
				'ref'               => ['type' => 'mail_message', 'id' => (int) $r['id']],
				'full_name'         => IncomingMessageTitle::display(
					(string) ($r['subject'] ?? ''),
					isset($r['ai_title']) ? (string) $r['ai_title'] : null,
					(int) ($r['source_type'] ?? 0),
					$patterns,
				),
				'subject'           => $r['subject'] ?: null,
				'ai_title'          => !empty($r['ai_title']) ? (string) $r['ai_title'] : null,
				'attention'         => !empty($r['attention']) ? (string) $r['attention'] : null,
				'action_note'       => !empty($r['action_note']) ? (string) $r['action_note'] : null,
				'action_due'        => self::isoDate($r['action_due'] ?? null),
				'partner'           => $partnerName !== '' || !empty($r['partner_person'])
					? [
						'name'   => $partnerName !== '' ? $partnerName : null,
						'person' => !empty($r['partner_person']) ? ['id' => (int) $r['partner_person']] : null,
					]
					: null,
				'sender'            => [
					'name'   => $r['sender_name'] ?: null,
					'email'  => $r['sender_email'] ?: null,
					'person' => $r['sender_person'] ? ['id' => (int) $r['sender_person']] : null,
				],
				'received_at'       => $r['received_at'] ?: null,
				'mailbox'           => $r['mailbox_name'] ?: null,
				'state_label'       => $stateCfg->getState($docState)['stateName'] ?? (string) $docState,
				'analysis_status'   => $this->mapAnalysisStatus($r['analysis_status_raw'] ?? null),
				'has_open_proposal' => $hasProposal,
			];
		}, $rows);

		$shown = count($items);

		return [
			'summary' => $shown === 0
				? 'Žádná čekající pošta.'
				: "{$shown} čekajících zpráv, {$actionableMsgs} s otevřeným návrhem.",
			'items'      => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => $offset,
				'returned' => $shown,
				'has_more' => $hasMore,
			],
		];
	}

	/** NULL (žádný běh) → none; 1 → pending; 2 → success; 3 → failed. */
	/** DB date (Dibi DateTime nebo string) → `Y-m-d`; prázdné → null. */
	private static function isoDate(mixed $value): ?string
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}
		return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
	}

	private function mapAnalysisStatus(mixed $raw): string
	{
		return match ((int) $raw) {
			1       => 'pending',
			2       => 'success',
			3       => 'failed',
			default => 'none',
		};
	}
}
