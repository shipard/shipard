<?php

namespace E10Doc\Bank\Import\cz_cspor_csv {

	require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

	use \E10\Application;


	class Import extends \E10Doc\Bank\ebankingImportDoc
	{
		private $rows;
		private $nextRowIdx = 0;
		private $colHeaders = array();

		public function unquote ($s)
		{
			$swlen = mb_strlen ($s, 'utf-8');
			return mb_substr ($s, 1, $swlen - 2, 'utf-8');
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

		protected function setColHeaders ()
		{
			$colRow = $this->getNextRow ();
			$colHeaders = explode (';', $colRow);

			$idx = 0;

			forEach ($colHeaders as $h)
				$this->colHeaders[$this->unquote($h)] = $idx++;
		}

		public function oneRowValue ($row, $key, &$value)
		{
			$pair = explode (';', $row);
			if ('"'.$key.'"' !== $pair[0])
				return FALSE;

			$value = $this->unquote ($pair[1]);
			return TRUE;
		}

		public function oneColValue ($cols, $colName)
		{
			if (isset ($this->colHeaders[$colName]))
			{
				$idx = $this->colHeaders[$colName];
				return $this->unquote($cols[$idx]);
			}
			return '';
		}

		public function import ()
		{
			$this->rows = explode ("\r\n", $this->textData);
			if (count($this->rows) === 1)
				$this->rows = explode ("\n", $this->textData);

			while (1)
			{
				$r = $this->getNextRow ();
				if ($r === '' || $r === FALSE)
					break;

				$v = '';

				if ($this->oneRowValue ($r, 'Číslo účtu', $v))
					$this->setHeadInfo ('bankAccount', $v);
				else
				if ($this->oneRowValue ($r, 'Číslo výpisu', $v))
					$this->setHeadInfo ('docOrderNumber', intval($v));
				else
				if ($this->oneRowValue ($r, 'Počáteční zůstatek', $v))
					$this->setHeadInfo ('initBalance', $this->parseNumber ($v));
				else
				if ($this->oneRowValue ($r, 'Konečný zůstatek', $v))
					$this->setHeadInfo ('balance', $this->parseNumber ($v));
				else
				if ($this->oneRowValue ($r, 'Datum výpisu', $v))
				{
					$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($v));
					$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($v));
				}
			}

			$this->setColHeaders();

			while (1)
			{
				$r = $this->getNextRow ();
				if ($r === '')
					continue;
				if ($r === FALSE)
					break;

				$cols = explode (';', $r);

				$this->setRowInfo ('dateDue', $this->parseDate ($this->oneColValue ($cols, 'Datum splatnosti')));
				$this->setRowInfo ('money', $this->parseNumber ($this->oneColValue ($cols, 'Obrat')));
				$this->setRowInfo ('bankAccount', $this->oneColValue ($cols, 'Číslo protiúčtu'));

				$this->setRowInfo ('symbol1', $this->oneColValue ($cols, 'Var.symb.1'), '0');
				$this->setRowInfo ('symbol2', $this->oneColValue ($cols, 'Spec.symb.'), '0');
				$this->setRowInfo ('symbol3', $this->oneColValue ($cols, 'Konst.symb.'), '0');

				$this->setRowInfo ('memo', $this->oneColValue ($cols, 'Informace k platbě'));
				$this->setRowInfo ('memo', $this->oneColValue ($cols, 'Reference platby'));
				$this->setRowInfo ('memo', $this->oneColValue ($cols, 'Kód příkazce'));
				$this->setRowInfo ('memo', $this->oneColValue ($cols, 'Kód příjemce'));
				$this->setRowInfo ('memo', $this->oneColValue ($cols, 'Položka'));

				$this->appendRow();
			}
		} // function import

		public function parseDate ($value)
		{
			return date_create_from_format ('Y/m/d', substr ($value, 0, 10));
		}

	} // class Import

} // namespace

