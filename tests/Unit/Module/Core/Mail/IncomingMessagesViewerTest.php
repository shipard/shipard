<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IncomingMessagesViewer;

/**
 * Layout řádku Došlé pošty po zavedení partnera zprávy
 * (tasks/mail-message-title-partner.md D7): t2 = partner (Osoba ??
 * partner_name) s fallbackem na odesílatele, odesílatel pak v t3 za
 * schránkou. Bez ConfigRuntime → anglické fallback popisky.
 */
final class IncomingMessagesViewerTest extends TestCase
{
    private function viewer(): IncomingMessagesViewer
    {
        return new IncomingMessagesViewer(
            $this->createMock(DataSourceConnection::class),
            'core_mail_incoming_messages',
        );
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'subject'           => 'Message from scanner',
            'sender_email'      => 'scanner@example.test',
            'sender_name'       => 'Kancelářský skener',
            'primary_type'      => 'invoiceReceived',
            'received_at'       => '2026-09-01 10:00:00',
            'body_plain'        => "Dobrý den,\nv příloze zasíláme doklad.",
            'docState'          => 20,
            'docStateMain'      => 2,
            'analysis_state'    => 30,
            'is_bulk'           => 0,
            'mailbox'           => 1,
            'mailbox_name'      => 'Faktury',
            'mailbox_code'      => 'inv',
            'partner_person'    => null,
            'partner_name'      => null,
            'partner_full_name' => null,
            'ai_title'          => null,
            'source_type'       => 2,
        ], $overrides);
    }

    // ── t1: titulek (D3) — bez configu jen prázdný předmět a ruční zprávy ──

    public function testT1KeepsSubjectForEmailWithAiTitle(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['ai_title' => 'Faktura 2026-0042 — Dodavatel s.r.o.']));
        $this->assertSame('Message from scanner', $rendered['t1']);
    }

    public function testT1UsesAiTitleForManualMessage(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'subject'     => 'faktura_final_v2.pdf',
            'source_type' => 1,
            'ai_title'    => 'Faktura 2026-0042 — Dodavatel s.r.o.',
        ]));
        $this->assertSame('Faktura 2026-0042 — Dodavatel s.r.o.', $rendered['t1']);
    }

    public function testT1UsesAiTitleForEmptySubject(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['subject' => '', 'ai_title' => 'Dopis od úřadu']));
        $this->assertSame('Dopis od úřadu', $rendered['t1']);

        $withoutTitle = $this->viewer()->renderRow($this->row(['subject' => '', 'ai_title' => null]));
        $this->assertSame('', $withoutTitle['t1']);
    }

    /** @return list<string> */
    private function t3Texts(array $rendered): array
    {
        return array_map(static fn(array $part): string => $part['text'], $rendered['t3'] ?? []);
    }

    public function testPersonNamePreferredOverCanonicalSnapshot(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'partner_person'    => 77,
            'partner_name'      => 'Dodavatel sro',
            'partner_full_name' => 'Dodavatel s.r.o.',
        ]));

        $this->assertSame('Message from scanner', $rendered['t1']);
        $this->assertSame('Dodavatel s.r.o.', $rendered['t2']);

        $t3 = $this->t3Texts($rendered);
        $this->assertSame('[Faktury]', $t3[0]);
        $this->assertStringEndsWith(': Kancelářský skener', $t3[1], 'odesílatel se přesouvá do t3 za schránku');
        $this->assertSame('Dobrý den,', $t3[2]);
    }

    public function testCanonicalSnapshotWhenPersonMissing(): void
    {
        // Smazaná / nespárovaná Osoba → LEFT JOIN vrátí null, t2 padá na partner_name (P9).
        $rendered = $this->viewer()->renderRow($this->row([
            'partner_person'    => 77,
            'partner_name'      => 'Dodavatel s.r.o.',
            'partner_full_name' => null,
        ]));

        $this->assertSame('Dodavatel s.r.o.', $rendered['t2']);
        $this->assertStringEndsWith(': Kancelářský skener', $this->t3Texts($rendered)[1]);
    }

    public function testSenderFallbackWithoutPartnerKeepsLegacyLayout(): void
    {
        $rendered = $this->viewer()->renderRow($this->row());

        $this->assertSame('Kancelářský skener', $rendered['t2']);
        // Bez partnera se odesílatel v t3 neopakuje.
        $this->assertSame(['[Faktury]', 'Dobrý den,'], $this->t3Texts($rendered));
    }

    public function testSenderEmailFallbackWhenNameMissing(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['sender_name' => null]));
        $this->assertSame('scanner@example.test', $rendered['t2']);

        $withPartner = $this->viewer()->renderRow($this->row([
            'sender_name'  => '',
            'partner_name' => 'Dodavatel s.r.o.',
        ]));
        $this->assertSame('Dodavatel s.r.o.', $withPartner['t2']);
        $this->assertStringEndsWith(': scanner@example.test', $this->t3Texts($withPartner)[1]);
    }

    public function testEmptySenderAndPartnerGiveNullT2(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'sender_name'  => null,
            'sender_email' => '',
            'body_plain'   => null,
        ]));

        $this->assertNull($rendered['t2']);
        $this->assertSame(['[Faktury]'], $this->t3Texts($rendered));
    }

    // ── Detail: karta selhání v tabu Návrh, sloupec Chyba v Analýzách ─────
    // (tasks/mail-analysis-error-messages.md D3a, D3b, D5). Bez configu
    // → anglický fallback katalogu analysisErrorKinds.

    /**
     * Viewer s mockem pro renderDetail: fetchRow routuje záznam zprávy
     * (JOIN na schránky), poslední úspěšný (status param 2) a poslední
     * selhaný (status param 3) běh; fetchAll vrací historii běhů pro tab
     * Analýzy, přílohy prázdné.
     *
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $analyses
     * @param array<string, mixed>|null $lastSuccess
     * @param array<string, mixed>|null $lastFailed
     */
    private function detailViewer(array $record, array $analyses = [], ?array $lastSuccess = null, ?array $lastFailed = null): IncomingMessagesViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($record, $lastSuccess, $lastFailed): ?array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_mail_mailboxes')) {
                    return $record;
                }
                if (str_contains($sql, 'core_mail_message_analyses')) {
                    return match ((int) ($args[2] ?? 0)) {
                        2       => $lastSuccess,
                        3       => $lastFailed,
                        default => null,
                    };
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static fn(mixed ...$args): array => str_contains((string) $args[0], 'core_mail_message_analyses') ? $analyses : [],
        );
        return new IncomingMessagesViewer($db, 'core_mail_incoming_messages');
    }

    /** @return array<string, mixed> content tabu podle id */
    private function tabContent(IncomingMessagesViewer $viewer, string $tabId): array
    {
        foreach ($viewer->renderDetail(1)['tabs'] as $tab) {
            if ($tab['id'] === $tabId) {
                return $tab['content'];
            }
        }
        $this->fail("Tab {$tabId} chybí");
    }

    /** @return array<string, mixed> */
    private function failedRun(string $errorMessage, string $promptVersion = 'v4.3.0'): array
    {
        return [
            'id'             => 9,
            'analyzed_at'    => '2026-09-30 08:15:00',
            'status'         => 3,
            'model_name'     => 'claude-x',
            'model_version'  => null,
            'prompt_version' => $promptVersion,
            'confidence'     => null,
            'cost_usd'       => null,
            'duration_ms'    => null,
            'has_proposal'   => 0,
            'invalid_output' => 0,
            'resolution'     => null,
            'error_message'  => $errorMessage,
        ];
    }

    public function testFailedStateCarriesFailureInsteadOfClassification(): void
    {
        $failed = $this->failedRun("[schema_error] output does not match schema: 'Lorem ipsum' is too long at ['document', 'title']");
        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 70, 'primary_type' => 'other']), [$failed], null, $failed),
            'proposal',
        );

        $this->assertSame('proposal', $content['type']);
        $this->assertNull($content['proposal']);
        $this->assertArrayNotHasKey('classification', $content, 'primary_type je ve stavu 70 jen výchozí hodnota');

        $failure = $content['failure'];
        $this->assertSame('schemaTooLong', $failure['kind']);
        $this->assertSame('AI returned data in an unexpected shape', $failure['title']);
        $this->assertSame('The value in field title is longer than the format allows.', $failure['detail']);
        $this->assertFalse($failure['reanalysisRecommended']);
        $this->assertStringStartsWith('[schema_error]', $failure['technical']);
        $this->assertSame('v4.3.0', $failure['promptVersion']);
        $this->assertNotNull($failure['analyzedAt']);
    }

    public function testAnalyzedStateWithoutProposalKeepsClassification(): void
    {
        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 30, 'primary_type' => 'other'])),
            'proposal',
        );

        $this->assertNull($content['proposal']);
        $this->assertNull($content['failure']);
        $this->assertSame('other', $content['classification']['primary_type']);
    }

    public function testInvalidOutputProposalCarriesOwnFailure(): void
    {
        $success = [
            'id'             => 12,
            'message'        => 1,
            'profile'        => null,
            'analyzed_at'    => '2026-09-30 09:00:00',
            'status'         => 2,
            'prompt_version' => 'v4.3.0',
            'proposed_type'  => 'invoiceReceived',
            'confidence'     => 0.5,
            'resolution'     => null,
            'resolved_at'    => null,
            'rejected_reason' => null,
            'analysis_json'  => null,
            'canonical_json' => json_encode(['_validationError' => 'Canonical schema validation failed', '_validationIssues' => [], '_rawOutput' => []]),
        ];
        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 30]), [], $success),
            'proposal',
        );

        $this->assertNull($content['failure']);
        $doc = $content['proposal'];
        $this->assertTrue($doc['ai_failed']);
        $this->assertSame('invalidOutput', $doc['failure']['kind']);
        $this->assertSame('AI returned an unusable proposal', $doc['failure']['title']);
        $this->assertNull($doc['failure']['technical']);
        $this->assertSame('v4.3.0', $doc['failure']['promptVersion']);
        $this->assertFalse($doc['can_apply']);
    }

    public function testFailedStateKeepsOlderProposalAndAddsFailure(): void
    {
        $success = [
            'id'             => 12,
            'message'        => 1,
            'profile'        => null,
            'analyzed_at'    => '2026-09-29 09:00:00',
            'status'         => 2,
            'model_name'     => 'claude-x',
            'model_version'  => null,
            'prompt_version' => 'v4.2.0',
            'proposed_type'  => 'invoiceReceived',
            'confidence'     => 0.9,
            'cost_usd'       => null,
            'duration_ms'    => null,
            'has_proposal'   => 1,
            'invalid_output' => 0,
            'resolution'     => null,
            'resolved_at'    => null,
            'rejected_reason' => null,
            'analysis_json'  => null,
            'canonical_json' => json_encode(['docNumber' => 'F-1', 'supplier' => ['name' => 'X'], 'totals' => ['totalAmount' => 10.0]]),
        ];
        $failed = $this->failedRun('[ai_error] anthropic: output truncated at max_tokens=8192');
        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 70]), [$failed, $success], $success, $failed),
            'proposal',
        );

        $this->assertSame('aiTruncated', $content['failure']['kind']);
        $this->assertNotNull($content['proposal'], 'starší návrh zůstává vykreslený');
        $this->assertFalse($content['proposal']['ai_failed']);
        $this->assertNull($content['proposal']['failure']);
        $this->assertFalse($content['proposal']['can_apply'], 'akce jen ve stavu 30');
    }

    public function testAnalysesTabHasErrorColumnFromCatalog(): void
    {
        $failed = $this->failedRun('[ai_error] anthropic: output truncated at max_tokens=8192');
        $invalid = $this->failedRun('') + [];
        $invalid['id'] = 10;
        $invalid['status'] = 2;
        $invalid['has_proposal'] = 1;
        $invalid['invalid_output'] = 1;
        $invalid['error_message'] = null;
        $ok = $invalid;
        $ok['id'] = 11;
        $ok['invalid_output'] = 0;

        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 70]), [$failed, $invalid, $ok], null, $failed),
            'analyses',
        );

        $this->assertSame('table', $content['type']);
        $ids = array_column($content['columns'], 'id');
        $this->assertSame(['analyzed_at', 'status', 'error'], array_slice($ids, 0, 3));
        $this->assertSame('Chyba', $content['columns'][2]['label']);
        $this->assertSame('The AI response did not fit within the limit', $content['rows'][0]['error']);
        $this->assertSame('AI returned an unusable proposal', $content['rows'][1]['error']);
        $this->assertSame('—', $content['rows'][2]['error']);
    }

    public function testAnalysesErrorCellAppendsDetail(): void
    {
        $failed = $this->failedRun("[schema_error] output does not match schema: 'x' is not one of ['a'] at ['document', 'extracted_json', 'rows', 0, 'vat', 'code']");
        $content = $this->tabContent(
            $this->detailViewer($this->row(['analysis_state' => 70]), [$failed], null, $failed),
            'analyses',
        );

        $this->assertSame(
            'AI returned data in an unexpected shape — The value in field rows.0.vat.code is not one of the allowed options.',
            $content['rows'][0]['error'],
        );
    }

    // ── Předzpracování: karta v Obsahu, upozornění v Návrhu ──────────────
    // (tasks/mail-preprocess-error-messages.md D3a, D3b). Bez configu
    // → anglický fallback katalogu preprocessErrorKinds. Fixtury jen
    // s fiktivními doménami — poznámky nesou URL.

    private const FAILED_FETCH = [
        'ruleId' => 'bolt-invoice-link',
        'action' => 'fetchLinkedDocument',
        'ok'     => false,
        'note'   => 'HTTP 404 at https://files.example.net/dl/abc?token=secret',
        'code'   => 'linkExpired',
    ];

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $extra
     */
    private function preprocessLog(array $results, array $extra = []): string
    {
        return (string) json_encode($extra + [
            'plan'       => [['ruleId' => 'bolt-invoice-link', 'ruleNdx' => 1, 'actions' => [['action' => 'fetchLinkedDocument']]]],
            'results'    => $results,
            'attempts'   => 1,
            'isdoc'      => 'none',
            'finishedAt' => '2026-10-02T10:00:00+02:00',
        ]);
    }

    /**
     * Indexy bloku `failure` a technického bloku „Předzpracování" v obsahu
     * tabu Obsah (composite, nebo jediný blok).
     *
     * @param array<string, mixed> $content
     * @return array{failure: ?int, properties: ?int, blocks: list<array<string, mixed>>}
     */
    private function contentBlocks(array $content): array
    {
        $blocks = $content['type'] === 'composite' ? $content['blocks'] : [$content];
        $failure = null;
        $properties = null;
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'failure') {
                $failure = $i;
            }
            if ($block['type'] === 'properties' && ($block['groups'][0]['title'] ?? '') === 'Předzpracování') {
                $properties = $i;
            }
        }
        return ['failure' => $failure, 'properties' => $properties, 'blocks' => $blocks];
    }

    /** @return array<string, mixed> */
    private function successRun(): array
    {
        return [
            'id'              => 12,
            'message'         => 1,
            'profile'         => null,
            'analyzed_at'     => '2026-09-29 09:00:00',
            'status'          => 2,
            'model_name'      => 'claude-x',
            'model_version'   => null,
            'prompt_version'  => 'v4.2.0',
            'proposed_type'   => 'invoiceReceived',
            'confidence'      => 0.9,
            'cost_usd'        => null,
            'duration_ms'     => null,
            'has_proposal'    => 1,
            'invalid_output'  => 0,
            'resolution'      => null,
            'resolved_at'     => null,
            'rejected_reason' => null,
            'error_message'   => null,
            'analysis_json'   => null,
            'canonical_json'  => json_encode([
                'selfParty' => 'customer',
                'supplier'  => ['name' => 'Dodavatel s.r.o.'],
                'currency'  => 'CZK',
                'totals'    => ['totalAmount' => 1200.0],
            ]),
        ];
    }

    public function testPreprocessFailureCardPrecedesTechnicalBlockInContent(): void
    {
        $content = $this->tabContent(
            $this->detailViewer($this->row([
                'preprocess_state' => 40,
                'preprocess_log'   => $this->preprocessLog([self::FAILED_FETCH]),
            ])),
            'content',
        );

        $idx = $this->contentBlocks($content);
        $this->assertNotNull($idx['failure'], 'blok failure chybí');
        $this->assertNotNull($idx['properties'], 'technický blok chybí');
        $this->assertLessThan($idx['properties'], $idx['failure'], 'karta stojí nad technickým blokem');

        $failure = $idx['blocks'][$idx['failure']]['failure'];
        $this->assertSame('linkExpired', $failure['kind']);
        $this->assertSame('warning', $failure['variant']);
        $this->assertSame('The document link does not work', $failure['title']);
        $this->assertSame(1, $failure['failedCount']);
        $this->assertNull($failure['detail']);
        $this->assertStringContainsString('files.example.net', $failure['technical']);
        $this->assertNotNull($failure['finishedAt']);
        // URL s tokenem jen v technických podrobnostech.
        $this->assertStringNotContainsString('example.net', $failure['title'] . $failure['description'] . $failure['hint']);
    }

    public function testIsdocFailedGivesInfoCardInContent(): void
    {
        $content = $this->tabContent(
            $this->detailViewer($this->row([
                'preprocess_state' => 30,
                'preprocess_log'   => $this->preprocessLog([], ['isdoc' => 'failed']),
            ])),
            'content',
        );

        $idx = $this->contentBlocks($content);
        $this->assertNotNull($idx['failure']);
        $failure = $idx['blocks'][$idx['failure']]['failure'];
        $this->assertSame('isdocFailed', $failure['kind']);
        $this->assertSame('info', $failure['variant']);
        $this->assertSame('The ISDOC attachment could not be read', $failure['title']);
        $this->assertNull($failure['technical']);
    }

    public function testNoFailureCardWhenPreprocessSucceeded(): void
    {
        $ok = ['ruleId' => 'bolt-invoice-link', 'action' => 'fetchLinkedDocument', 'ok' => true, 'note' => 'fetched → attachment 5', 'attachmentId' => 5];
        $content = $this->tabContent(
            $this->detailViewer($this->row(['preprocess_state' => 30, 'preprocess_log' => $this->preprocessLog([$ok])])),
            'content',
        );

        $idx = $this->contentBlocks($content);
        $this->assertNull($idx['failure']);
        $this->assertNotNull($idx['properties'], 'technický blok zůstává');
    }

    public function testProposalCarriesPreprocessWarningWithoutProposal(): void
    {
        $content = $this->tabContent(
            $this->detailViewer($this->row([
                'analysis_state'   => 30,
                'primary_type'     => 'other',
                'preprocess_state' => 40,
                'preprocess_log'   => $this->preprocessLog([self::FAILED_FETCH]),
            ])),
            'proposal',
        );

        $this->assertNull($content['proposal']);
        $this->assertNull($content['failure']);
        $warning = $content['preprocessWarning'];
        $this->assertSame('linkExpired', $warning['kind']);
        $this->assertSame('The proposal was created without the preprocessing result', $warning['title']);
        $this->assertStringContainsString('Content tab', $warning['text']);
        $this->assertStringNotContainsString('example.net', (string) json_encode($warning));
        $this->assertSame('other', $content['classification']['primary_type'], 'klasifikace zůstává, upozornění ji jen zpochybňuje');
    }

    public function testProposalCarriesPreprocessWarningNextToProposalAndAnalysisFailure(): void
    {
        // Stav 40 + selhaná analýza nad starším návrhem: všechny tři kusy
        // najednou — upozornění předzpracování, karta selhání, návrh.
        $success = $this->successRun();
        $failed = $this->failedRun('[ai_error] anthropic: output truncated at max_tokens=8192');
        $content = $this->tabContent(
            $this->detailViewer(
                $this->row([
                    'analysis_state'   => 70,
                    'preprocess_state' => 40,
                    'preprocess_log'   => $this->preprocessLog([self::FAILED_FETCH]),
                ]),
                [$failed, $success],
                $success,
                $failed,
            ),
            'proposal',
        );

        $this->assertSame('aiTruncated', $content['failure']['kind']);
        $this->assertNotNull($content['proposal']);
        $this->assertSame('linkExpired', $content['preprocessWarning']['kind']);
    }

    public function testProposalWarningAbsentOutsideStateForty(): void
    {
        $content = $this->tabContent(
            $this->detailViewer($this->row([
                'preprocess_state' => 30,
                'preprocess_log'   => $this->preprocessLog([], ['isdoc' => 'failed']),
            ])),
            'proposal',
        );

        $this->assertArrayHasKey('preprocessWarning', $content);
        $this->assertNull($content['preprocessWarning'], 'selhaný ISDOC je jen v Obsahu');
    }
}
