<?php

namespace lib\E10Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType, PhpOffice\PhpSpreadsheet\Style\NumberFormat;


/**
 * Class TableRenderer
 * @package lib\E10Excel
 */
class TableRenderer
{
	protected $columns;
	protected $data;
	protected $params;
	protected $header;

	protected $colClasses = array();
	protected $colTitles = array();
	protected $sums = array();

	protected $lineNumber = 1;
	protected $precision = 2;
	protected $disableZeros = 0;
	protected $formatMaskDecimals = '';

	private $rowSpan;

	/** @var  \lib\E10Excel */
	var $excelEngine;
	var $spreadsheet;
	var $sheetNumber;
	var $sheet;
	var $cellX;
	var $cellY;
	var $firstColumn;

	
	public function __construct ($data, $columns, $params)
	{
		$this->columns = $columns;
		$this->data = $data;
		$this->params = $params;

		if (isset($params['header']))
			$this->header = $params['header'];

		if (isset($params['disableZeros']))
			$this->disableZeros = intval($params['disableZeros']);
	}

	public function init ()
	{
		foreach ($this->columns as $cn => $ch)
		{
			$this->colClasses [$cn] = '';

			if (!is_string($ch))
			{
				$this->colTitles [$cn] = $ch;
				continue;
			}

			if ($ch === '_')
				continue;
			if ($ch === '') {}
			else
				if ($ch [0] === '+')
				{
					$this->colTitles [$cn] = substr ($ch, 1);
					$this->sums [$cn] = 0;
					$this->colClasses [$cn] = 'number';
				}
				else if ($ch [0] === ' ')
				{
					$this->colTitles [$cn] = substr ($ch, 1);
					$this->colClasses [$cn] = 'number';
				}
				else if ($ch [0] === '_')
				{
					$this->colTitles [$cn] = substr ($ch, 1);
					$this->colClasses [$cn] = 'nowrap';
				}
				else if ($ch [0] === '|')
				{
					$this->colTitles [$cn] = substr ($ch, 1);
					$this->colClasses [$cn] = 'center';
				}
				else if ($cn === '#')
				{
					$this->colTitles [$cn] = $ch;
					$this->colClasses [$cn] = 'number';
				}
				else
					$this->colTitles [$cn] = $ch;
		}

		if (!isset ($this->header))
		{
			$this->header = array ();
			$this->header[]= $this->colTitles;
		}

		if (isset ($this->params ['precision']))
			$this->precision = intval($this->params ['precision']);

		$this->formatMaskDecimals = '#0';
		if ($this->precision !== 0)
			$this->formatMaskDecimals = '#,'.str_repeat('#', $this->precision).'0.'.str_repeat('#', $this->precision);
	}

	protected function formatMoney ($money, $disableZeros)
	{
		if ($disableZeros && !$money)
			return '';

		if (isset ($this->params ['resultFormat']))
		{
			switch($this->params['resultFormat'])
			{
				case	'1000': return \E10\nf ($money, 0);
			}
		}
		return utils::nf ($money, $this->precision);
	}

	protected function renderRows ($rows, $tablePart, $td)
	{
		$this->rowSpan = [];
		foreach ($rows as $r)
			$this->renderRow($r, $tablePart, $td);
	}

