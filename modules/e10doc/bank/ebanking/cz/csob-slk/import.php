<?php

namespace E10Doc\Bank\Import\cz_csob_slk {

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

/**
 * fileImportSLK
 *
 */

class fileImportSLK extends \E10\Utility
{
	var $rawText = '';
	var $data = array();

	protected function setCellValue ($cellPosX, $cellPosY, $cellValue)
	{
		$x = strval($cellPosX);
		$y = strval($cellPosY);
		if ($cellValue[1] === '"')
			$this->data[$y][$x] = substr ($cellValue, 2, -1);
		else
			$this->data[$y][$x] = substr ($cellValue, 1);
	}

	public function setText ($text)
	{
		$this->rawText = $text;
	}

	public function run ()
	{
		$rows = explode("\r\n", $this->rawText);
		$cellPosX = 0;
		$cellPosY = 0;
		foreach ($rows as $row)
		{
			//error_log ("IMPORT ROW: ".json_encode($row));
			$cols = explode(';', $row);
			if ($cols[0] === 'ID' || $cols[0] === 'P')
				continue;
			if ($cols[0] === 'E')
				break;
			foreach ($cols as $c)
			{
				if ($c[0] === 'X')
					$cellPosX = intval (substr($c, 1));
				else
				if ($c[0] === 'Y')
					$cellPosY = intval (substr($c, 1));
				else
				if ($c[0] === 'K')
					$this->setCellValue ($cellPosX, $cellPosY, $c);
			}
		}
	}
}


/**
 * Import
 *
 */

class Import extends \E10Doc\Bank\ebankingImportDoc
{
	private $rows;
	private $nextRowIdx = 0;
	private $colHeaders = array();
	private $accountNumber = '';

	public function import ()
	{
		$slk = new fileImportSLK ($this->app);
		$slk->setText ($this->textData);
		$slk->run ();

		// -- detect account number
		$numbers = array();
		if (preg_match("/[0123456789]+/", $slk->data['2']['1'], $numbers) === 1)
			$this->accountNumber = $numbers[0];

		$openedRows = 0;
		$lastDate = '';

		$rows = array_reverse($slk->data, TRUE);
		forEach ($rows as $rowNumber => $r)
		{
			if (intval($rowNumber) <= 4)
				continue;

			$rowDate = $r['1'];

			if ($rowDate != $lastDate)
			{
				if ($openedRows !== 0)
				{
					$this->saveDoc ();
					$this->clear();
					$openedRows = 0;
				}
				$this->importHead($r);
			}

			$this->importRow($r);
			$openedRows++;
			$lastDate = $rowDate;
		}
	} // function import

	public function importHead ($r)
	{
		$bankAccount = $this->accountNumber.'/0300';
		$this->setHeadInfo ('bankAccount', $bankAccount);

		$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($r[1]));
		$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($r[1]));

		$initState = $r['4'] - $r['2'];
		$this->setHeadInfo ('initBalance', $initState);
	}

	public function importRow ($r)
	{
		$this->setRowInfo ('bankAccount', $r['10']);
		$this->setRowInfo ('money', $r['2']);

		$symbol1 = ltrim ($r['6'], ' 0');
		$this->setRowInfo ('symbol1', $symbol1);

		$symbol2 = ltrim ($r['7'], ' 0');
		$this->setRowInfo ('symbol2', $symbol2);

		$symbol3 = ltrim ($r['5'], ' 0');
		$this->setRowInfo ('symbol3', $symbol3);

		$this->setRowInfo ('memo', trim ($r['9']));
		$this->setRowInfo ('memo', trim ($r['11']));

		$this->setRowInfo ('dateDue', $this->parseDate ($r['1']));

		$this->appendRow();
	}

} // class Import

} // namespace

