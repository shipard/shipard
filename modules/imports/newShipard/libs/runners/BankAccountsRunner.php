<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;
use imports\newShipard\libs\ResolvesAccountingAccount;

final class BankAccountsRunner extends BaseCodebookRunner
{
	use ResolvesAccountingAccount;

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
			'code'           => $this->deriveCode($oldRow['id'] ?? null, (int) $oldRow['ndx'], 'BA'),
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
