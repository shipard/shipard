<?php

namespace E10Doc\Bank\Import\cz_kb_mb_csv {

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


class Import extends \E10Doc\Bank\ebankingImportDoc
{
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
				continue;

			// -- add new row
			$this->setRowInfo ('dateDue', $this->parseDate ($cells [0]));
			$this->setRowInfo ('bankAccount', $cells [2]);
			$this->setRowInfo ('money', $this->parseNumber ($cells [4]));

			$this->setRowInfo ('symbol1', $cells [8]);
			if ($cells [10] === '0') // prázdný SS je znak nula (0)
				$cells [10] = '';
			$this->setRowInfo ('symbol2', $cells [10]);
			$this->setRowInfo ('symbol3', $cells [9]);

			for ($mid = 11; $mid < 19; $mid++)
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


} // namespace E10Doc\Bank\Import\cz_kb_mb_csv
