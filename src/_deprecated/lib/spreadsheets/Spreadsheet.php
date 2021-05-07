<?php

namespace lib\spreadsheets;

use \e10\Utility, \lib\spreadsheets\SpreadsheetCellFunc, \e10\utils;


/**
 * Class Spreadsheet
 * @package lib\spreadsheets
 */
class Spreadsheet extends Utility
{
	var $spreadsheetId;

	protected $spd;
	var $app;
	var $contentData = [];
	var $otherSpreadsheets = [];
	var $content = [];
	protected $params = [];
	var $testNotes = [];

	var $subColumns;
	var $subColumnsData = [];
	var $renderAsSubColumns = FALSE;
	var $idxTable;
	var $idxRow;
	var $subColumnsSrcPrefix = '';

	var $renderAsExplain = FALSE;
	var $dataExplain = [];

	public function setParam ($paramKey, $paramValue)
	{
		$this->params[$paramKey] = $paramValue;
	}

	public function param ($paramKey = FALSE)
	{
		if ($paramKey === FALSE)
			return $this->params;
		if (isset ($this->params[$paramKey]))
			return $this->params[$paramKey];
		return FALSE;
	}

	public function calc ()
	{
	}

	public function calcAll ()
	{
		$steps = 0;
		while (TRUE)
		{
			$failed = 0;
			forEach ($this->contentData as $partId => $part)
			{
				$idxTable = 0;
				forEach ($this->contentData[$partId]['tables'] as &$table)
				{
					$idxRow = 0;
					forEach ($table ['rows'] as &$row)
					{
						$colNumber = 0;
						forEach ($row as &$cell)
						{
							$scColId = 'C_'.$idxTable.'_'.$idxRow.'_'.$colNumber;
							if (is_string($cell) && substr($cell, 0, 2) === '=[')
							{
								$res = $this->evalCell($cell, $table);
								if ($res !== FALSE)
								{
									$cell = $res['result'];
									$this->dataExplain[$scColId] = $res['explain'];
								}
								else
									$failed++;
							}
							$colNumber++;
						}
						$idxRow++;
					}
					$idxTable++;
				}
			}
			if ($failed === 0)
				break;
			$steps++;
			if ($steps > 5)
				break;
		}
	}

	public function content ()
	{
		return $this->content;
	}

	public function createContent ()
	{
		$this->calcAll ();

		$partId = 'this';
		$this->idxTable = 0;
		forEach ($this->contentData[$partId]['tables'] as $tbl)
		{
			$this->renderTable($tbl);
			$this->idxTable++;
		}
	}

	protected function evalCell ($cellStr, $table)
	{
		$cellFormula = mb_substr($cellStr, 2, -1, 'utf-8');
		$resultFormat = FALSE;

		$res = ['result' => 0.0, 'explain' => []];
		$parts = explode (' ', $cellFormula);
		$listItems = 0;
		forEach ($parts as $fp)
		{
			$func = $fp;
			$thisRes = FALSE;

			if ($func[0] === '{')
				$thisRes = $this->evalCellFunc ($func, $table);
			else
			{
				$thisRes = $this->evalCellSumListItem($func, $table);
				$listItems = 1;
			}

			if ($thisRes === FALSE)
				return FALSE;

			$res['result'] += $thisRes['result'];
			$res['explain'] = array_merge ($res['explain'], $thisRes['explain']);
		}

		if ($listItems)
		{
			$resultFormat = $this->param('resultFormat');
			if ($resultFormat === '1000')
			{
				$res['originalResult'] = $res['result'];
				$res['result'] = intval(round($res['result'] / 1000));
			}
		}

		$explainItem = ['value' => $res['result'], 'text' => 'Σ', '_options' => ['class' => 'sum']];
		if (isset($res['originalResult']))
		{
			$explainItem['value'] = ['text' => utils::nf($res['result']), 'prefix' => utils::nf ($res['originalResult'], 2).' ≐'];
		}

		$res['explain'][] = $explainItem;

		return $res;
	}

	protected function evalCellFunc ($funcCode, $table)
	{
		$cellFunc = new SpreadsheetCellFunc ();
		$cellFunc->setFunction($funcCode);

		$res = FALSE;

		switch ($cellFunc->name())
		{
			case 'SUM':	$res = $this->funcSUM ($cellFunc, $table); break;
		}

		return $res;
	}

	protected function evalCellSumListItem ($cell, $table)
	{
		return FALSE;
	}

