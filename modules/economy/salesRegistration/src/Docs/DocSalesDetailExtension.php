<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Docs;

use Shipard\Core\I18n\DisplayDate;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\DetailExtension;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\Evaluation\ReasonTexts;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Evidence tržeb v detailu dokladu (#171 D13, U18):
 *
 *  - záložka **Evidence tržeb** u dokladů, které do evidence spadají, mají
 *    tržbu nebo se jich týká kontaktní platba: nahoře vyhodnocení (spadá
 *    a jakou částkou / nespadá a proč / nejde založit a proč — texty, ne
 *    kódy), pod ním tržby dokladu s odkazem do agendy Evidence tržeb;
 *  - štítek v hlavičce = stav evidence dokladu (nejhorší stav jeho tržeb);
 *    doklad bez tržby štítek nemá.
 *
 * Vyhodnocení je totéž, které dělá rozdílový výpočet ({@see SaleSync::evaluation()}):
 * řádky dokladu se čtou jen u typů s pravidlem nad řádky. Nic nezapisuje.
 */
class DocSalesDetailExtension implements DetailExtension
{
    /** Agenda Evidence tržeb — cíl odkazu na tržbu (`open_detail`). */
    public const string SALES_VIEWER = 'economy.salesRegistration.sales';

    /** Kolik znaků kódu potvrzení tabulka ukazuje (celý je v detailu tržby). */
    public const int CODE_TAIL = 8;

    /** @var array<int, list<array<string, mixed>>> tržby dokladu — záložka i štítek je čtou jednou */
    private array $sales = [];

    private ?DocFactsLoader $loader = null;

    public function tabs(string $table, array $record, ModuleExtensionContext $context): array
    {
        $docId = (int) ($record['id'] ?? 0);
        if ($docId <= 0) {
            return [];
        }
        $sales = $this->salesOf($docId, $context);
        $doc = $this->facts($record, $context);
        $evaluation = $this->evaluation($doc, $context);
        $offered = $this->contactPaymentOffered($doc, $context);

        if (!$evaluation->applicable && !$evaluation->unregistrable && $sales === [] && !$offered && !$doc->contactPayment) {
            return [];
        }

        $t = Texts::of($context->config);
        $blocks = [[
            'type'   => 'properties',
            'groups' => array_values(array_filter([
                $this->evaluationGroup($doc, $evaluation, $context),
                $this->contactPaymentGroup($doc, $offered, $context),
            ])),
        ]];
        if ($offered) {
            $blocks[] = [
                'type' => 'notice',
                'tone' => 'info',
                'text' => $t->t(
                    'doc.offerNotice',
                    'If the customer paid the document on the spot — by QR code or by card on a terminal — mark it with the Contact payment action.',
                ),
            ];
        }
        $blocks[] = $sales !== []
            ? $this->salesTable($sales, $context)
            : ['type' => 'empty', 'text' => $t->t('doc.noSales', 'The document has no sale yet.')];

        return [[
            'id'      => 'sales',
            'label'   => $t->t('doc.tab', 'Sales registration'),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ]];
    }

    public function badges(string $table, array $record, ModuleExtensionContext $context): array
    {
        $docId = (int) ($record['id'] ?? 0);
        if ($docId <= 0) {
            return [];
        }
        $sales = $this->salesOf($docId, $context);
        $docSales = $this->docSales($context);
        $tag = $docSales->tag(
            array_map(static fn(array $sale): string => (string) $sale['state'], $sales),
            array_filter($sales, static fn(array $sale): bool => !empty($sale['test'])) !== [],
        );
        return $tag === null ? [] : [['label' => $tag['label'], 'style' => $docSales->stateStyle($tag['state'])]];
    }

    /** @return array{title: string, items: list<array{label: string, value: string}>} */
    private function evaluationGroup(DocFacts $doc, DocEvaluation $evaluation, ModuleExtensionContext $context): array
    {
        $t = Texts::of($context->config);
        $items = [];

        if ($evaluation->applicable) {
            $verdict = $t->t('doc.verdictFalls', 'Falls — {amount}', [
                'amount' => Money::display($evaluation->cents, (string) $evaluation->registration?->type->currency, $context->language),
            ]);
        } elseif ($evaluation->unregistrable) {
            $verdict = $t->t('doc.verdictUnregistrable', 'Falls, but the sale cannot be created');
        } else {
            $verdict = $t->t('doc.verdictNot', 'Does not fall');
        }
        $items[] = ['label' => $t->t('doc.verdict', 'Sales registration'), 'value' => $verdict];

        if (!$evaluation->applicable) {
            $items[] = ['label' => $t->t('doc.reason', 'Reason'), 'value' => ReasonTexts::of($context->config)->text($evaluation->reason)];
        } else {
            $items[] = [
                'label' => $t->t('doc.source', 'Falls by'),
                'value' => $evaluation->source === DocEvaluation::SOURCE_CONTACT_PAYMENT
                    ? $t->t('doc.sourceContactPayment', 'contact payment')
                    : $t->t('doc.sourceRule', 'document type and payment method'),
            ];
            $items[] = ['label' => $t->t('doc.unit', 'Registration unit'), 'value' => (string) $evaluation->unitId];
            $items[] = ['label' => $t->t('doc.cashRegister', 'Cash register'), 'value' => (string) $evaluation->cashRegisterId];
        }

        return ['title' => $t->t('doc.groupEvaluation', 'Evaluation'), 'items' => $items];
    }

    /** @return array{title: string, items: list<array{label: string, value: string}>}|null */
    private function contactPaymentGroup(DocFacts $doc, bool $offered, ModuleExtensionContext $context): ?array
    {
        if (!$doc->contactPayment && !$offered) {
            return null;
        }
        $t = Texts::of($context->config);
        $items = [[
            'label' => $t->t('doc.contactPayment', 'Contact payment'),
            'value' => $doc->contactPayment ? $t->t('doc.yes', 'Yes') : $t->t('doc.no', 'No'),
        ]];
        if ($doc->contactPayment) {
            $items[] = [
                'label' => $t->t('doc.paidAt', 'Payment time'),
                'value' => DisplayDate::dateTime($doc->contactPaymentAt, $context->language)
                    ?? $t->t('doc.paidAtTransition', 'the time the document is confirmed'),
            ];
        }
        return ['title' => $t->t('doc.groupContactPayment', 'Contact payment'), 'items' => $items];
    }

    /**
     * @param list<array<string, mixed>> $sales
     * @return array<string, mixed>
     */
    private function salesTable(array $sales, ModuleExtensionContext $context): array
    {
        $t = Texts::of($context->config);
        $docSales = $this->docSales($context);

        $rows = [];
        $sum = 0;
        $currency = '';
        foreach ($sales as $sale) {
            $cents = Money::cents($sale['amount'] ?? 0);
            $sum += $cents;
            $currency = (string) ($sale['currency'] ?? $currency);
            $state = (string) ($sale['state'] ?? '');
            $code = trim((string) ($sale['confirmation_code'] ?? ''));
            $row = [
                'serial_number' => (string) ($sale['serial_number'] ?? ''),
                'sold_at'       => DisplayDate::dateTime($sale['sold_at'] ?? null, $context->language) ?? '',
                'amount'        => Money::display($cents, $currency, $context->language),
                'state'         => $docSales->stateLabel($state) . (!empty($sale['test']) ? ' · ' . $t->t('doc.saleTest', 'test') : ''),
                'code'          => $code !== '' ? '…' . substr($code, -self::CODE_TAIL) : '',
                'attempts'      => (string) (int) ($sale['attempt_count'] ?? 0),
                '_action'       => [
                    'id'     => 'openSale',
                    'kind'   => 'open_detail',
                    'target' => ['viewerId' => self::SALES_VIEWER, 'recordId' => (int) $sale['id']],
                ],
            ];
            if ($state === SaleState::FAILED) {
                $row['_class'] = 'error';
            }
            $rows[] = $row;
        }
        if (count($sales) > 1) {
            $rows[] = [
                'serial_number' => $t->t('doc.salesTotal', 'Total'),
                'sold_at'       => '',
                'amount'        => Money::display($sum, $currency, $context->language),
                'state'         => '',
                'code'          => '',
                'attempts'      => '',
                '_class'        => 'total',
            ];
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'serial_number', 'label' => $t->t('doc.colSerialNumber', 'Serial number'), 'link' => true],
                ['id' => 'sold_at',       'label' => $t->t('doc.colSoldAt', 'Sale time')],
                ['id' => 'amount',        'label' => $t->t('doc.colAmount', 'Amount'), 'align' => 'right'],
                ['id' => 'state',         'label' => $t->t('doc.colState', 'State')],
                ['id' => 'code',          'label' => $t->t('doc.colCode', 'Confirmation code')],
                ['id' => 'attempts',      'label' => $t->t('doc.colAttempts', 'Attempts'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /** Nabízí doklad akci Kontaktní platba? (V pořádku, bez příznaku, podle dat typu — U14.) */
    private function contactPaymentOffered(DocFacts $doc, ModuleExtensionContext $context): bool
    {
        return $doc->docState === DocFacts::STATE_CONFIRMED
            && !$doc->contactPayment
            && !$doc->isImported()
            && $this->policy($context)->allows($doc->docType, $doc->paymentMethod);
    }

    /** @return list<array<string, mixed>> */
    private function salesOf(int $docId, ModuleExtensionContext $context): array
    {
        return $this->sales[$docId] ??= $this->loadSales($docId, $context);
    }

    // ── Čtení (přepsatelné v testech) ───────────────────────────────────────

    protected function docSales(ModuleExtensionContext $context): DocSales
    {
        return new DocSales($context->db->getDibiConnection(), $context->config);
    }

    /** @return list<array<string, mixed>> */
    protected function loadSales(int $docId, ModuleExtensionContext $context): array
    {
        return $this->docSales($context)->of($docId);
    }

    /** Jeden na požadavek — registrace a číselné řady si pamatuje, čtou se jednou. */
    protected function loader(ModuleExtensionContext $context): DocFactsLoader
    {
        return $this->loader ??= new DocFactsLoader($context->db->getDibiConnection(), $context->config, $context->dsConfig);
    }

    /** @param array<string, mixed> $record záznam detailu dokladu (všechny sloupce hlavičky) */
    protected function facts(array $record, ModuleExtensionContext $context): DocFacts
    {
        return $this->loader($context)->fromHead($record);
    }

    protected function evaluation(DocFacts $doc, ModuleExtensionContext $context): DocEvaluation
    {
        $loader = $this->loader($context);
        return (new SaleSync($context->db->getDibiConnection(), $loader, $context->config))->evaluation($doc);
    }

    protected function policy(ModuleExtensionContext $context): ContactPaymentPolicy
    {
        return ContactPaymentPolicy::fromLoader($this->loader($context));
    }
}