	public function renderRow ($r, $tablePart, $td)
	{
		$cntColumns = count($this->columns);
		$this->cellX = ord($this->firstColumn);


		if (isset ($r ['_options']) && isset ($r ['_options']['beforeSeparator']))
			$this->cellY++;

			//$c .= "<tr class='{$r ['_options']['beforeSeparator']}'><td colspan='$cntColumns'></td></tr>";*/
		$rowClasses = '';
		if ($td === 'th')
			$rowClasses = 'xls-table-header';
		if (isset ($r ['_options']) && isset ($r ['_options']['class']))
			$rowClasses = $r['_options']['class'];

		$rowCells = chr($this->cellX).$this->cellY.':'.chr($this->cellX + $cntColumns - 1).$this->cellY;
		if ($rowClasses !== '')
			$this->excelEngine->formatCell($this->spreadsheet, $this->sheetNumber, $rowCells, $rowClasses);

		$disableZeros = $this->disableZeros;
		if (isset ($r ['_options']) && isset ($r ['_options']['disableZeros']))
			$disableZeros = $r ['_options']['disableZeros'];

		//$cntCols = count ($r);
		$colSpan = 0;
		foreach ($this->columns as $cn => $ch)
		{
			if ($cn === '_options')
				continue;
			$cid = 0;
			if ($colSpan)
			{
				$colSpan--;
				$cid = 1;
			}

			if (isset ($this->rowSpan[$cn]) && $this->rowSpan[$cn])
			{
				$this->rowSpan[$cn]--;
				$cid = 1;
			}

			$colSpdNumber = $this->cellX - 65; // 1 --> A, 26 --> Z, 27 --> AA, 28 --> AB, ...
			$colSpdLetters = ($colSpdNumber < 26) ? chr($colSpdNumber+65) : chr(intval($colSpdNumber/26)+64).chr(intval($colSpdNumber%26)+65);
			$cellId = $colSpdLetters.$this->cellY;

			if ($cn == '#')
			{
				if (!isset ($r ['_options']['class']) ||
						($r ['_options']['class'] != 'subheader' && $r ['_options']['class'] != 'subtotal' && (substr($r ['_options']['class'], 0, 3) != 'sum')))
					$cv = ($tablePart === 0) ? $this->lineNumber.'.' : $ch;
				else
					$cv = isset($r[$cn]) ? $r[$cn] :'';
			}
			else if ($ch == '_')
				$cv = "<input type='checkbox' value='{$r[$cn]}'>";
			else
				$cv = isset ($r[$cn]) ? $r[$cn] : '';

			if ($cv instanceof \DateTime)
			{
				$this->sheet->setCellValue($cellId, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($cv));
				$this->sheet->getStyle($cellId)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

				//$ct = $cv->format('d.m.Y');
			}
			else if (is_double ($cv) || is_int ($cv))
			{
				if ($tablePart === 0 && isset ($this->sums [$cn]))
					$this->sums [$cn] += $cv;
				if (is_int ($cv))
				{
					//$ct = nf($cv, 0);
					$this->sheet->setCellValueExplicit($cellId, $cv, DataType::TYPE_NUMERIC);
				}
				else
				{
					//$ct = $this->formatMoney($cv, $disableZeros);
					$this->sheet->setCellValueExplicit($cellId, $cv, DataType::TYPE_NUMERIC);
					$this->sheet->getStyle($cellId)->getNumberFormat()->setFormatCode($this->formatMaskDecimals);
				}
			}
			else if (is_array($cv))
			{
				if (isset($cv['text']))
					$this->sheet->setCellValueExplicit($cellId, $cv['text'], DataType::TYPE_STRING);
				elseif (isset($cv[0]['text']))
				{
					$txts = [];
					foreach ($cv as $cvt)
						$txts[] = $cvt['text'];
					$this->sheet->setCellValueExplicit($cellId, implode (', ', $txts), DataType::TYPE_STRING);
				}
			}
			else
			{
				$ct = strval($cv);
				$this->sheet->setCellValueExplicit($cellId, $ct, DataType::TYPE_STRING);
			}

			//if (isset($r ['_options']['cellExtension'][$cn]))
			//	$ct .= '<div>'.$this->app()->ui()->composeTextLine ($r ['_options']['cellExtension'][$cn], '').'</div>';

			$cellClasses = $this->colClasses[$cn];
			if ($td === 'th' && isset($this->params['header']))
				$cellClasses = '';
			if (isset ($r ['_options']) && isset ($r ['_options']['cellClasses'][$cn]))
			{
				if (count($this->header) > 1)
					$cellClasses = ' '.$r ['_options']['cellClasses'][$cn];
				else $cellClasses .= ' '.$r ['_options']['cellClasses'][$cn];
			}
			$ccp = '';
			//if ($cellClasses !== '')
			//	$ccp = " class='$cellClasses'";

			if ($cellClasses !== '')
				$this->excelEngine->formatCell($this->spreadsheet, $this->sheetNumber, $cellId, $cellClasses);

			if (isset ($r ['_options']) && isset ($r ['_options']['colSpan'][$cn]))
			{
				$colSpan = $r ['_options']['colSpan'][$cn];
				//$ccp .= " colspan='$colSpan'";
				$this->spreadsheet->getActiveSheet()->mergeCells(chr($this->cellX).$this->cellY.':'.chr($this->cellX + $colSpan - 1).$this->cellY);
				$colSpan--;
			}

			if (isset ($r ['_options']) && isset ($r ['_options']['rowSpan'][$cn]))
			{
				$this->rowSpan[$cn] = $r ['_options']['rowSpan'][$cn];
				//$ccp .= " rowspan='{$this->rowSpan[$cn]}'";
				$this->rowSpan[$cn]--;
			}

			//if (isset ($r ['_options']) && isset ($r ['_options']['cellTitles'][$cn]))
			//	$ccp .= " title=\"".utils::es($r ['_options']['cellTitles'][$cn])."\"";

			//if (!$cid)
			//	$c .= "<$td$ccp>$ct</$td>";

			$this->cellX++;
		}
		if ($tablePart === 0 &&
				(!isset ($r ['_options']['class']) ||
						($r ['_options']['class'] != 'subheader' && $r ['_options']['class'] != 'subtotal' && $r ['_options']['class'] != 'sumtotal' && $r ['_options']['class'] != 'sum')))
			$this->lineNumber++;
		//$c .= "</tr>";

		if (isset ($r ['_options']) && isset ($r ['_options']['afterSeparator']))
			$this->cellY++;

		$this->cellY++;
	}

