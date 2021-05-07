<?php

namespace lib\E10Excel;

use e10\utils, e10\str, PhpOffice\PhpSpreadsheet\Cell\DataType;


/**
 * Class ContentRenderer
 * @package lib\E10Excel
 */
class ContentRenderer extends \e10\E10Object
{
	/** @var  \lib\E10Excel */
	var $excelEngine;
	var $spreadsheet;
	var $sheetNumber;

	var $cellY = 1;

	var $reportHeaderCnt = 0;
	var $reportHeader = NULL;

	public function setReportHeader ($reportHeader)
	{
		$this->reportHeader = $reportHeader;
	}

	public function openNewSheet ($sheetTitle = '')
	{
		$this->excelEngine->setAutoSize($this->spreadsheet);

		$this->sheetNumber++;
		$this->spreadsheet->createSheet($this->sheetNumber);
		$this->spreadsheet->setActiveSheetIndex($this->sheetNumber);
		$this->cellY = 1;

		if (!$sheetTitle && $sheetTitle !== '')
			$this->spreadsheet->getSheet($this->sheetNumber)->setTitle (str::upToLen(utils::safeChars($sheetTitle, TRUE), 31));
	}

	public function setSheetTitle ($cp)
	{
		if (isset($cp['params']['sheetTitle']))
		{
			$this->spreadsheet->getSheet($this->sheetNumber)->setTitle (str::upToLen(utils::safeChars($cp['params']['sheetTitle'], TRUE), 31));
		}
	}

	public function render($excelEngine, $spreadsheet, $sheetNumber, $content)
	{
		$this->excelEngine = $excelEngine;
		$this->spreadsheet = $spreadsheet;
		$this->sheetNumber = $sheetNumber;

		if ($this->reportHeader)
		{
			$this->renderReportHeader(['type' => 'reportHeader', 'reportHeader' => $this->reportHeader]);
		}

		$tableNumber = 0;
		foreach ($content as $cp)
		{
			$doOpen = FALSE;

			if (isset($cp['params']['newPage']) && $cp['params']['newPage'] === 1)
				$doOpen = TRUE;
			if (isset($cp['params']['newPage']) && $cp['params']['newPage'] === 2 && $tableNumber !== 0)
				$doOpen = TRUE;

			if ($doOpen)
				$this->openNewSheet ();

			$this->setSheetTitle($cp);

			if (isset($cp['table']))
			{
				$this->renderTable($cp);
				$tableNumber++;
			}
			elseif (isset($cp['reportHeader']))
			{
				$this->renderReportHeader($cp);
			}
		}

		$this->excelEngine->setAutoSize($this->spreadsheet);
		$this->spreadsheet->setActiveSheetIndex(0);
	}

	function renderTable ($cp)
	{
		if (isset ($cp['title']) && $cp['title'])
		{
			$title = $this->lineTextValue ($cp['title']);
			$this->excelEngine->putCell ($this->spreadsheet, $this->sheetNumber, 'A', $this->cellY, $title, FALSE, 'xls-table-title');
			$this->spreadsheet->getActiveSheet()->mergeCells('A'.$this->cellY.':F'.$this->cellY);
			$this->cellY++;
		}

		$table = $cp['table'];
		$header = $cp['header'];
		$params = isset($cp['params']) ? $cp['params'] : [];
		$this->cellY = $this->excelEngine->appendTable($this->spreadsheet, $this->sheetNumber, 'A', $this->cellY, $table, $header, $params);

		$this->cellY++;
	}

	function renderReportHeader ($cp)
	{
		if ($this->reportHeaderCnt)
		{
			$this->excelEngine->setAutoSize($this->spreadsheet);

			$this->sheetNumber++;
			$this->spreadsheet->createSheet($this->sheetNumber);
			$this->spreadsheet->setActiveSheetIndex($this->sheetNumber);
			$this->cellY = 1;
		}

		if (isset($cp['reportHeader']['worksheetTitle']))
			$this->spreadsheet->getSheet($this->sheetNumber)->setTitle (str::upToLen(utils::safeChars($cp['reportHeader']['worksheetTitle'], TRUE), 21));

		$title = $this->lineTextValue ($cp['reportHeader']['title']);
		$this->excelEngine->putCell ($this->spreadsheet, $this->sheetNumber, 'A', $this->cellY, $title, DataType::TYPE_STRING, 'xls-report-title');
		$this->spreadsheet->getActiveSheet()->mergeCells('A'.$this->cellY.':F'.$this->cellY);
		$this->cellY++;

		if (isset($cp['reportHeader']['param']))
		{
			foreach ($cp['reportHeader']['param'] as $paramName => $paramValue)
			{
				//$c .= "<span><b>" . utils::es($paramName) . ':</b> ' . utils::es($paramValue) . '</span> ';
//				$paramText = $paramName.': '.$paramValue;
//				$this->excelEngine->putCell ($this->spreadsheet, $this->sheetNumber, 'A', $this->cellY, $paramText, FALSE, '');
//				$this->spreadsheet->getActiveSheet()->mergeCells('A'.$this->cellY.':F'.$this->cellY);

				$objRichText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();

				$objParamName = $objRichText->createTextRun($paramName.': ');
				$objParamName->getFont()->setBold(true);

				$objRichText->createText ($paramValue);
				//$this->spreadsheet->getActiveSheet()->getCell('A'.$this->cellY)->setValue($objRichText);
				$this->excelEngine->putCell ($this->spreadsheet, $this->sheetNumber, 'A', $this->cellY, $objRichText, FALSE, '');
				$this->spreadsheet->getActiveSheet()->mergeCells('A'.$this->cellY.':F'.$this->cellY);

				//$this->excelEngine->putCell ($this->spreadsheet, $this->sheetNumber, 'C', $this->cellY, strval(), FALSE, '');
				//$this->spreadsheet->getActiveSheet()->mergeCells('C'.$this->cellY.':F'.$this->cellY);
				$this->cellY++;
			}
		}


		$this->cellY++;


		$this->reportHeaderCnt++;
	}

	function lineTextValue ($line)
	{
		$text = '';
		if (is_string($line))
			$text = $line;
		else
		{
			if (isset($line['text']))
				$text = $line['text'];
			elseif (isset($line[0]['text']))
				$text = $line[0]['text'];
		}

		return $text;
	}
}
