<?php

namespace lib\ebanking\parsers;

use e10\Utility, e10\utils, e10\str;


/**
 * Class mt940
 * @package lib\ebanking\parsers
 */
class mt940 extends Utility
{
	protected $activeStatement = NULL;
	protected $activeTransaction = NULL;
	protected $definition = NULL;

	var $statements = [];

	protected function initDefinition()
	{
		$this->definition = [
			'name' => 'ČSOB',
			'dirKinds' => ['D' => 'D', 'C' => 'C', 'RC' => 'C', 'RD' => 'D'],
			'transactionTypes' => [
				'111' => [
						'name' => 'Tuzemsko', 'fields' => [
								'00' => ['id' => 'memo'],
								'20' => ['id' => 'accountImported'],
								'21' => ['id' => 'symbol1Imported', 'prefix' => 'VS:'],
								'22' => ['id' => 'symbol2Imported', 'prefix' => 'SS:'],
								'23' => ['id' => 'symbol3Imported', 'prefix' => 'KS:'],
								'24' => ['id' => 'memo', 'blank' => '.'],
								'25' => ['id' => 'memo', 'blank' => '.'],
								'26' => ['id' => 'memo', 'blank' => '.'],
								'27' => ['id' => 'memo', 'blank' => '.'],
						]
				],
				'030' => [
						'name' => 'Zahraničí', 'fields' => [
								'00' => ['id' => 'memo'],
								'30' => ['id' => 'accountBankCode', 'blank' => '.'],
								'31' => ['id' => 'accountImported'],
								'33' => ['id' => 'memo', 'blank' => '.'],
								'20' => ['id' => 'memo', 'blank' => '.'],
								'21' => ['id' => 'memo', 'blank' => '.'],
								'22' => ['id' => 'memo', 'blank' => '.'],
								'23' => ['id' => 'memo', 'blank' => '.'],
								'24' => ['id' => 'memo', 'blank' => '.'],
						]
				],
				'040' => [
						'name' => 'Ostatní transakce', 'fields' => [
								'00' => ['id' => 'memo'],
								'20' => ['id' => 'symbol1Imported', 'prefix' => 'VS:'],
								'25' => ['id' => 'symbol2Imported', 'prefix' => 'SS:'],
								'26' => ['id' => 'symbol3Imported', 'prefix' => 'KS:'],
								'21' => ['id' => 'memo', 'blank' => '.'],
								'22' => ['id' => 'memo', 'blank' => '.'],
								'23' => ['id' => 'memo', 'blank' => '.'],
								'24' => ['id' => 'memo', 'blank' => '.'],
						]
				]
			]
		];
	}

	public function parse($fileName)
	{
		$text = file_get_contents($fileName);
		if (!$text)
			return;

		$this->parseText($text);
	}

	public function parseText($text)
	{
		$this->initDefinition();

		$blocks = [];
		preg_match_all('/\{.+?\}/si', $text, $blocks);

		foreach ($blocks[0] as $block)
		{
			$b = str_replace(["\r", "\n"], '', $block);
			if (substr($b, 0, 2) === '{4')
				$b = substr($b, 3, -2);
			else
				$b = ':' . substr($b, 1, -1);

			$parts = preg_split('/:[0-9,F,C,M]{1,3}:/', $b, -1, PREG_SPLIT_OFFSET_CAPTURE);

			foreach ($parts as $p)
			{
				if (!isset($p[0]) || !$p[0])
					continue;

				$t = $p[0];
				$o = $p[1];

				$id = '';
				$o -= 2;
				while ($o >= 0 && $b[$o] !== ':')
				{
					$id = $b[$o] . $id;
					$o--;
				}

				if ($id === '1')
					$this->newStatement($t);
				elseif ($id === '61')
					$this->parseTransaction($t);
				elseif ($id === '86')
					$this->parseTransactionDetails($t);
				elseif ($id === '60F')
					$this->parseBalanceBegin($t);
				elseif ($id === '62F')
					$this->parseBalanceEnd($t);
				elseif ($id === '28C')
					$this->parseStatementNumber($t);
				elseif ($id === '25')
					$this->parseMyAccount($t);
			}
		}
		if ($this->activeTransaction)
			$this->appendActiveTransaction();

		if ($this->activeStatement)
			$this->appendActiveStatement();
	}

