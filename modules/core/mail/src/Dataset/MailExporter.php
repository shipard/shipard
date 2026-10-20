<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Dataset;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\AttachmentNames;
use Shipard\Module\Core\Exchange\Dataset\ExportedFile;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * Došlá zpráva + analýzy + přílohy → `shpd.mail.incomingMessage.v1`
 * (schéma `modules/core/mail/schemas/`).
 *
 * Bez interních id: mailbox a profil kódem, odesílatel identifikátory
 * osoby, `target` přes číslo dokladu / titulek dokumentu spisovny.
 * `analysisJson` / `canonicalJson` se přenášejí verbatim (dekódované
 * jako objekty, bez prune), jen `att:<id>` odkazy v canonicalu se
 * přepisují na 1-based pořadí přílohy v `attachments[]` (R4).
 */
final class MailExporter implements RecordExporter
{
    public const FORMAT = 'shpd.mail.incomingMessage';

    private const MAIL_TABLE_ID = 303;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $dsPath,
    ) {}

    public function section(): string
    {
        return 'mail';
    }

    public function exportAll(): array
    {
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [m.docState] <> 90 ' . $this->orderSql(),
        ));
    }

    public function exportByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [m.id] IN %in AND [m.docState] <> 90 ' . $this->orderSql(),
            $ids,
        ));
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function selectSql(): string
    {
        return 'SELECT [m.*], [mb.mailbox_id] AS [mailbox_code], [po.profile_id] AS [profile_override_code]'
            . ' FROM [core_mail_incoming_messages] AS [m]'
            . ' LEFT JOIN [core_mail_mailboxes] AS [mb] ON [mb.id] = [m.mailbox]'
            . ' LEFT JOIN [core_mail_ai_profiles] AS [po] ON [po.id] = [m.profile_override]';
    }

    private function orderSql(): string
    {
        return 'ORDER BY [m.received_at], [m.message_id], [m.id]';
    }

    /**
     * @param iterable<\Dibi\Row|array<string, mixed>> $rows
     * @return list<ExportedRecord>
     */
    private function exportRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->exportMessage(is_array($row) ? $row : $row->toArray());
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $m
     */
    public function exportMessage(array $m): ExportedRecord
    {
        $id = (int) $m['id'];
        $messageId = V::str($m['message_id'] ?? null) ?? "MSG-{$id}";
        $rawId = V::int($m['raw_source_attachment'] ?? null);

        [$attachments, $files, $ordinals] = $this->attachments($id, $rawId);
        $analyses = $this->analyses($id, $ordinals, $messageId);

        $mailbox = V::str($m['mailbox_code'] ?? null);
        if ($mailbox === null) {
            $this->warnings[] = "mail {$messageId}: mailbox #{$m['mailbox']} neexistuje — použit 'default'";
            $mailbox = 'default';
        }

        $data = V::prune([
            'format'            => self::FORMAT,
            'formatVersion'     => '1.0',
            'messageId'         => $messageId,
            'mailbox'           => $mailbox,
            'primaryType'       => V::str($m['primary_type'] ?? null),
            'primaryTypeSource' => V::str($m['primary_type_source'] ?? null),
            'subject'           => (string) ($m['subject'] ?? ''),
            'senderEmail'       => (string) ($m['sender_email'] ?? ''),
            'senderName'        => V::str($m['sender_name'] ?? null),
            'senderPerson'      => $this->personRef(V::int($m['sender_person'] ?? null)),
            'partnerPerson'     => $this->personRef(V::int($m['partner_person'] ?? null)),
            'partnerName'       => V::str($m['partner_name'] ?? null),
            'receivedAt'        => V::dateTime($m['received_at'] ?? null),
            'externalMessageId' => V::str($m['external_message_id'] ?? null),
            'inReplyTo'         => V::str($m['in_reply_to'] ?? null),
            'replyReferences'   => V::str($m['reply_references'] ?? null),
            'isBulk'            => ((int) ($m['is_bulk'] ?? 0)) === 1 ? true : null,
            'bodyPlain'         => self::text($m['body_plain'] ?? null),
            'bodyHtml'          => self::text($m['body_html'] ?? null),
            'sourceType'        => V::int($m['source_type'] ?? null),
            'docState'          => (int) ($m['docState'] ?? 10),
            'analysisState'     => (int) ($m['analysis_state'] ?? 0),
            'aiAnalysisEnabled' => V::bool($m['ai_analysis_enabled'] ?? null),
            'needsReanalysis'   => ((int) ($m['needs_reanalysis'] ?? 0)) === 1 ? true : null,
            'profileOverride'   => V::str($m['profile_override_code'] ?? null),
            'aiTitle'           => V::str($m['ai_title'] ?? null),
            'attention'         => V::str($m['attention'] ?? null),
            'actionNote'        => V::str($m['action_note'] ?? null),
            'actionDue'         => V::date($m['action_due'] ?? null),
            'created'           => V::dateTime($m['created'] ?? null),
            'target'            => $this->target($m, $messageId),
            'attachments'       => $attachments,
        ]);
        // prune nesmí sáhnout do analysisJson/canonicalJson — přidávají se až po něm.
        if ($analyses !== []) {
            $data['analyses'] = $analyses;
        }
        // Prázdný subject / sender jsou legitimní hodnoty, prune je nechá (řetězce).
        $data['subject'] ??= '';
        $data['senderEmail'] ??= '';

        return new ExportedRecord($id, $messageId, $data, $files);
    }

    // ── části zprávy ───────────────────────────────────────────────────────

    /**
     * Odkaz na Osobu identifikátory (jméno + IČO/DIČ/VAT ID) — sender_person
     * i partner_person; seeder ho na cílovém DS rozřeší jen shodou identifikátorem.
     *
     * @return array<string, mixed>|null
     */
    private function personRef(?int $personId): ?array
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }
        $p = $this->db->fetch(
            'SELECT [full_name], [company_id], [tax_id], [vat_id] FROM [base_persons_persons] WHERE [id] = %i',
            $personId,
        );
        if ($p === null) {
            return null;
        }
        $p = is_array($p) ? $p : $p->toArray();
        return [
            'name'      => V::str($p['full_name'] ?? null),
            'companyId' => V::str($p['company_id'] ?? null),
            'taxId'     => V::str($p['tax_id'] ?? null),
            'vatId'     => V::str($p['vat_id'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>|null
     */
    private function target(array $m, string $messageId): ?array
    {
        $table = V::str($m['target_table_id'] ?? null);
        $row = V::int($m['target_row'] ?? null);
        if ($table === null || $row === null) {
            return null;
        }
        if ($table === 'docs_core_heads') {
            $doc = $this->db->fetch('SELECT [doc_number] FROM [docs_core_heads] WHERE [id] = %i', $row);
            $number = $doc === null ? null : V::str($doc['doc_number']);
            if ($number === null) {
                $this->warnings[] = "mail {$messageId}: cílový doklad #{$row} " . ($doc === null ? 'neexistuje' : 'nemá číslo') . ' — vazba se nepřenese';
                return null;
            }
            return ['table' => $table, 'docNumber' => $number];
        }
        if ($table === 'base_registry_documents') {
            $doc = $this->db->fetch('SELECT [title], [created] FROM [base_registry_documents] WHERE [id] = %i', $row);
            if ($doc === null) {
                $this->warnings[] = "mail {$messageId}: cílový dokument spisovny #{$row} neexistuje — vazba se nepřenese";
                return null;
            }
            return ['table' => $table, 'title' => V::str($doc['title']), 'created' => V::dateTime($doc['created'])];
        }
        $this->warnings[] = "mail {$messageId}: neznámý cíl '{$table}' — vazba se nepřenese";
        return null;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<ExportedFile>, 2: array<int, int>}
     */
    private function attachments(int $messageId, ?int $rawId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [core_attachments_files]
             WHERE [table_id] = %i AND [record_id] = %i AND [is_deleted] = 0
             ORDER BY [att_order], [name], [id]',
            self::MAIL_TABLE_ID,
            $messageId,
        );

        $meta = [];
        $files = [];
        $ordinals = [];
        $used = [];
        $n = 0;
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $attId = (int) $r['id'];
            $n++;
            $ordinals[$attId] = $n;
            $name = AttachmentNames::unique(V::str($r['name'] ?? null) ?? V::str($r['file_name'] ?? null) ?? 'file', $used);
            $used[$name] = true;
            $meta[] = V::prune([
                'file'        => $name,
                'name'        => V::str($r['name'] ?? null),
                'mimeType'    => V::str($r['mime_type'] ?? null),
                'size'        => V::int($r['file_size'] ?? null),
                'sha256'      => V::str($r['checksum'] ?? null),
                'isRawSource' => $rawId !== null && $attId === $rawId ? true : null,
                'order'       => V::int($r['att_order'] ?? null),
            ]);
            $files[] = new ExportedFile(
                sourcePath: $this->dsPath . '/att/' . $r['file_path'] . '/' . $r['file_name'],
                name: $name,
                attachmentId: $attId,
            );
        }
        return [$meta, $files, $ordinals];
    }

    /**
     * @param array<int, int> $ordinals id přílohy → pořadí v attachments[]
     * @return list<array<string, mixed>>
     */
    private function analyses(int $messageId, array $ordinals, string $label): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [a.*], [p.profile_id] AS [profile_code]
             FROM [core_mail_message_analyses] AS [a]
             LEFT JOIN [core_mail_ai_profiles] AS [p] ON [p.id] = [a.profile]
             WHERE [a.message] = %i ORDER BY [a.analyzed_at], [a.id]',
            $messageId,
        );

        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $analysis = V::prune([
                'analyzedAt'     => V::dateTime($r['analyzed_at'] ?? null),
                'status'         => (int) ($r['status'] ?? 1),
                'modelName'      => V::str($r['model_name'] ?? null) ?? 'unknown',
                'modelVersion'   => V::str($r['model_version'] ?? null),
                'promptVersion'  => V::str($r['prompt_version'] ?? null) ?? 'unknown',
                'profile'        => V::str($r['profile_code'] ?? null),
                'confidence'     => V::float($r['confidence'] ?? null),
                'proposedType'   => V::str($r['proposed_type'] ?? null),
                'contentTag'     => V::str($r['content_tag'] ?? null),
                'errorMessage'   => V::str($r['error_message'] ?? null),
                'resolution'     => V::int($r['resolution'] ?? null),
                'rejectedReason' => V::str($r['rejected_reason'] ?? null),
                'resolvedAt'     => V::dateTime($r['resolved_at'] ?? null),
                'tokensInput'    => V::int($r['tokens_input'] ?? null),
                'tokensOutput'   => V::int($r['tokens_output'] ?? null),
                'durationMs'     => V::int($r['duration_ms'] ?? null),
                'costUsd'        => V::float($r['cost_usd'] ?? null),
                'created'        => V::dateTime($r['created'] ?? null),
            ]);

            $analysisJson = self::decodeObject($r['analysis_json'] ?? null);
            if ($analysisJson !== null) {
                $analysis['analysisJson'] = $analysisJson;
            } elseif (V::str($r['analysis_json'] ?? null) !== null) {
                $this->warnings[] = "mail {$label}: analysis_json běhu #{$r['id']} není platný JSON objekt — vynecháno";
            }
            $canonical = self::decodeObject($r['canonical_json'] ?? null);
            if ($canonical !== null) {
                $analysis['canonicalJson'] = $this->remapAttachmentRefs($canonical, $ordinals, $label);
            } elseif (V::str($r['canonical_json'] ?? null) !== null) {
                $this->warnings[] = "mail {$label}: canonical_json běhu #{$r['id']} není platný JSON objekt — vynecháno";
            }

            // Pořadí klíčů: metadata, pak velké bloby na konci.
            $out[] = $analysis;
        }
        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * JSON blob → stdClass strom (objekty zůstanou objekty i prázdné — `{}`
     * se nesmí změnit na `[]`, canonical schéma rozlišuje).
     */
    private static function decodeObject(mixed $value): ?\stdClass
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, false);
        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * `att:<id>` → `att:<pořadí>`; neznámé id zůstane a vyvolá warning.
     *
     * @param array<int, int> $ordinals
     */
    private function remapAttachmentRefs(mixed $node, array $ordinals, string $label): mixed
    {
        if (is_string($node)) {
            if (preg_match('/^att:([0-9]+)$/', $node, $mm) === 1) {
                $attId = (int) $mm[1];
                if (isset($ordinals[$attId])) {
                    return 'att:' . $ordinals[$attId];
                }
                $this->warnings[] = "mail {$label}: canonical odkazuje na přílohu #{$attId}, která u zprávy není — odkaz ponechán";
            }
            return $node;
        }
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = $this->remapAttachmentRefs($v, $ordinals, $label);
            }
            return $node;
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $k => $v) {
                $node->{$k} = $this->remapAttachmentRefs($v, $ordinals, $label);
            }
            return $node;
        }
        return $node;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }
}
