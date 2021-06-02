<?php

namespace E10Doc\Bank\Import\cz_rb_email {

require_once __SHPD_MODULES_ROOT__.'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


class Import extends \E10Doc\Bank\ebankingImportDoc
{
	private $rows;
	private $nextRowIdx = 0;

	public function parseNumber ($value)
	{
		$s = str_replace(' ', '', $value);
		return floatval($s);
	}

	public function oneRowValue ($row, $startWith, &$value, $minCol = 0)
	{
		$swlen = mb_strlen ($startWith, 'utf-8');
		if (mb_substr ($row, 0, $swlen, 'utf-8') != $startWith)
			return FALSE;

		$alllen = mb_strlen ($row, 'utf-8');
		if ($minCol === 0)
		{
			$buf = mb_substr ($row, $swlen, $alllen - $swlen, 'utf-8');
			$value = trim ($buf);
			return TRUE;
		}

		if ($minCol !== 0 && $minCol < $alllen)
		{
			$buf = mb_substr ($row, $minCol, $alllen - $minCol, 'utf-8');
			$value = trim ($buf);
			return TRUE;
		}

		return FALSE;
	}

	public function getNextRow ()
	{
		if (isset ($this->rows[$this->nextRowIdx]))
		{
			$this->nextRowIdx++;
			return $this->rows[$this->nextRowIdx-1];
		}

		return FALSE;
	}

	public function getNextBlock ()
	{
		$b = array();
		while (1)
		{
			$r = $this->getNextRow ();
			if ($r === FALSE)
				return FALSE;
			if (trim($r) === '' && count ($b) === 0)
				return FALSE;

			if ($r === '--------------------------------------------------------------------------------------')
				break;

			$b[] = $r;
		}

		if (!count($b))
			return FALSE;
		return $b;
	}


	public function import ()
	{
		$this->rows = explode ("\r\n", $this->textData);
		if (count($this->rows) === 1)
				$this->rows = explode ("\n", $this->textData);

		$thisYear = '2000';

		// -- header
		$cntDivs = 0;
		while (1)
		{
			$r = $this->getNextRow ();
			if ($r === FALSE)
				break;

			if ($r === '======================================================================================')
			{
				$cntDivs++;
				if ($cntDivs === 5)
					break;
				continue;
			}

			$v = '';

			if ($this->oneRowValue ($r, 'Číslo účtu:', $v))
				$this->setHeadInfo ('bankAccount', $v);
			else
			if ($this->oneRowValue ($r, 'Bankovní výpis č.', $v))
				$this->setHeadInfo ('docOrderNumber', intval($v));
			else
			if ($this->oneRowValue ($r, 'Počáteční zůstatek', $v))
				$this->setHeadInfo ('initBalance', $this->parseNumber ($v));
			else
			if ($this->oneRowValue ($r, 'Konečný zůstatek', $v))
				$this->setHeadInfo ('balance', $this->parseNumber ($v));
			else
			if ($this->oneRowValue ($r, 'Za období', $v))
			{
				$dd = explode ('/', $v);
				$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($dd [0]));
				$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($dd [1]));

				$thisYear = $this->parseDate ($dd [0])->format('Y');
			}
			else
			if ($this->oneRowValue ($r, 'za ', $v))
			{
				$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($v));
				$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($v));

				$thisYear = $this->parseDate ($v)->format('Y');
			}
		}

		// -- rows
		$dailyFees = 0;
		while (1)
		{
			$b = $this->getNextBlock();
			if ($b === FALSE)
				break;

			$this->setRowInfo ('dateDue', $this->parseDate ($this->substr ($b[0], 5, 6).$thisYear));

			$this->setRowInfo ('money', $this->parseNumber ($this->substr ($b[0], 55, 25)));
			$thisRowFee = $this->parseNumber ($this->substr ($b[0], 81));
			if ($thisRowFee)
				$dailyFees += $thisRowFee;

			if (trim($b[1]) !== '') // (poslední?) blok s poplatky
				$this->setRowInfo ('bankAccount', $this->substr ($b[2], 11, 22));
			if (mb_substr ($this->importRow ['bankAccount'], 0, 18, 'utf-8') === 'Přímé bankovnictví')
				$this->setRowInfo ('bankAccount', '');

			$this->setRowInfo ('symbol1', $this->substr ($b[1], 44, 10));
			$this->setRowInfo ('symbol2', $this->substr ($b[0], 44, 10));
			$this->setRowInfo ('symbol3', $this->substr ($b[2], 44, 10));

			if (isset ($b[3]))
				$this->setRowInfo ('memo', trim($b [3]));

			$this->setRowInfo ('memo', $this->substr ($b[1], 11, 20));
			$this->setRowInfo ('memo', $this->substr ($b[0], 11, 20));
			$this->setRowInfo ('memo', $this->substr ($b[2], 55));

			$this->appendRow();
		}

		if ($dailyFees)
		{
			$this->setRowInfo ('dateDue', $this->importHead ['datePeriodEnd']);
			$this->setRowInfo ('money', $dailyFees);
			$this->setRowInfo ('memo', 'Poplatky');
			$this->appendRow();
		}
	} // function import
} // class Import


} // namespace E10Doc\Bank\Import\cz_rb_email