	protected function parseTransaction($tag)
	{
		if ($this->activeTransaction)
			$this->appendActiveTransaction();

		$this->activeTransaction = ['symbol1' => '', 'symbol2' => '', 'symbol3' => '', 'memos' => []];

		$dateStr = substr($tag, 0, 6);
		$this->activeTransaction['date'] = $this->parseDate($dateStr);
		$this->activeTransaction['dateStr'] = $this->activeTransaction['date']->format ('Y-m-d');

		$dateStrAcc = substr($tag, 4, 4);
		$this->activeTransaction['dateAcc'] = $this->parseDate($dateStrAcc);

		$pos = 10;

		$dirKind = '';
		while (!is_numeric($tag[$pos]))
		{
			$dirKind .= $tag[$pos];
			$pos++;
		}
		$this->activeTransaction['dirKindOriginal'] = $dirKind;
		if (isset ($this->definition['dirKinds'][$dirKind]))
			$this->activeTransaction['dirKind'] = $this->definition['dirKinds'][$dirKind];

		/*
		$currencyId = '';
		while (!is_numeric($tag[$pos]))
		{ // currency
			$currencyId .= $tag[$pos];
			$pos++;
		}
		*/

		$amountStr = '';
		while (is_numeric($tag[$pos]) || $tag[$pos] === ',')
		{ // currency
			$amountStr .= $tag[$pos];
			$pos++;
		}

		if ($amountStr !== '')
			$this->activeTransaction['amount'] = floatval(str_replace(',', '.', $amountStr));

		switch ($dirKind)
		{
			case 'RC':
			case 'D': $this->activeStatement['sumDebitCalc'] += $this->activeTransaction['amount']; break;
			case 'RD':
			case 'C': $this->activeStatement['sumCreditCalc'] += $this->activeTransaction['amount']; break;
		}
	}

	protected function parseTransactionDetails($tag)
	{
		$parts = explode('?', $tag);

		$pos = 0;

		$id = '';
		$tt = [];

		while (isset($parts[$pos]))
		{
			$key = substr($parts[$pos], 0, 2);
			if ($pos === 0)
			{
				$id = $parts[$pos];
				if (isset($this->definition['transactionTypes'][$id]))
					$tt = $this->definition['transactionTypes'][$id];
			}

			if (!isset($tt['fields'][$key]))
			{
				$pos++;
				continue;
			}

			$field = $tt['fields'][$key];

			$value = substr($parts[$pos], 2);
			if (isset ($field['prefix']))
				$value = substr($value, strlen($field['prefix']));

			if (isset ($field['blank']) && $value === $field['blank'])
				$value = '';

			if ($field['id'] === 'memo')
			{
				if ($value !== '')
					$this->activeTransaction['memos'][] = $value;
			} else
				$this->activeTransaction[$field['id']] = $value;

			$pos++;
		}

		$this->activeTransaction['symbol1'] = '';
		if ($this->activeTransaction['symbol1Imported'])
			$this->activeTransaction['symbol1'] = ltrim ($this->activeTransaction['symbol1Imported'], ' 0');
		$this->activeTransaction['symbol2'] = '';
		if ($this->activeTransaction['symbol2Imported'])
			$this->activeTransaction['symbol2'] = ltrim ($this->activeTransaction['symbol2Imported'], ' 0');

		$this->activeTransaction['account'] = '';
		if (isset($this->activeTransaction['accountImported']))
		{
			$this->activeTransaction['account'] = ltrim($this->activeTransaction['accountImported'], ' 0-');
		}

		if (!isset($this->activeTransaction['accountBankCode']))
		{
			$this->activeTransaction['accountBankCode'] = '';
			if (isset($this->activeTransaction['account']))
			{
				$ap = explode ('/', $this->activeTransaction['account']);
				if (isset($ap[1]))
					$this->activeTransaction['accountBankCode'] = $ap[1];
			}
		}
	}

