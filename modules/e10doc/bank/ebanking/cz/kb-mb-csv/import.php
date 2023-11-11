<?php

namespace E10Doc\Bank\Import\cz_kb_mb_csv;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


class Import extends \E10Doc\Bank\ebankingImportDoc
{
	var $colIdDateDue = 0;
	var $colIdBankAccount = 2;
	var $colIdAmount = 4;
	var $colIdSymbol1 = 8;
	var $colIdSymbol2 = 10;
	var $colIdSymbol3 = 9;
	var $colIdFirstMemo = 11;

	public function import ()
	{
		$rows = explode ("\r\n", $this->textData);
		forEach ($rows as $r)
		{
			$cells = explode (';', $r);
			array_walk($cells, [$this, 'cleanQuotes']);

			// -- detect header info
			switch ($cells [0])
			{
				case 'Cislo uctu':
								$cu = explode (' ', $cells [1]);
								$this->setHeadInfo ('bankAccount', $cu[0].'/0100');
								continue;
				case 'Cislo vypisu':
								$this->setHeadInfo ('docOrderNumber', $cells [1]); continue;
				case 'Vypis za obdobi':
								$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($cells [1]));
								$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($cells [1]));
								continue;
				case 'Pocatecni zustatek':
								$this->setHeadInfo ('initBalance', $this->parseNumber ($cells [1])); continue;
				case 'Konecny zustatek':
								$this->setHeadInfo ('balance', $this->parseNumber ($cells [1])); continue;
			}

			if (count($cells) < 15)
				continue;

			if ($cells[0] === 'Datum splatnosti')
			{
				if ($cells[1] === 'Datum zuctovani')
				{
					$this->colIdBankAccount = 3;
					$this->colIdAmount = 5;
					$this->colIdSymbol1 = 9;
					$this->colIdSymbol2 = 11;
					$this->colIdSymbol3 = 10;
					$this->colIdFirstMemo = 12;
				}
				continue;
			}

			// -- add new row
			$this->setRowInfo ('dateDue', $this->parseDate ($cells [$this->colIdDateDue]));
			$this->setRowInfo ('bankAccount', $cells [$this->colIdBankAccount]);
			$this->setRowInfo ('money', $this->parseNumber ($cells [$this->colIdAmount]));

			$this->setRowInfo ('symbol1', $cells [$this->colIdSymbol1]);
			if ($cells [$this->colIdSymbol2] === '0') // prázdný SS je znak nula (0)
				$cells [$this->colIdSymbol2] = '';
			$this->setRowInfo ('symbol2', $cells [$this->colIdSymbol2]);
			$this->setRowInfo ('symbol3', $cells [$this->colIdSymbol3]);

			for ($mid = $this->colIdFirstMemo; $mid < $this->colIdFirstMemo + 8; $mid++)
			{
				$cells [$mid] = trim ($cells [$mid]);
				if (isset ($cells [$mid]) && $cells [$mid] != '')
					$this->setRowInfo ('memo', $cells [$mid]);
			}

			$this->appendRow();
		}
	} // function import

	private function cleanQuotes(&$value)
	{
		if (strlen($value) == 0)
			return;
		if (!is_string($value))
			return;
		if ($value[0] == '"')
			$value = substr($value, 1);
		if ($value[strlen($value)-1] == '"')
			$value = substr($value, 0, -1);
	} // cleanQuotes

} // class Import

