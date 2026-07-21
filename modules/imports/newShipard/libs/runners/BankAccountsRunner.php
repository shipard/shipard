<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\ImportException;
use imports\newShipard\libs\LocalIdMap;
use imports\newShipard\libs\ResolvesAccountingAccount;

final class BankAccountsRunner extends BaseCodebookRunner
{
	use ResolvesAccountingAccount;

	private const CODE_MAX_LEN = 10;

	/** @var array<int, string> old ndx → finální (deduplikovaný) code */
	private array $codeByNdx = [];

	protected function entityType(): string  { return LocalIdMap::ENTITY_BANK_ACCOUNT; }
	protected function targetTable(): string { return 'economy_codebooks_bank_accounts'; }
	protected function entityLabel(): string { return 'bank-account'; }

	/**
	 * LEFT JOIN na persons pro bank_name — starý schema má FK na osobu (banku),
	 * nový má jen text. Persons ještě nejsou importovaní, takže nepoužíváme
	 * LocalIdMap, jen vezmeme fullName ze starého DS jako stringovou hodnotu.
	 */
	protected function sourceQuery(): array
	{
		return [
			'SELECT ba.[ndx], ba.[id], ba.[fullName], ba.[bankAccount],'
			. ' ba.[iban], ba.[swift], ba.[currency], ba.[order], ba.[docState],'
			. ' ba.[debsAccountId],'
			. ' p.[fullName] AS bankFullName'
			. ' FROM [e10doc_base_bankaccounts] ba'
			. ' LEFT JOIN [e10_persons_persons] p ON ba.[bank] = p.[ndx]'
			. ' WHERE ba.[docState] != %i', 9800,
			' ORDER BY ba.[ndx]',
		];
	}

	/**
	 * Starý sloupec `id` není unikátní (lefreal má duplicity i mezi aktivními
	 * účty), nová strana má na `code` unique constraint. Prepass nad celou
	 * zdrojovou sadou: první výskyt kódu (nejnižší ndx, řádky chodí ORDER BY
	 * ndx) si ho nechá, každý další dostane deterministický suffix
	 * `{code}-{ndx}`. Odvození závisí jen na zdrojových datech → idempotentní.
	 */
	protected function fetchSourceRows(): array
	{
		$rows = parent::fetchSourceRows();

		$used = [];
		foreach ($rows as $row)
		{
			$ndx = (int) $row['ndx'];
			$code = $this->deriveCode($row['id'] ?? null, $ndx, 'BA', self::CODE_MAX_LEN);

			if (!isset($used[$code]))
			{
				$used[$code] = $ndx;
				$this->codeByNdx[$ndx] = $code;
				continue;
			}

			$suffix = '-' . $ndx;
			$candidate = mb_substr($code, 0, self::CODE_MAX_LEN - mb_strlen($suffix)) . $suffix;
			if (isset($used[$candidate]))
				throw new ImportException(
					"bank-account {$ndx}: deduplicated code '{$candidate}' still collides"
					. " (code '{$code}' first used by ndx=" . $used[$code] . ")");

			$this->warn("bank-account {$ndx}: duplicate code '{$code}'"
				. " (first used by ndx=" . $used[$code] . ") → renamed to '{$candidate}'");
			$used[$candidate] = $ndx;
			$this->codeByNdx[$ndx] = $candidate;
		}

		return $rows;
	}

	protected function mapRow(array $oldRow): array
	{
		$currency = strtolower(trim((string) ($oldRow['currency'] ?? '')));
		if ($currency === '')
			$currency = 'czk';

		$swift = trim((string) ($oldRow['swift'] ?? ''));
		if (mb_strlen($swift) > 11)
			$swift = mb_substr($swift, 0, 11);

		$bankName = trim((string) ($oldRow['bankFullName'] ?? ''));

		$payload = [
			'code'           => $this->codeByNdx[(int) $oldRow['ndx']],
			'name'           => (string) ($oldRow['fullName'] ?? ''),
			'notice'         => null,
			'bank_name'      => $bankName !== '' ? $bankName : null,
			'account_number' => trim((string) ($oldRow['bankAccount'] ?? '')) ?: null,
			'iban'           => trim((string) ($oldRow['iban'] ?? '')) ?: null,
			'bic'            => $swift !== '' ? $swift : null,
			'currency'       => $currency,
			'is_default'     => 0,
			'valid_from'     => null,
			'valid_to'       => null,
			'sort_order'     => (int) ($oldRow['order'] ?? 0),
		];

		// accounting_account (extension z economy.bank) — protiúčet 221xxx pro
		// bankovní pohyby. Starý `debsAccountId` (číslo účtu) → nový ndx přes
		// LocalIdMap ENTITY_ACCOUNT. Prerekvizita: AccountsRunner běží dřív
		// (AllCodebooksRunner::SEQUENCE); jinak warn + NULL. Server validuje,
		// že jde o aktivní analytický účet v řadě 221.
		$account = $this->resolveAccountingAccountNumber(
			(string) ($oldRow['debsAccountId'] ?? ''),
			'bank-account ' . (int) $oldRow['ndx'],
		);
		if ($account !== null)
			$payload['accounting_account'] = $account;

		return $payload;
	}
}
