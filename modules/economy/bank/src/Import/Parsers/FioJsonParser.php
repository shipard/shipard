<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import\Parsers;

use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\ParsedStatement;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementParser;

/**
 * FIO JSON — port `cz/fio-json/import.php`. Jediný výpis v souboru.
 *
 * `column22` = bankTransId (stabilní ID) — starý parser ho zakomentoval;
 * obnovujeme jako `externalId`. Měnu (`currency`) FIO v info nese.
 */
final class FioJsonParser implements StatementParser
{
    public function parse(string $text): array
    {
        $data = json_decode($text, true);
        if (!is_array($data) || !isset($data['accountStatement'])) {
            throw new ImportException('FIO: vstup není validní JSON výpis (chybí accountStatement).');
        }
        $stmt = $data['accountStatement'];
        $info = $stmt['info'] ?? [];

        $periodStart = $this->date($info['dateStart'] ?? null);
        $periodEnd = $this->date($info['dateEnd'] ?? null);
        if ($periodStart === null || $periodEnd === null) {
            throw new ImportException('FIO: chybí/neplatné období (info.dateStart/dateEnd).');
        }

        $transactions = [];
        $list = $stmt['transactionList']['transaction'] ?? [];
        if (is_array($list)) {
            foreach ($list as $t) {
                if (is_array($t)) {
                    $transactions[] = $this->parseTransaction($t);
                }
            }
        }

        // Odkaz na vlastní účet pro párování klíčem účtu (#118 D23): číslo
        // s kódem banky (`accountId/bankId`), jinak IBAN, jinak holé číslo
        // (to se napáruje jen na stejně holý záznam — nic se nehádá).
        $accountId = $this->s($info['accountId'] ?? null);
        $bankId = $this->s($info['bankId'] ?? null);
        $account = $accountId !== null && $bankId !== null
            ? "{$accountId}/{$bankId}"
            : ($this->s($info['iban'] ?? null) ?? $accountId);
        if ($account === null) {
            throw new ImportException('FIO: chybí číslo účtu (info.accountId).');
        }

        return [new ParsedStatement(
            bankAccountRef: $account,
            statementNumber: $this->s($info['idList'] ?? null) ?? $this->s($info['statementId'] ?? null),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: (float) ($info['openingBalance'] ?? 0),
            closingBalance: (float) ($info['closingBalance'] ?? 0),
            currency: $this->s($info['currency'] ?? null),
            transactions: $transactions,
        )];
    }

    /** @param array<string, mixed> $t */
    private function parseTransaction(array $t): ParsedTransaction
    {
        $date = $this->date($this->col($t, 'column0'));
        if ($date === null) {
            throw new ImportException('FIO: transakce bez data (column0).');
        }

        $accNumber = ltrim((string) ($this->col($t, 'column2') ?? ''), '0-');
        $bankCode = $this->col($t, 'column3');
        $counterparty = $accNumber !== '' ? $accNumber : null;
        if ($counterparty !== null && $bankCode !== null) {
            $counterparty .= '/' . $bankCode;
        }

        return new ParsedTransaction(
            externalId: $this->col($t, 'column22'),
            amount: ParserUtils::parseAmount((string) ($this->col($t, 'column1') ?? '0')),
            dateTransaction: $date,
            dateValue: null,
            counterpartyAccount: $counterparty,
            counterpartyName: $this->col($t, 'column10') ?? $this->col($t, 'column7'),
            paymentReference: ParserUtils::normalizeSymbol($this->col($t, 'column5')),
            specificSymbol: ParserUtils::normalizeSymbol($this->col($t, 'column6')),
            constantSymbol: ParserUtils::normalizeSymbol($this->col($t, 'column4')),
            message: ParserUtils::mergeMemo([
                $this->col($t, 'column16'),
                $this->col($t, 'column7'),
                $this->col($t, 'column8'),
                $this->col($t, 'column10'),
            ]),
            raw: $t,
        );
    }

    /** @param array<string, mixed> $t */
    private function col(array $t, string $key): ?string
    {
        if (!isset($t[$key]) || !is_array($t[$key]) || !array_key_exists('value', $t[$key])) {
            return null;
        }
        $v = $t[$key]['value'];
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function s(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