	protected function formatMoney ($money)
	{
		switch($this->param('resultFormat'))
		{
			case	'1000': return \E10\nf ($money, 0);
		}
		return \E10\nf ($money, 2);
	}

	protected function renderTable ($table)
	{
		$rt = ['table' => [], 'header' => [],
			'params' => [
				'header' => [], 'tableClass' => ($this->renderAsSubColumns) ? 'e10-dataSet-table' : 'e10-print-small'
			]
		];

		if (isset($table['newPage']))
			$rt['params']['newPage'] = 1;

		if (isset($table['sheetTitle']))
			$rt['params']['sheetTitle'] = $table['sheetTitle'];

		if ($this->param('resultFormat') == '1000')
			$rt['params']['precision'] = 0;

		if (isset($table['disableZeros']))
			$rt['params']['disableZeros'] = 1;

		if (isset($table['fixedHeader']))
			$rt['params']['fixedHeader'] = 1;

		forEach ($table ['columns'] as $colNumber => $colDef)
		{
			$colId = 'C'.$colNumber;

			if (isset($colDef['format']) && /*in_array($colDef['format'], ['money'])*/$colDef['format'] === 'money')
				$rt['header'][$colId] = ' '.$colDef['title'];
			else
				$rt['header'][$colId] = $colDef['title'];
		}

		$this->renderTableHeader ($table, $rt);

		$this->idxRow = 0;
		forEach ($table ['rows'] as $rowIndex => $row)
		{
			$rowNumber = $rowIndex + 1;
			if (isset ($table['firstRowNumber']))
				$rowNumber += $table['firstRowNumber'] - 1;
			$rr = [];
			$this->renderTableRow ($table, $row, 0, $rr, $rowNumber);
			$rt['table'][] = $rr;
			$this->idxRow++;
		}

		$this->content[] = $rt;
	}

	protected function renderTableRow ($table, $row, $tablePart, &$rr, $rowNumber = FALSE)
	{
		$columnIdx = 0;
		$needColSpan = 0;
		forEach ($row as $colNumber => $cell)
		{
			$colId = 'C'.$colNumber;

			if ($needColSpan > 0)
			{
				$needColSpan--;
				continue;
			}

			$colSpan = 0;
			$class = '';

			if (is_array($cell))
			{
				$cellValue = isset($cell['value']) ? $cell['value'] : '';
				if (isset ($cell['colspan']))
					$colSpan = $cell['colspan'];
				if (isset ($cell['class']))
					$class = $cell['class'].' ';
			}
			else
				$cellValue = $cell;

			if ($tablePart === 0)
			{
				$scColId = 'C_'.$this->idxTable.'_'.$this->idxRow.'_'.$colNumber;
				$autoEval = isset ($this->contentData['this']['tables'][$this->idxTable]['columns'][$colNumber]['autoEval']) ? 1 : 0;
				if (is_array($cell))
					$autoEval = 0;

				if ($autoEval && $this->renderAsSubColumns)
				{
					$rr[$colId] = ['scInput' => $scColId];
				}
				elseif ($autoEval && $this->renderAsExplain)
				{
					if (isset($this->dataExplain[$scColId]))
					{
						$rr[$colId] = [
							'table' => $this->dataExplain[$scColId], 'header' => ['text' => 't', 'value' => ' v'],
							'params' => ['hideHeader' => 1, 'forceTableClass' => 'subTable fullWidth']
						];
						$rr['_options']['cellClasses']['C'.$colNumber] = 'subTable';
					}
					else
						$rr[$colId] = $cellValue;
				}
				else
					$rr[$colId] = $cellValue;

				if ($autoEval && !$this->renderAsSubColumns)
					$this->subColumnsData[$scColId] = $cellValue;
			}
			else
				$rr[$colId] = $cellValue;

			if ($colSpan !== 0)
			{
				$rr['_options']['colSpan'][$colId] = $colSpan;
				$needColSpan = $colSpan - 1;
			}

			if ($rowNumber !== FALSE && isset ($table['cellClasses']) && isset($table ['cellClasses'][$rowNumber]))
			{
				foreach ($table ['cellClasses'][$rowNumber] as $cellId => $cellClass)
				{
					if ($cellId === 'row')
					{
						$rr['_options']['class'] = $cellClass;
					}
					else
					{
						$ccId = 'C' . (ord($cellId) - ord('A'));
						if (isset($rr['_options']['cellClasses'][$ccId]))
							$rr['_options']['cellClasses'][$ccId] .= ' '.$cellClass;
						else
							$rr['_options']['cellClasses'][$ccId] = $cellClass;
					}
				}
			}

			if ($tablePart === 0 && isset ($table['columns'][$columnIdx]))
				$colDef = $table['columns'][$columnIdx];

			if (isset ($colDef['class']))
				$class .= $colDef['class'];

			if ($class !== '')
			{
				if (isset($rr['_options']['cellClasses'][$colId]))
					$rr['_options']['cellClasses'][$colId] .= ' '.$class;
				else
					$rr['_options']['cellClasses'][$colId] = $class;
			}

			$columnIdx++;
			unset ($colDef);
		}
	}