	protected function parseStatementNumber($tag)
	{
		$parts = explode ('/', $tag);
		if (!isset($this->activeStatement['statementNumber']) && isset($parts[0]))
			$this->activeStatement['statementNumber'] = $parts[0];
	}

	protected function parseBalanceBegin($tag)
	{
		$dateStr = substr($tag, 1, 6);
		$date = $this->parseDate($dateStr);
		$this->activeStatement['balanceBeginDate'] = $date;
		$currency = substr($tag, 7, 3);
		$this->activeStatement['balanceBeginCurrency'] = strtolower($currency);
		$amountStr = substr($tag, 10);
		$this->activeStatement['balanceBeginAmount'] = strtolower(floatval(str_replace(',', '.', $amountStr)));
	}

	protected function parseBalanceEnd($tag)
	{
		$dateStr = substr($tag, 1, 6);
		$date = $this->parseDate($dateStr);
		$this->activeStatement['balanceEndDate'] = $date;
		$currency = substr($tag, 7, 3);
		$this->activeStatement['balanceEndCurrency'] = strtolower($currency);
		$amountStr = substr($tag, 10);
		$this->activeStatement['balanceEndAmount'] = strtolower(floatval(str_replace(',', '.', $amountStr)));
	}

	protected function parseMyAccount($tag)
	{
		$parts = explode ('/', $tag);
		$bankCode = $parts[0];
		$bankAccountNumber = $parts[1];
		$bankAccount = $bankAccountNumber.'/'.$bankCode;
		$this->activeStatement['myBankAccount'] = $bankAccount;
		$this->activeStatement['myBankAccountBankCode'] = $bankCode;
		$this->activeStatement['myBankAccountNumber'] = $bankAccountNumber;
	}

	protected function newStatement($tag)
	{
		if ($this->activeStatement)
		{

		}

		$this->activeStatement = ['transactions' => [], 'sumCreditCalc' => 0.0, 'sumDebitCalc' => 0.0];
	}

	private function appendActiveTransaction()
	{
		$this->activeStatement['transactions'][] = $this->activeTransaction;
	}

	private function appendActiveStatement()
	{
		if (!isset($this->activeStatement['date']) && isset($this->activeStatement['balanceBeginDate']))
			$this->activeStatement['date'] = $this->activeStatement['balanceBeginDate'];
		if (!isset($this->activeStatement['currency']) && isset($this->activeStatement['balanceBeginCurrency']))
			$this->activeStatement['currency'] = $this->activeStatement['balanceBeginCurrency'];

		if (!isset($this->activeStatement['sumDebit']) && isset($this->activeStatement['sumDebitCalc']))
			$this->activeStatement['sumDebit'] = $this->activeStatement['sumDebitCalc'];
		if (!isset($this->activeStatement['sumCredit']) && isset($this->activeStatement['sumCreditCalc']))
			$this->activeStatement['sumCredit'] = $this->activeStatement['sumCreditCalc'];

		$this->activeStatement['dateStr'] = $this->activeStatement['date']->format ('Y-m-d');

		$this->statements[] = $this->activeStatement;
	}

	private function parseDate($str)
	{
		if (strlen($str) === 6)
		{
			$date = utils::createDateTime('20'.substr($str, 0, 2).'-'.substr($str, 2, 2).'-'.substr($str, 4, 2));
			return $date;
		}

		if (strlen($str) === 4)
		{
			$date = utils::createDateTime($this->activeTransaction['date']->format('Y').'-'.substr($str, 2, 2).'-'.substr($str, 0, 2));
			return $date;
		}

		return NULL;
	}
}
