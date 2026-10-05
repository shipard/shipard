<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Core\Mail\Sent\SentMessagesForm;
use Shipard\Module\Core\Mail\Sent\SentMessagesViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/** Agenda Odeslaná pošta a formulář zprávy (#90 D41, D45). */
class SentMessagesViewerTest extends TestCase
{
    private function config(): \Shipard\Core\Config\ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems([
            'core.mail.docStatesSent' => [
                '40' => ['stateName' => 'Odeslaná', 'stateStyle' => 'done', 'viewGroup' => 'active'],
                '70' => ['stateName' => 'V archivu', 'stateStyle' => 'archive', 'viewGroup' => 'archive'],
            ],
            'core.mail.transportStates' => [
                'queued' => ['name' => 'Ve frontě', 'style' => 'warning'],
                'sent'   => ['name' => 'Odesláno', 'style' => 'success'],
                'failed' => ['name' => 'Neodesláno', 'style' => 'danger'],
            ],
            'core.mail.safetyActions' => [
                'redirected' => [
                    'name'           => 'Přesměrováno',
                    'nameTarget'     => 'Přesměrováno na {target}',
                    'nameRestricted' => 'Příjemci omezeni pojistkou',
                    'style'          => 'warning',
                ],
                'dropped' => ['name' => 'Zachyceno — neodesláno', 'style' => 'warning'],
            ],
            'core.system.viewerDefaults' => ['toolbarActions' => [
                'create' => ['name' => 'Přidat'], 'edit' => ['name' => 'Otevřít'],
            ]],
        ]);
    }

    /** @param ?array<string, mixed> $record Řádek pro detail. */
    private function viewer(?array $record = null): SentMessagesViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);
        $db->method('fetchAll')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'core_attachments_files')
                ? [['id' => 101, 'name' => 'faktura-2260011.pdf', 'file_name' => 'f.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf']]
                : [],
        );

        $viewer = new SentMessagesViewer($db, 'core_mail_sent_messages');
        $viewer->setConfig($this->config());
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** @return array<string, mixed> */
    private function record(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 7,
            'subject'          => 'Faktura – daňový doklad 2260011 — Naše firma s.r.o.',
            'body_text'        => "Dobrý den,\n<b>v příloze</b> posíláme fakturu.",
            'email_from'       => 'fakturace@firma.example',
            'email_from_name'  => 'Naše firma s.r.o.',
            'email_to'         => 'ucetni@odberatel.example, jana@odberatel.example',
            'email_cc'         => null,
            'recipient_name'   => 'Odběratel s.r.o.',
            'target_table_id'  => 'docs_core_heads',
            'target_row'       => 55,
            'target_label'     => 'Faktura – daňový doklad 2260011',
            'transport_state'  => 'failed',
            'last_error'       => 'Mailbox unavailable',
            'send_count'       => 1,
            'sent_at'          => '2026-10-04 14:31:00',
            'created'          => '2026-10-04 14:30:00',
            'docState'         => 40,
        ];
    }

    public function testRowShowsRecipientRecordAndTransportState(): void
    {
        $row = $this->viewer()->renderRow($this->record());

        $this->assertSame('Faktura – daňový doklad 2260011 — Naše firma s.r.o.', $row['t1']);
        $this->assertSame('Odběratel s.r.o.', $row['t2']);
        $this->assertSame('04.10.2026 14:30', $row['i1']);
        $this->assertSame([['text' => 'Neodesláno', 'class' => 'danger']], $row['i2']);
        $this->assertSame('Faktura – daňový doklad 2260011', $row['t3'][0]['text']);
        $this->assertSame('ucetni@odberatel.example, jana@odberatel.example', $row['t3'][1]['text']);
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testRowWithoutPersonFallsBackToAddresses(): void
    {
        $row = $this->viewer()->renderRow($this->record(['recipient_name' => null, 'docState' => 70]));

        $this->assertSame('ucetni@odberatel.example, jana@odberatel.example', $row['t2']);
        $this->assertSame('archive', $row['stateStyle']);
    }

    public function testRowOfRedirectedMessageCarriesSafetyLabel(): void
    {
        $row = $this->viewer()->renderRow($this->record([
            'transport_state' => 'sent',
            'safety_action'   => 'redirected',
            'safety_target'   => 'testy@firma.example',
        ]));

        // Řádek dál ukazuje původní příjemce — pojistka je jen štítek.
        $this->assertSame('ucetni@odberatel.example, jana@odberatel.example', $row['t3'][1]['text']);
        $this->assertSame([
            ['text' => 'Odesláno', 'class' => 'success'],
            ['text' => 'Přesměrováno na testy@firma.example', 'class' => 'warning'],
        ], $row['i2']);
    }

    public function testSafetyLabelVariants(): void
    {
        $label = fn (array $overrides): ?string => $this->viewer()
            ->renderRow($this->record($overrides + ['transport_state' => 'sent']))['i2'][1]['text'] ?? null;

        $this->assertSame('Zachyceno — neodesláno', $label(['safety_action' => 'dropped']));
        // Část příjemců vypadla a nikam se nepřesměrovala (allowlist bez redirectTo).
        $this->assertSame('Příjemci omezeni pojistkou', $label(['safety_action' => 'redirected', 'safety_target' => null]));
        $this->assertNull($label(['safety_action' => null]));
        // Zpráva znovu ve frontě stopu dřívějšího odeslání neukazuje.
        $this->assertNull($label(['transport_state' => 'queued', 'safety_action' => 'dropped']));
    }

    public function testDetailOfHeldMessageCarriesSafetyBadge(): void
    {
        $detail = $this->viewer($this->record(['transport_state' => 'sent', 'safety_action' => 'dropped']))
            ->renderDetail(7);

        $this->assertSame([
            ['label' => 'Odeslaná', 'style' => 'done'],
            ['label' => 'Odesláno', 'style' => 'success'],
            ['label' => 'Zachyceno — neodesláno', 'style' => 'warning'],
        ], $detail['badges']);

        $properties = array_values(array_filter(
            $detail['tabs'][0]['content']['blocks'],
            static fn (array $block): bool => $block['type'] === 'properties',
        ))[0];
        $this->assertContains(
            ['label' => 'Pojistka', 'value' => 'Zachyceno — neodesláno'],
            $properties['groups'][1]['items'],
        );
    }

    public function testSafetyLabelFallsBackToEnglishWithoutConfig(): void
    {
        $info = new \Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo(
            $this->createMock(DataSourceConnection::class),
        );

        $state = $info->state([
            'transport_state' => 'sent',
            'safety_action'   => 'redirected',
            'safety_target'   => 'testy@firma.example',
        ]);

        $this->assertSame(
            ['action' => 'redirected', 'target' => 'testy@firma.example', 'label' => 'Redirected to testy@firma.example', 'style' => 'warning'],
            $state['safety'],
        );
        $this->assertNull($info->state(['transport_state' => 'sent'])['safety']);
    }

    public function testToolbarHasNoCreateAction(): void
    {
        $viewer = $this->viewer();

        $this->assertSame([], $viewer->getToolbarActions(null));
        $this->assertSame(['edit'], array_column($viewer->getToolbarActions($this->record()), 'id'));
    }

    public function testDetailCarriesBodyAttachmentsAndLinkToRecord(): void
    {
        $detail  = $this->viewer($this->record())->renderDetail(7);
        $content = $detail['tabs'][0]['content'];

        $this->assertSame('Faktura – daňový doklad 2260011 — Naše firma s.r.o.', $detail['title']);
        $this->assertSame(
            [['label' => 'Odeslaná', 'style' => 'done'], ['label' => 'Neodesláno', 'style' => 'danger']],
            $detail['badges'],
        );

        $types = array_column($content['blocks'], 'type');
        $this->assertSame(['html', 'heading', 'attachment-grid', 'properties'], $types);
        // Tělo je prostý text — do HTML jde escapované.
        $this->assertStringContainsString('&lt;b&gt;v příloze&lt;/b&gt;', $content['blocks'][0]['html']);
        $this->assertSame('faktura-2260011.pdf', $content['blocks'][2]['attachments'][0]['name']);

        $this->assertSame([
            'table' => 'docs_core_heads', 'mode' => 'edit', 'id' => 55,
        ], $detail['actions'][0]['target']);
        $this->assertSame('open_form', $detail['actions'][0]['kind']);
    }

    public function testMessageWithoutRecordHasNoLinkAction(): void
    {
        $detail = $this->viewer($this->record(['target_table_id' => null, 'target_row' => null]))->renderDetail(7);

        $this->assertArrayNotHasKey('actions', $detail);
    }

    public function testFormIsReadOnlyAndCarriesTransportBlock(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['ts' => '2026-10-04 14:31:00', 'result' => 'fail', 'transport' => 'relay:587', 'smtp_response' => 'Mailbox unavailable'],
        ]);

        $form = new SentMessagesForm('core_mail_sent_messages');
        $form->setDb($db);
        $form->setConfig($this->config());

        $elements = [];
        foreach ($form->buildFormDefinition($this->record(), false)->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $column) {
                    foreach ($column->elements as $element) {
                        $elements[] = $element;
                    }
                }
            }
        }

        foreach ($elements as $element) {
            if (in_array($element->type, ['input', 'lookup'], true)) {
                $this->assertTrue($element->readOnly, "pole {$element->column} je jen pro čtení");
            }
        }
        $this->assertSame([], $form->getReadOnlyEditableColumns(), 'formulář nepouští žádný sloupec');

        $components = array_values(array_filter($elements, static fn (FormElement $e): bool => $e->type === 'component'));
        $this->assertSame(['sentMessageTransport', 'attachmentsView'], array_column($components, 'componentName'));

        $transport = $components[0]->params['transport'];
        $this->assertSame('failed', $transport['state']);
        $this->assertSame('Neodesláno', $transport['stateLabel']);
        $this->assertSame('Mailbox unavailable', $transport['lastError']);
        $this->assertTrue($transport['canResend'], 'selhanou zprávu ve stavu Odeslaná jde odeslat znovu');
        $this->assertSame(
            [['at' => '04.10.2026 14:31', 'ok' => false, 'transport' => 'relay:587', 'response' => 'Mailbox unavailable']],
            $transport['attempts'],
        );
    }
}
