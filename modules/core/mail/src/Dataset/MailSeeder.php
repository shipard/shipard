<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Dataset;

use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

/**
 * `mail/*.jsonc` (`shpd.mail.incomingMessage.v1`) → zpráva + přílohy +
 * snapshot analýz (snapshot mode, D2 — žádné volání AI).
 *
 * Zpráva jde přes `IncomingMessageDocument` (validate + beforeSave;
 * `message_id` se zachová, `analysis_state` je explicitní), přílohy přes
 * `AttachmentService` s remapou `att:<pořadí>` → `att:<nové id>`
 * v canonicalu analýz (R4), lineage `target` → `target_table_id/target_row`
 * na zprávě a `source_message` na dokladu / dokumentu spisovny. Jedna
 * zpráva = jedna transakce.
 */
final class MailSeeder implements SectionSeeder
{
    private const TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const TABLE_ID = 303;

    /** @var array<string, ?int> */
    private array $mailboxCache = [];

    /** @var array<string, ?int> */
    private array $profileCache = [];

    public function __construct(
        private readonly PartyResolver $partyResolver,
    ) {}

    public function section(): string
    {
        return 'mail';
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        foreach ($ctx->reader->listFiles('mail') as $rel) {
            try {
                $m = $ctx->reader->readJsonc($rel);
                $raw = $ctx->reader->readJsoncObjects($rel);
            } catch (DatasetException $e) {
                $report->failed('mail', $e->getMessage());
                continue;
            }
            $label = (string) ($m['messageId'] ?? $rel);

            $mailboxId = $this->mailboxId($ctx, (string) ($m['mailbox'] ?? ''));
            if ($mailboxId === null) {
                $report->failed('mail', "{$rel}: mailbox '{$m['mailbox']}' na DS neexistuje (chybí v setup/mailboxes?)");
                continue;
            }
            if ($ctx->merge && $this->messageExists($ctx, $label)) {
                $report->failed('mail', "{$rel}: zpráva '{$label}' už v DS existuje");
                continue;
            }

            $ctx->db->begin();
            try {
                $id = $this->insertMessage($ctx, $m, $mailboxId);
                $attachmentIds = $this->attachments($ctx, $report, $rel, $m, $id);
                $this->analyses($ctx, $report, $label, $m, $raw, $id, $attachmentIds);
                $this->linkTarget($ctx, $report, $label, $m, $id);
                $ctx->db->commit();
                $report->ok('mail');
            } catch (\Throwable $e) {
                $ctx->db->rollback();
                $report->failed('mail', "{$rel}: {$e->getMessage()}");
            }
        }
    }

