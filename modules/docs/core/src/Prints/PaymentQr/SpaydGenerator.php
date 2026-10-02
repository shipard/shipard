<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\PaymentQr;

/**
 * SPAYD (Short Payment Descriptor, „QR platba“ ČBA) verze 1.0.
 *
 * Pole: `ACC` (IBAN[+BIC]), `AM` (2 desetinná místa), `CC`, `X-VS`, `X-SS`,
 * `X-KS`, `DT` (splatnost `YYYYMMDD`), `MSG` (číslo dokladu). Účet: IBAN
 * z dokladu; chybí-li a číslo účtu je české, IBAN se dopočítá. Bez účtu
 * payload nevznikne.
 *
 * Symboly dovoluje standard jen číselné, nejvýš 10 číslic — jiný tvar se
 * vynechá (QR bez VS je pořád použitelný) a volající se o tom dozví přes
 * `skippedFields()`.
 */
final class SpaydGenerator implements PaymentQrGenerator
{
    private const MESSAGE_MAX_LENGTH = 60;

    /** @var list<string> */
    private array $skipped = [];

    public function standard(): string
    {
        return 'spayd';
    }

    public function generate(PaymentQrInput $input): ?string
    {
        $this->skipped = [];

        $iban = $this->resolveIban($input);
        if ($iban === null) {
            return null;
        }

        $account = $iban;
        $bic = strtoupper((string) preg_replace('/\s+/', '', (string) $input->bic));
        if (preg_match('/^[A-Z0-9]{8}(?:[A-Z0-9]{3})?$/', $bic)) {
            $account .= '+' . $bic;
        }

        $fields = [
            'ACC' => $account,
            'AM'  => number_format($input->amount, 2, '.', ''),
            'CC'  => strtoupper($input->currency),
        ];

        $symbols = [
            'X-VS' => ['paymentReference', $input->paymentReference],
            'X-SS' => ['specificSymbol', $input->specificSymbol],
            'X-KS' => ['constantSymbol', $input->constantSymbol],
        ];
        foreach ($symbols as $key => [$field, $value]) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^\d{1,10}$/', $value)) {
                $this->skipped[] = $field;
                continue;
            }
            $fields[$key] = $value;
        }

        if ($input->dueDate !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $input->dueDate, $m)) {
            $fields['DT'] = $m[1] . $m[2] . $m[3];
        }

        $message = trim((string) $input->message);
        if ($message !== '') {
            $fields['MSG'] = mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);
        }

        $payload = 'SPD*1.0';
        foreach ($fields as $key => $value) {
            $payload .= '*' . $key . ':' . self::escape($value);
        }
        return $payload;
    }

    public function skippedFields(): array
    {
        return $this->skipped;
    }

    private function resolveIban(PaymentQrInput $input): ?string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $input->iban));
        if ($iban !== '') {
            return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) ? $iban : null;
        }
        if ($input->accountNumber === null || trim($input->accountNumber) === '') {
            return null;
        }
        return CzIbanCalculator::fromAccountNumber($input->accountNumber);
    }

    /**
     * Hvězdička odděluje pole, proto se v hodnotě kóduje procentově;
     * procento samo taky, ať je zápis jednoznačný.
     */
    private static function escape(string $value): string
    {
        return strtr($value, ['%' => '%25', '*' => '%2A']);
    }
}
