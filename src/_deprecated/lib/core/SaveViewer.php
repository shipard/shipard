<?php

namespace lib\core;


use \e10\utils, \e10\uiutils, \e10\Utility, \Shipard\Report\TemplateMustache;


/**
 * Class SaveViewer
 * @package lib\core
 */
class SaveViewer extends Utility
{
	CONST ftInvalid = 0, ftPdf = 1, ftCsv = 2, ftXls = 3;
	static $formats = [
			'pdf' => ['srcFileSuffix' => 'html', 'destFileSuffix' => 'pdf', 'type' => self::ftPdf],
			'csv' => ['srcFileSuffix' => 'csv', 'destFileSuffix' => 'csv', 'type' => self::ftCsv],
			'xls' => ['srcFileSuffix' => 'xlsx', 'destFileSuffix' => 'xlsx', 'type' => self::ftXls],
	];

	/** @var \e10\TableView */
	var $viewer = NULL;
	var $format = self::ftInvalid;
	var $formatDef = NULL;

	protected $buffer = '';

	var $srcFullFileName;
	var $finalFullFileName;

	var $excelEngine;
	var $excelSpreadsheet;

	var $rowNumber = 0;


	public function setViewer ($viewer)
	{
		$this->viewer = $viewer;
		$this->formatDef = isset(self::$formats[$this->viewer->saveAs]) ? self::$formats[$this->viewer->saveAs] : NULL;
		if ($this->formatDef)
			$this->format = $this->formatDef['type'];
	}

	public function init ()
	{
		$srcFileSuffix = '.'.$this->formatDef['srcFileSuffix'];
		$destFileSuffix = '.'.$this->formatDef['destFileSuffix'];

		$baseFileName = "v-{$this->format}-" . time() . '-' . mt_rand (1000000, 9999999);
		$this->srcFullFileName = __APP_DIR__ . "/tmp/".$baseFileName.$srcFileSuffix;
		$this->finalFullFileName = __APP_DIR__ . "/tmp/".$baseFileName.$destFileSuffix;

		$this->viewer->objectData ['fileUrl'] = '/tmp/'.$baseFileName.$destFileSuffix;
	}

	public function saveViewerData ()
	{
		if ($this->format == self::ftInvalid)
			return;

		$this->init();

		$this->viewer->rowsPageSize = 1000;
		$this->viewer->rowsPageNumber = 0;
		$this->viewer->rowsFirst = 0;

		$this->viewer->init ();
		$this->viewer->selectRows ();

		$this->viewer->gridTableRenderer = new \Shipard\Utils\TableRenderer(NULL, $this->viewer->gridStruct, ['tableClass' => 'e10-vd-mainTable']);
		$this->viewer->gridTableRenderer->init ();

		$this->open();

		while (1)
		{
			$this->viewer->rowsLoadNext = 0;
			$this->viewer->lineRowNumber = 0;
			foreach ($this->viewer->queryRows as $item)
			{
				if ($this->viewer->lineRowNumber == $this->viewer->rowsPageSize)
				{
					$this->viewer->rowsLoadNext = 1;
					break;
				}

				$this->viewer->lineRowNumber++;
				$this->viewer->docState = $this->viewer->table->getDocumentState ($item);
				$listItem = $this->viewer->saveRow ((array)$item);

				if ($this->viewer->docState)
				{
					$docStateClass = $this->viewer->table->getDocumentStateInfo ($this->viewer->docState ['states'], $item, 'styleClass');
					if ($docStateClass)
						$listItem ['class'] = $docStateClass;
				}

				$this->viewer->addListItem ($listItem);
			}

			$this->viewer->selectRows2 ();

			unset ($item);
			$this->viewer->lineRowNumber = 0;

			forEach ($this->viewer->objectData ['dataItems'] as &$item)
			{
				if (!isset ($item ['groupName']))
					$this->viewer->lineRowNumber++;

				$this->viewer->decorateRow ($item);
				$this->appendRow($item);


			}

			if (!$this->viewer->rowsLoadNext)
			{
				if ($this->format === self::ftPdf)
				{
					$sumRow = $this->viewer->sumRow(NULL);
					if ($sumRow)
					{
						$this->buffer .= $this->viewer->gridTableRenderer->renderRow($sumRow, 0, 'td', TRUE);
					}
				}
			}

			$this->flushBuffer();

			if (!$this->viewer->rowsLoadNext)
				break;

			$this->viewer->lineRowNumber = 0;
			$this->viewer->rowsPageNumber++;
			$this->viewer->rowsFirst += $this->viewer->rowsPageSize;
			unset ($this->viewer->queryRows);
			unset ($this->viewer->objectData ['dataItems']);
			$this->viewer->objectData ['dataItems'] = [];

			$this->viewer->selectRows ();
		}

		$this->close();
		$this->createFinalFile();

		return $this->finalFullFileName;
	}