	public function renderHeader ()
	{
		$tableClass = 'default fullWidth';
		if (isset ($this->params ['forceTableClass']))
			$tableClass = $this->params ['forceTableClass'];
		else
		if (isset ($this->params ['tableClass']))
			$tableClass .= ' ' . $this->params ['tableClass'];

		//$c = "<table class='$tableClass'>";

		$showHeader = !isset ($this->params['hideHeader']);
		if ($showHeader)
		{
			$this->renderRows($this->header, 1, 'th');
		}
	}

	public function renderFooter ()
	{
		if (count ($this->sums))
		{
			$this->cellX = ord($this->firstColumn);
			$cntColumns = count($this->columns);

			$rowClasses = 'sumtotal';
			$rowCells = chr($this->cellX).$this->cellY.':'.chr($this->cellX + $cntColumns - 1).$this->cellY;
			if ($rowClasses !== '')
				$this->excelEngine->formatCell($this->spreadsheet, $this->sheetNumber, $rowCells, $rowClasses);

			foreach ($this->columns as $cn => $ch)
			{
				$cellId = chr($this->cellX).$this->cellY;
				if (isset ($this->sums [$cn]))
				{
					if (is_int ($this->sums [$cn]))
					{
						$this->sheet->setCellValueExplicit($cellId, $this->sums [$cn], DataType::TYPE_NUMERIC);
					}
					else
					{
						$this->sheet->setCellValueExplicit($cellId, $this->sums [$cn], DataType::TYPE_NUMERIC);
						$this->sheet->getStyle($cellId)->getNumberFormat()->setFormatCode('#,##0.00');
					}
				}
				$this->cellX++;
			}
		}
		$this->cellY++;
	}

	public function render ($excelEngine, $spreadsheet, $sheetNumber, $firstColumn, $firstRow)
	{
		if (!isset($this->columns))
			return;

		$this->excelEngine = $excelEngine;
		$this->spreadsheet = $spreadsheet;
		$this->sheetNumber = $sheetNumber;
		$this->sheet = $spreadsheet->getSheet($sheetNumber);
		$this->cellY = $firstRow;
		$this->firstColumn = $firstColumn;

		$this->init ();

		$this->renderHeader();
		$this->renderRows($this->data, 0, 'td');
		$this->renderFooter();
	}
}

