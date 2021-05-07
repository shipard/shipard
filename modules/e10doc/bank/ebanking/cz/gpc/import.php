<?php

namespace E10Doc\Bank\Import\cz_gpc {

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


class Import extends \E10Doc\Bank\ebankingImportDoc
{
	/**
	 * @param string $n
	 *
	 * Cislice:  A B C D  E F G H I J
	 * Vahy:     6 3 7 9 10 5 8 4 2 1
	 * n:       10 9 8 7  6 5 4 3 2 1
	 *
	 * n je pozice číslice v čísle účtu (počítáno zprava)
	 * @url http://www.cnb.cz/cs/platebni_styk/pravni_predpisy/download/vyhl_169_2011.pdf
	 *
	 */
	protected function mod11 ($n)
	{
		$factor = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
		$len = strlen ($n);
		$sum = 0;
		for ($i = 0; $i < $len; $i++)
		{
			$fp = 10 - $len + $i;
			$sum += intval($n[$i]) * $factor[$fp];
		}
		return ($sum % 11 == 0);
	}

	/**
	 * @param $str
	 * @return string
	 *
	 * Vnitřní formát čísla účtu je vytvářen permutací dle následujícího principu:
	 * Px-předčíslí, pozice x.
	 * Cx-Číslo učtu, pozice x.
	 * Číslo účtu:     P1P2P3P4P5P6C1C2C3C4C5C6C7C8C9C0
	 * Vnitřní formát: C0C8C9C6C1C2C3C4C5C7P1P2P3P4P5P6
	 *
	 * @url http://www.csas.cz/static_internet/cs/Obchodni_informace-Produkty/Prime_bankovnictvi/Spolecne/Prilohy/ABO_format.pdf
	 * @url http://www.mojebanka.cz/file/cs/bdsk_format_abo_sk.pdf
	 *
	 */
	public function getAccountNumber ($str)
	{
		$bankAccountNumber = ltrim ($this->substr ($str, 6, 10), ' 0');
		$bankAccountPrefix = ltrim ($this->substr ($str, 0, 6), ' 0');

		if (!$this->mod11($bankAccountNumber) || !$this->mod11($bankAccountPrefix))
		{
			$ap = $this->substr ($str, 10, 6);
			$an =
				$this->substr($str, 4, 5).	// 1,2,3,4,5
				$this->substr($str, 3, 1).	// 6
				$this->substr($str, 9, 1).	// 7
				$this->substr($str, 1, 1).	// 8
				$this->substr($str, 2, 1).	// 9
				$this->substr($str, 0, 1);	// 10,
			$bankAccountNumber = ltrim ($an, ' 0');
			$bankAccountPrefix = ltrim ($ap, ' 0');
		}

		$bankAccount = '';
		if ($bankAccountPrefix != '')
			$bankAccount = $bankAccountPrefix . '-';
		$bankAccount .= $bankAccountNumber;
		return $bankAccount;
	}

	public function importHead ($r)
	{
		$bankAccount = $this->getAccountNumber($this->substr ($r, 3, 16));

		$this->setHeadInfo ('bankAccount', $bankAccount);

		$initState = intval ($this->substr ($r, 59, 1).$this->substr ($r, 45, 14)) / 100;
		$this->setHeadInfo ('initBalance', $initState);

		$docOrderNumber = intval ($this->substr ($r, 105, 3));
		$this->setHeadInfo ('docOrderNumber', $docOrderNumber);

		$datePeriodBegin = $this->substr ($r, 39, 6);
		$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($datePeriodBegin));

		$datePeriodEnd = $this->substr ($r, 108, 6);
		$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($datePeriodEnd));
	}

	public function importRow ($r)
	{
		$bankId = substr ($r, 73, 4);
		$bankAccount = $this->getAccountNumber($this->substr ($r, 19, 16));

		if ($bankAccount != '')
			$bankAccount .= '/'.$bankId;

		$this->setRowInfo ('bankAccount', $bankAccount);

		$money = intval (substr ($r, 48, 12)) / 100;
		$moneyType = substr ($r, 60, 1);
		switch ($moneyType)
		{
			case '1':
			case '5':
						$money *= -1;
						break;
		}
		$this->setRowInfo ('money', $money);

		$symbol1 = ltrim (substr ($r, 61, 10), ' 0');
		$this->setRowInfo ('symbol1', $symbol1);
		$symbol2 = ltrim (substr ($r, 81, 10), ' 0');
		$this->setRowInfo ('symbol2', $symbol2);
		$symbol3 = ltrim (substr ($r, 77, 4), ' 0');
		$this->setRowInfo ('symbol3', $symbol3);

		$this->setRowInfo ('memo', trim (mb_substr ($r, 97, 20, 'utf-8')));

		$dateDue = mb_substr ($r, 122, 6, 'utf-8');
		$this->setRowInfo ('dateDue', $this->parseDate ($dateDue));

		$this->appendRow();
	}

	public function import ()
	{
		$rows = preg_split ('/\R/', $this->textData);
		$openedRows = 0;

		forEach ($rows as $r)
		{
			$rowType = substr($r, 0, 3);

			if ($rowType === '074')
			{
				if ($openedRows !== 0)
				{
					$this->saveDoc ();
					$this->clear();
					$openedRows = 0;
				}
				$this->importHead($r);
			}
			elseif ($rowType === '075')
			{
				$this->importRow($r);
				$openedRows++;
			}
			elseif ($rowType === '078' || $rowType === '079')
			{
				$lastRowNdx = count($this->importedRows) - 1;
				$this->importedRows[$lastRowNdx]['memo'][] = mb_substr($r, 3, null, 'utf-8');
			}
		}
	} // function import

	public function parseDate ($value)
	{
		return date_create_from_format ('dmy', $value);
	}
} // class Import


} // namespace E10Doc\Bank\Import\cz_gpc