    // ── zpráva ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $m
     */
    private function insertMessage(SeedContext $ctx, array $m, int $mailboxId): int
    {
        $docState = (int) ($m['docState'] ?? 10);
        $data = [
            'message_id'          => (string) $m['messageId'],
            'mailbox'             => $mailboxId,
            'subject'             => (string) ($m['subject'] ?? ''),
            'sender_email'        => (string) ($m['senderEmail'] ?? ''),
            'sender_name'         => $m['senderName'] ?? null,
            'sender_person'       => $this->personRef(is_array($m['senderPerson'] ?? null) ? $m['senderPerson'] : null),
            'partner_person'      => $this->personRef(is_array($m['partnerPerson'] ?? null) ? $m['partnerPerson'] : null),
            'partner_name'        => $m['partnerName'] ?? null,
            'received_at'         => SeedContext::dbDateTime((string) $m['receivedAt']),
            'external_message_id' => $m['externalMessageId'] ?? null,
            'in_reply_to'         => $m['inReplyTo'] ?? null,
            'reply_references'    => $m['replyReferences'] ?? null,
            'is_bulk'             => !empty($m['isBulk']) ? 1 : 0,
            'body_plain'          => $m['bodyPlain'] ?? null,
            'body_html'           => $m['bodyHtml'] ?? null,
            'source_type'         => (int) ($m['sourceType'] ?? 1),
            'analysis_state'      => (int) ($m['analysisState'] ?? 0),
            'needs_reanalysis'    => !empty($m['needsReanalysis']) ? 1 : 0,
            'profile_override'    => $this->profileId($ctx, $m['profileOverride'] ?? null),
            'ai_title'            => $m['aiTitle'] ?? null,
            'docState'            => $docState,
            'docStateMain'        => $ctx->mainState('core.mail.docStatesIncoming', $docState, [10 => 1, 20 => 2, 40 => 3, 80 => 4, 90 => 5]),
            'created_by'          => null,
        ];
        if (isset($m['primaryType'])) {
            $data['primary_type'] = (string) $m['primaryType'];
        }
        if (isset($m['primaryTypeSource'])) {
            $data['primary_type_source'] = (string) $m['primaryTypeSource'];
        }
        // Pozornost u zprávy bez dokladu (#105): jen co obálka nese, chybějící
        // klíč nechá DB default NULL. Hodnotu `attention` hlídá enum schématu
        // v preflightu — Document enumString proti cfgItem nekontroluje.
        if (isset($m['attention'])) {
            $data['attention'] = (string) $m['attention'];
        }
        if (isset($m['actionNote'])) {
            $data['action_note'] = (string) $m['actionNote'];
        }
        if (isset($m['actionDue'])) {
            $data['action_due'] = (string) $m['actionDue'];
        }
        if (array_key_exists('aiAnalysisEnabled', $m) && is_bool($m['aiAnalysisEnabled'])) {
            $data['ai_analysis_enabled'] = $m['aiAnalysisEnabled'] ? 1 : 0;
        }
        $created = SeedContext::dbDateTime(is_string($m['created'] ?? null) ? $m['created'] : null);
        if ($created !== null) {
            $data['created'] = $created;
        }

        $doc = $ctx->registry->getDocument(self::TABLE, $data);
        $doc->setDb($ctx->db);
        $doc->setConfig($ctx->config);
        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0] ?? null;
            throw new DatasetException($first !== null ? "validace {$first->column}: {$first->message}" : 'validace selhala');
        }
        $doc->beforeSave($data);

        $ctx->db->insert(self::TABLE, $data)->execute();
        return (int) $ctx->db->getInsertId();
    }

    // ── přílohy ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $m
     * @return array<int, int> pořadí v attachments[] (1-based) → id v core_attachments_files
     */
    private function attachments(SeedContext $ctx, SeedReport $report, string $rel, array $m, int $messageId): array
    {
        $dir = SeedContext::sidecarDir($rel);
        $map = [];
        $n = 0;
        foreach ((array) ($m['attachments'] ?? []) as $att) {
            $n++;
            if (!is_array($att) || !is_string($att['file'] ?? null)) {
                continue;
            }
            $relFile = $dir . '/' . $att['file'];
            if (!$ctx->reader->fileExists($relFile)) {
                $report->warning("mail {$rel}: příloha '{$att['file']}' v sadě chybí — vynechána");
                continue;
            }
            $tmp = $ctx->tempCopy($ctx->reader->resolvePath($relFile));
            $result = $ctx->attachments->upload(self::TABLE_ID, $messageId, (string) ($att['name'] ?? $att['file']), $tmp, null);
            if (!($result['success'] ?? false)) {
                throw new DatasetException("nahrání přílohy '{$att['file']}' selhalo: " . (string) ($result['error'] ?? 'unknown'));
            }
            $attId = (int) ($result['data']['id'] ?? 0);
            $map[$n] = $attId;
            if (!empty($att['isRawSource'])) {
                $ctx->db->query('UPDATE %n SET [raw_source_attachment] = %i WHERE [id] = %i', self::TABLE, $attId, $messageId);
            }
        }
        return $map;
    }

    // ── analýzy ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $m
     * @param array<int, int>      $attachmentIds
     */
    private function analyses(SeedContext $ctx, SeedReport $report, string $label, array $m, \stdClass $raw, int $messageId, array $attachmentIds): void
    {
        $rawAnalyses = is_array($raw->analyses ?? null) ? $raw->analyses : [];
        foreach ((array) ($m['analyses'] ?? []) as $i => $a) {
            if (!is_array($a)) {
                continue;
            }
            $rawA = $rawAnalyses[$i] ?? null;
            $analysisJson = $rawA instanceof \stdClass && ($rawA->analysisJson ?? null) instanceof \stdClass ? $rawA->analysisJson : null;
            $canonical = $rawA instanceof \stdClass && ($rawA->canonicalJson ?? null) instanceof \stdClass ? $rawA->canonicalJson : null;
            if ($canonical !== null) {
                $canonical = self::remapAttachmentRefs($canonical, $attachmentIds, $missing);
                if ($missing !== []) {
                    $report->warning("mail {$label}: analýza odkazuje na přílohy " . implode(', ', $missing) . ', které se nepodařilo nahrát');
                }
            }
            $analyzedAt = SeedContext::dbDateTime((string) $a['analyzedAt']);
            $row = [
                'message'         => $messageId,
                'profile'         => $this->profileId($ctx, $a['profile'] ?? null),
                'backend'         => null,
                'analyzed_at'     => $analyzedAt,
                'status'          => (int) ($a['status'] ?? 2),
                'model_name'      => (string) ($a['modelName'] ?? 'unknown'),
                'model_version'   => $a['modelVersion'] ?? null,
                'prompt_version'  => (string) ($a['promptVersion'] ?? 'unknown'),
                'analysis_json'   => $analysisJson !== null ? self::encode($analysisJson) : null,
                'canonical_json'  => $canonical !== null ? self::encode($canonical) : null,
                'confidence'      => $a['confidence'] ?? null,
                'proposed_type'   => $a['proposedType'] ?? null,
                'content_tag'     => $a['contentTag'] ?? null,
                'error_message'   => $a['errorMessage'] ?? null,
                'resolution'      => $a['resolution'] ?? null,
                'rejected_reason' => $a['rejectedReason'] ?? null,
                'resolved_at'     => SeedContext::dbDateTime(is_string($a['resolvedAt'] ?? null) ? $a['resolvedAt'] : null),
                'resolved_by'     => null,
                'tokens_input'    => $a['tokensInput'] ?? null,
                'tokens_output'   => $a['tokensOutput'] ?? null,
                'duration_ms'     => $a['durationMs'] ?? null,
                'cost_usd'        => $a['costUsd'] ?? null,
                'created'         => SeedContext::dbDateTime(is_string($a['created'] ?? null) ? $a['created'] : null) ?? $analyzedAt,
                'created_by'      => null,
            ];
            $ctx->db->insert(self::ANALYSES_TABLE, $row)->execute();
        }
    }

    /**
     * `att:<pořadí>` → `att:<id>`. Neznámé pořadí zůstane a nahlásí se.
     *
     * @param array<int, int> $ids
     * @param list<string>|null $missing
     */
    public static function remapAttachmentRefs(mixed $node, array $ids, ?array &$missing = null): mixed
    {
        $missing ??= [];
        if (is_string($node)) {
            if (preg_match('/^att:([0-9]+)$/', $node, $mm) === 1) {
                $n = (int) $mm[1];
                if (isset($ids[$n])) {
                    return 'att:' . $ids[$n];
                }
                $missing[] = $node;
            }
            return $node;
        }
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = self::remapAttachmentRefs($v, $ids, $missing);
            }
            return $node;
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $k => $v) {
                $node->{$k} = self::remapAttachmentRefs($v, $ids, $missing);
            }
            return $node;
        }
        return $node;
    }

    // ── lineage ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $m
     */
    private function linkTarget(SeedContext $ctx, SeedReport $report, string $label, array $m, int $messageId): void
    {
        $target = $m['target'] ?? null;
        if (!is_array($target)) {
            return;
        }
        $table = (string) ($target['table'] ?? '');
        $rowId = null;
        if ($table === 'docs_core_heads' && is_string($target['docNumber'] ?? null)) {
            $row = $ctx->db->fetch(
                'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] = %s AND [docState] <> 90 ORDER BY [id] LIMIT 1',
                $target['docNumber'],
            );
            $rowId = $row !== null ? (int) $row['id'] : null;
        } elseif ($table === 'base_registry_documents' && is_string($target['title'] ?? null)) {
            $created = SeedContext::dbDateTime(is_string($target['created'] ?? null) ? $target['created'] : null);
            $row = $created !== null
                ? $ctx->db->fetch(
                    'SELECT [id] FROM [base_registry_documents] WHERE [title] = %s AND [created] = %s AND [docState] <> 90 ORDER BY [id] LIMIT 1',
                    $target['title'], $created,
                )
                : $ctx->db->fetch(
                    'SELECT [id] FROM [base_registry_documents] WHERE [title] = %s AND [docState] <> 90 ORDER BY [id] LIMIT 1',
                    $target['title'],
                );
            $rowId = $row !== null ? (int) $row['id'] : null;
        }
        if ($rowId === null) {
            $report->warning("mail {$label}: cíl vazby ({$table} " . json_encode(array_diff_key($target, ['table' => 1]), JSON_UNESCAPED_UNICODE) . ') v DS nenalezen — vazba nenastavena');
            return;
        }
        $ctx->db->query(
            'UPDATE %n SET [target_table_id] = %s, [target_row] = %i WHERE [id] = %i',
            self::TABLE, $table, $rowId, $messageId,
        );
        $ctx->db->query('UPDATE %n SET [source_message] = %i WHERE [id] = %i', $table, $messageId, $rowId);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function mailboxId(SeedContext $ctx, string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        if (!array_key_exists($code, $this->mailboxCache)) {
            $row = $ctx->db->fetch('SELECT [id] FROM [core_mail_mailboxes] WHERE [mailbox_id] = %s AND [docState] <> 90 LIMIT 1', $code);
            $this->mailboxCache[$code] = $row !== null ? (int) $row['id'] : null;
        }
        return $this->mailboxCache[$code];
    }

    private function profileId(SeedContext $ctx, mixed $code): ?int
    {
        if (!is_string($code) || $code === '') {
            return null;
        }
        if (!array_key_exists($code, $this->profileCache)) {
            $row = $ctx->db->fetch('SELECT [id] FROM [core_mail_ai_profiles] WHERE [profile_id] = %s LIMIT 1', $code);
            $this->profileCache[$code] = $row !== null ? (int) $row['id'] : null;
        }
        return $this->profileCache[$code];
    }

    private function messageExists(SeedContext $ctx, string $messageId): bool
    {
        return $ctx->db->fetch('SELECT [id] FROM %n WHERE [message_id] = %s LIMIT 1', self::TABLE, $messageId) !== null;
    }

    /**
     * @param array<string, mixed>|null $person
     */
    /**
     * Osoba z exportovaného odkazu (sender_person / partner_person) — jen
     * deterministická shoda identifikátorem, nikdy jménem, nikdy create.
     */
    private function personRef(?array $person): ?int
    {
        if ($person === null) {
            return null;
        }
        $ids = array_filter([
            'companyId' => $person['companyId'] ?? null,
            'vatId'     => $person['vatId'] ?? null,
            'taxId'     => $person['taxId'] ?? null,
        ], static fn($v) => is_string($v) && $v !== '');
        if ($ids === []) {
            return null;
        }
        try {
            $r = $this->partyResolver->resolve($ids, identifiersOnly: true);
        } catch (\Throwable) {
            return null;
        }
        return $r->status === ResolveStatus::Matched ? $r->matchedId : null;
    }

    private static function encode(\stdClass $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
