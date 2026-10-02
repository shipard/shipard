<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Core\Prints\PrintMessage;
use Shipard\Module\Docs\Core\Prints\DocPrintContext;
use Shipard\Module\Docs\Core\Prints\PaymentQr\PaymentQrInput;
use Shipard\Module\Docs\Core\Prints\PaymentQr\PaymentQrResolver;

/**
 * Blok `payment` — způsob úhrady, symboly, náš účet ze snapshotu dodavatele,
 * částka k úhradě a QR platba (#90 D17).
 *
 * Účet jen u platby převodem (hotově ani kartou se na něj neplatí), QR
 * navíc jen pro kladnou částku. Když QR nevznikne kvůli
 * chybějícímu účtu, nebo se do něj nevejde některý symbol, přidá měkké
 * hlášení — tisk vznikne i tak.
 */
final class DocPaymentBlock implements DocPrintBlock
{
    private const METHOD_BANK_TRANSFER = 1;

    public function __construct(
        private readonly PaymentQrResolver $qrResolver = new PaymentQrResolver(),
    ) {}

    public function build(DocPrintContext $context): array
    {
        $head      = $context->head;
        $methodId  = (int) ($head['payment_method'] ?? 0);
        $amount    = (float) ($head['total_amount'] ?? 0.0);
        $transfer  = $methodId === self::METHOD_BANK_TRANSFER;
        $account   = $transfer ? ($context->supplier['bank_account'] ?? null) : null;
        $account   = is_array($account) && $account !== [] ? $account : null;

        $payment = [
            'method'         => ['id' => $methodId, 'label' => $this->methodLabel($context, $methodId)],
            'reference'      => DocPrintContext::text($head['payment_reference'] ?? null),
            'specificSymbol' => DocPrintContext::text($head['specific_symbol'] ?? null),
            'constantSymbol' => DocPrintContext::text($head['constant_symbol'] ?? null),
            'bankAccount'    => $account,
            'amountToPay'    => $amount,
            'currency'       => $context->currency(),
            'qr'             => null,
        ];

        if ($transfer && $amount > 0.0) {
            $payment['qr'] = $this->buildQr($context, $payment, $account);
        }

        return ['payment' => $payment];
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed>|null $account
     * @return array{standard: string, payload: string}|null
     */
    private function buildQr(DocPrintContext $context, array $payment, ?array $account): ?array
    {
        $generator = $this->qrResolver->forCustomerCountry(
            DocPrintContext::text($context->customer['address']['country'] ?? null),
        );

        $payload = $generator->generate(new PaymentQrInput(
            iban: DocPrintContext::text($account['iban'] ?? null),
            bic: DocPrintContext::text($account['bic'] ?? null),
            accountNumber: DocPrintContext::text($account['account_number'] ?? null),
            amount: (float) $payment['amountToPay'],
            currency: (string) $payment['currency'],
            paymentReference: $payment['reference'],
            specificSymbol: $payment['specificSymbol'],
            constantSymbol: $payment['constantSymbol'],
            dueDate: DocPrintContext::date($context->head['due_date'] ?? null),
            message: DocPrintContext::text($context->head['doc_number'] ?? null),
        ));

        if ($payload === null) {
            $context->addMessage(PrintMessage::warning(
                'payment.qrNoAccount',
                $context->translator->t('message.qrNoAccount'),
            ));
            return null;
        }
        foreach ($generator->skippedFields() as $field) {
            $context->addMessage(PrintMessage::warning(
                'payment.qrSkipped.' . $field,
                $context->translator->t('message.qrSkipped.' . $field),
            ));
        }

        return ['standard' => $generator->standard(), 'payload' => $payload];
    }

    private function methodLabel(DocPrintContext $context, int $methodId): string
    {
        $methods = $context->config?->cfgItem('docs.core.paymentMethods');
        $label   = is_array($methods) ? ($methods[$methodId]['name'] ?? null) : null;
        return is_string($label) ? $label : (string) $methodId;
    }
}
