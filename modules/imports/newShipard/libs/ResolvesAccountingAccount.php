<?php

namespace imports\newShipard\libs;

/**
 * Sdílená logika resolve účtu pro účtování: staré číslo účtu (string z
 * extension sloupce `debsAccountId`) → nový ndx `economy_accounting_accounts`.
 *
 * Řetězec převodu:
 *   debsAccountId (číslo, např. "221001")
 *     → starý ndx přes e10doc_debs_accounts.id
 *     → nový ndx přes LocalIdMap ENTITY_ACCOUNT (plní AccountsRunner).
 *
 * Použití: ItemsRunner (účet na položce → economy_items.accounting_account)
 * i BankAccountsRunner (účet na bankovním spojení →
 * economy_codebooks_bank_accounts.accounting_account).
 *
 * Prerekvizita: AccountsRunner musí proběhnout dřív, jinak LocalIdMap účet
 * nezná → warn + null (accounting_account zůstane NULL). V `all-codebooks` to
 * zajišťuje pořadí v AllCodebooksRunner::SEQUENCE; při samostatném běhu je to
 * best-effort.
 *
 * Vyžaduje hostitelskou třídu odvozenou z ImportRunner (db(), idMap(), warn()).
 */
trait ResolvesAccountingAccount
{
	/**
	 * Lazy cache: číslo účtu (e10doc_debs_accounts.id) → starý ndx.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $accountNdxByNumber = null;

	/**
	 * Číslo účtu (string z `debsAccountId`) → nový ndx
	 * economy_accounting_accounts, nebo null (prázdné / nenalezené číslo /
	 * AccountsRunner ještě neproběhl). `$context` jde do warn logu pro
	 * dohledatelnost (např. "item 123" / "bank-account 7").
	 */
	protected function resolveAccountingAccountNumber(string $number, string $context): ?int
	{
		$number = trim($number);
		if ($number === '')
			return null;

		$oldAccNdx = $this->accountNdxByNumber()[$number] ?? null;
		if ($oldAccNdx === null)
		{
			$this->warn("{$context}: ucet '{$number}' neni v e10doc_debs_accounts (nebo je smazany), accounting_account zustava NULL");
			return null;
		}

		$newAccNdx = $this->idMap()->lookup(LocalIdMap::ENTITY_ACCOUNT, $oldAccNdx);
		if ($newAccNdx === null)
		{
			$this->warn("{$context}: ucet '{$number}' (old ndx={$oldAccNdx}) neni v LocalIdMap, probehl AccountsRunner?");
			return null;
		}

		return $newAccNdx;
	}

	/**
	 * Lazy mapa: číslo účtu → starý ndx z e10doc_debs_accounts (bez smazaných).
	 * Záměrně bez JOINu v sourceQuery — případná duplicita čísla účtu by
	 * násobila řádky. Duplicity řeší first-wins (nižší ndx) + warning.
	 *
	 * @return array<string, int>
	 */
	private function accountNdxByNumber(): array
	{
		if ($this->accountNdxByNumber !== null)
			return $this->accountNdxByNumber;

		$this->accountNdxByNumber = [];

		$rows = $this->db()->query(
			'SELECT [ndx], [id] FROM [e10doc_debs_accounts]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		)->fetchAll();

		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$number = trim((string) ($row['id'] ?? ''));
			if ($number === '')
				continue;
			if (isset($this->accountNdxByNumber[$number]))
			{
				$this->warn("duplicate account number '{$number}' in e10doc_debs_accounts (using ndx={$this->accountNdxByNumber[$number]}, ignoring ndx={$row['ndx']})");
				continue;
			}
			$this->accountNdxByNumber[$number] = (int) $row['ndx'];
		}

		return $this->accountNdxByNumber;
	}
}