	protected function renderTableHeader ($table, &$dst)
	{
		if (isset ($table ['header']))
		{
			forEach ($table ['header'] as $row)
			{
				$rr = [];
				$this->renderTableRow ($table, $row, 2, $rr);
				$dst['params']['header'][] = $rr;
			}
		}
	}

	public function searchTable ($partId, $tableId)
	{
		forEach ($this->contentData[$partId]['tables'] as $table)
		{
			if (!isset ($table['tableId']))
				continue;
			if ($table['tableId'] === $tableId)
				return $table;
			if ($tableId === '')
				return $table;
		}
		return FALSE;
	}

	public function loadFromFile ($reportId)
	{
		// 'e10doc.debs.spdBalanceSheetFull'
		$parts = explode ('.', $reportId);
		$shortId = array_pop ($parts);

		$replace = $this->app->db->query ("SELECT * FROM [e10_base_templates] WHERE [replaceId] = %s", $reportId)->fetch ();
		if ($replace)
		{
			$fullFileName = __APP_DIR__ . '/templates/'.$replace['sn'].'/'.$shortId.'.json';
		}
		else
		{
			$coreFileName = __SHPD_MODULES_DIR__.implode ('/', $parts).'/spreadsheets/'.$shortId;
			$fullFileName = $coreFileName.'.json';
		}

		$this->spd = $this->loadCfgFile($fullFileName);

		$this->initPattern ();
	}

	function loadOtherSpreadsheets()
	{
	}

	protected function initPattern ()
	{
		$this->initSpdSettings();

		$this->contentData = array();
		forEach ($this->spd['pattern']['tables'] as $pt)
		{
			$this->contentData['this']['tables'][] = $pt;
		}

		forEach ($this->contentData as $partId => $part)
		{
			$tableIdx = 0;
			forEach ($this->contentData[$partId]['tables'] as &$table)
			{
				if (!isset ($table['columns']))
					continue;

				$rowIdx = 0;
				forEach ($table ['rows'] as &$row)
				{
					$colIdx = 0;
					forEach ($row as &$cell)
					{
						$autoEval = isset ($table['columns'][$colIdx]['autoEval']) ? 1 : 0;

						if (is_string($cell) && $autoEval)
						{
							if (substr ($cell, 0, 2) !== '=[')
								$cell = $this->initCellListItemsPattern ($tableIdx, $rowIdx, $colIdx, $cell);

							$colId = 'C_'.$tableIdx.'_'.$rowIdx.'_'.$colIdx;
							$scDef = ['name' => $colId, 'id' => $colId, 'type' => 'long'];
							if ($this->subColumnsSrcPrefix !== '')
								$scDef['src'] = $this->subColumnsSrcPrefix.'.'.$colId;

							$this->subColumns['columns'][] = $scDef;
						}
						$colIdx++;
					}
					$rowIdx++;
				}
				$tableIdx++;
			}
		}
	}

	protected function initCellListItemsPattern ($tableIdx, $rowIdx, $colIdx, $cellPattern)
	{
		if ($cellPattern !== '')
			return '=['.$cellPattern.']';

		return '';
	}

	protected function initSpdSettings ()
	{
	}

	protected function funcSUM (SpreadsheetCellFunc $f, $table)
	{
		$res = ['result' => 0.0, 'explain' => []];

		forEach ($f->param() as $cellId => $none)
		{
			$cellValue = $this->cellValue($cellId, $table);
			if ($cellValue === FALSE)
				return FALSE;
			$res['result'] += $cellValue['result'];
			$res['explain'] = array_merge($res['explain'], $cellValue['explain']);
		}

		return $res;
	}