	public function appendRow ($item)
	{
		if ($this->format === self::ftPdf)
		{
			$sumRow = $this->viewer->sumRow($item);
			if ($sumRow)
			{
				$this->buffer .= $this->viewer->gridTableRenderer->renderRow($sumRow, 0, 'td', TRUE);
			}
		}

		$this->rowNumber++;
		switch ($this->format)
		{
			case self::ftPdf: $this->buffer .= $this->viewer->gridTableRenderer->renderRow ($item, 0, 'td'); break;
			case self::ftCsv: $this->appendRow_CSV($item); break;
			case self::ftXls: $this->appendRow_XLS($item); break;
		}
	}

	public function appendRow_CSV ($item)
	{
		$colSep = ";";
		$lineSep = "\n";
		$c = '';

		foreach ($this->viewer->gridStruct as $cn => $ch)
		{
			$cv = isset($item[$cn]) ? $item[$cn] : '';

			if ($c !== '')
				$c .= $colSep;
			if ($cv instanceof \DateTimeInterface)
				$ct = $cv->format ('Y-m-d');
			else if (is_double ($cv))
				$ct = '"'.str_replace('.', ',', strval($cv)).'"';
			elseif (is_string($cv))
				$ct = '"'.str_replace('"', '""', $cv).'"';
			elseif (is_array($cv))
				$ct = '"'.str_replace('"', '""', $cv['text']).'"';
			else
				$ct = $cv;

			$c .= $ct;

		}
		$c .= $lineSep;

		$this->buffer .= $c;
	}

	public function appendRow_XLS ($item)
	{
		$row = [];
		foreach ($this->viewer->gridStruct as $cn => $ch)
		{
			if (!isset($item[$cn]))
				continue;

			$row[] = $item[$cn];
		}

		$this->excelEngine->putTable ($this->excelSpreadsheet, 0, 'A', $this->rowNumber, [$row]);
	}

	protected function flushBuffer()
	{
		file_put_contents($this->srcFullFileName, $this->buffer, FILE_APPEND);
		$this->buffer = '';
	}

	public function open()
	{
		switch ($this->format)
		{
			case self::ftPdf: $this->open_PDF(); break;
			case self::ftCsv: $this->open_CSV(); break;
			case self::ftXls: $this->open_XLS(); break;
		}
	}

	public function open_CSV ()
	{
		$BOM = chr(0xEF).chr(0xBB).chr(0xBF);
		file_put_contents($this->srcFullFileName, $BOM);

		/* TODO: add header?
		foreach ($this->viewer->gridStruct as $cn => $ch)
		{
			if ($this->buffer !== '')
				$this->buffer .= ';';
			$t = $ch;
			if (in_array($ch[0], [' ', '_', '+']))
				$t = substr($ch, 1);
			$this->buffer .= '"'.$t.'"';
		}

		$this->buffer .= "\n";
		*/
	}

	public function open_PDF ()
	{
		$t = new TemplateMustache ($this->app());
		if ($t->loadTemplate('reports.default.globalReport.default') !== FALSE)
		{
			$c = $t->renderTemplate ();
		}
		$c .= "<div class='e10-reportContent'>";
		$c .= uiutils::createReportContentHeader ($this->app, $this->viewer->info);
		$c .= $this->viewer->gridTableRenderer->renderHeader();
		$c .= "\n";
		file_put_contents($this->srcFullFileName, $c);

		return $c;
	}

	public function open_XLS ()
	{
		$this->excelEngine = new \lib\E10Excel ($this->app);
		$this->excelSpreadsheet = $this->excelEngine->create();
	}

	public function close ()
	{
		switch ($this->format)
		{
			case self::ftPdf: $this->close_PDF(); break;
			case self::ftCsv: $this->close_CSV(); break;
			case self::ftXls: $this->close_XLS(); break;
		}
	}

	public function close_CSV()
	{
	}

	public function close_XLS()
	{
		$this->excelEngine->save ($this->excelSpreadsheet, $this->finalFullFileName);
	}

	public function close_PDF()
	{
		$footerCode = $this->viewer->gridTableRenderer->renderFooter();
		$footerCode .= '</div></body></html>';
		file_put_contents($this->srcFullFileName, $footerCode, FILE_APPEND);
	}

	public function createFinalFile()
	{
		switch ($this->format)
		{
			case self::ftPdf: $this->createFinalFile_PDF(); break;
			case self::ftCsv: break;
			case self::ftXls: break;
		}
	}

	public function createFinalFile_PDF()
	{
		exec ('phantomjs '.__APP_DIR__ . '/e10-modules/e10/server/utils/createpdf.js '.$this->srcFullFileName.' '.
				$this->finalFullFileName.' '.'A4'.' '.'landscape'.' '.'1cm');
	}
}