	protected function cellValue ($cellIdX, $table)
	{
		$cellId = strval($cellIdX);
		$spd = $this;
		$otherSrcCellId = '';

		$res = ['result' => 0.0, 'explain' => []];
		if (strpos($cellId, ':') !== FALSE)
		{
			$x = explode (':', $cellId);
			$os = explode ('!', $x[0]);
			if (count($os) === 2)
			{
				if (!isset($this->otherSpreadsheets[$os[0]]))
				{
					$res['explain'][] = ['text' => $cellId, 'value' => ''];
					return $res;
				}
				$spd = $this->otherSpreadsheets[$os[0]];
				$table = $spd->searchTable('this', $os[1]);
				$cellId = $x[1];
				$otherSrcCellId = $os[0];
				if ($os[1] !== '')
					$otherSrcCellId .= ':'.$os[1];
			}
			else
			{
				$table = $this->searchTable('this', $x[0]);
				$cellId = $x[1];
				$otherSrcCellId = $x[0];
			}
		}

		if ($otherSrcCellId !== '')
			$otherSrcCellId .= '!';

		$cids = 0;
		if ($cellId [0] === '-')
			$cids = 1;

		$colId = $cellId [$cids];
		$rowId = substr ($cellId, $cids + 1);

		$colIdx = ord($colId) - ord('A');
		$rowIdx = intval($rowId);

		if (isset ($table['firstRowNumber']))
			$rowIdx -= $table['firstRowNumber'];
		else
			$rowIdx -= 1;

		if (!isset ($table['rows'][$rowIdx][$colIdx]))
			return $res;

		$cell = $table['rows'][$rowIdx][$colIdx];
		if (is_array($cell))
			$cellValue = $cell['value'];
		else
			$cellValue = $cell;

		if (is_string($cellValue) && substr ($cellValue, 0, 2) === '=[')
			return FALSE;

		if ($cellValue !== '')
		{
			if ($cids === 1)
				$res['result'] = -$cellValue;
			else
				$res['result'] = $cellValue;
		}

		$rowInfo = $spd->rowInfo ($table, $rowIdx);
		$colInfo = $spd->colInfo ($table, $colIdx);
		$cellName = ($cids === 1) ? '-' : '';
		$cellName .= $otherSrcCellId.$rowInfo['shortName'].':'.$colInfo['shortName'];

		$res['explain'][] = ['value' => $res['result'], 'text' => $cellName];

		return $res;
	}

	function colInfo ($table, $colIdx)
	{
		$colInfo = ['shortName' => '', 'fullName' => ''];

		$colInfoDef = $table['columns'][$colIdx];
		$colInfo['shortName'] = isset($colInfoDef['shortName']) ? $colInfoDef['shortName'] : 'xxx';

		return $colInfo;
	}

	function rowInfo ($table, $rowIdx)
	{
		$rowInfo = ['shortName' => '', 'fullName' => ''];

		$rowInfoDef = isset($table['rowInfo']) ? $table['rowInfo'] : [];
		$row = $table['rows'][$rowIdx];

		if (isset($rowInfoDef['shortName']['cols']))
		foreach ($rowInfoDef['shortName']['cols'] as $colIdx)
		{
			$rowInfo['shortName'] .= $row[$colIdx];
		}

		if (isset($rowInfoDef['fullName']['cols']))
		foreach ($rowInfoDef['fullName']['cols'] as $colIdx)
		{
			$rowInfo['fullName'] .= $row[$colIdx];
		}

		return $rowInfo;
	}

	public function run ()
	{
		$this->loadOtherSpreadsheets();
		$this->loadFromFile($this->spreadsheetId);
		$this->init ();
		$this->createContent();
		$this->testResults();
	}

	public function createSubColumns()
	{
		$this->loadFromFile($this->spreadsheetId);
		$partId = 'this';

		$this->renderAsSubColumns = TRUE;
		$this->idxTable = 0;
		$titles = [];
		forEach ($this->contentData[$partId]['tables'] as $tbl)
		{
			$this->renderTable($tbl);

			if (isset($tbl['sheetTitle']))
				$titles[] = $tbl['sheetTitle'];
			else
				$titles[] = $tbl['tableId'];

			$this->idxTable++;
		}

		foreach ($this->content as $cpIdx => $cp)
		{
			$table = [
				'cols' => $cp['header'],
				'rows' => $cp['table'],
				'params' => $cp['params']
			];
			if (isset($cp['params']['header']))
				$table['header'] = $cp['params']['header'];

			$this->subColumns['layout'][] = ['table' => $table];
		}
	}

	function testResults()
	{
	}
}
