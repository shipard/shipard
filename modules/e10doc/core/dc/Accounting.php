<?php

namespace e10doc\core\dc;
use \e10\utils;
use \e10doc\core\libs\E10Utils;


/**
 * Class Accounting
 * @package e10doc\core\dc
 */
class Accounting extends \e10\DocumentCard
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

	protected $docTypes;

	function createBalances($recData)
	{
		if ($this->app()->model()->module ('e10doc.balance') === FALSE)
			return;

		$q = 'SELECT DISTINCT [pairId], [type], [person], [symbol1], [symbol2], [currency], [docHead], [fiscalYear] FROM [e10doc_balance_journal] WHERE [docHead] = %i ORDER BY [docLine]';
		$balancesRows = $this->table->db()->query ($q, $recData['ndx']);

		if (count ($balancesRows) == 0)
		{
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'system/iconStar', 'text' => 'Doklad '.$recData['docNumber'].' nemá zápisy v saldokontu'],
					'header' => [], 'table' => []]);
			return;
		}

		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'tables' => []];
		foreach ($balancesRows as $r)
		{
			$balanceCfg = $this->app()->cfgItem ('e10.balance');
			$balanceName = $balanceCfg[$r['type']]['name'];
			$balanceIcon = $balanceCfg[$r['type']]['icon'];

			$b = $this->loadBalance($r, $balanceCfg[$r['type']]);

			$title = [['icon' => $balanceIcon, 'text' => $balanceName.' ']];

			if ($recData['person'] !== $r['person'] && $r['person'])
			{
				$recPerson = $this->tablePersons->loadItem ($r['person']);
				$title [] = ['text' => $recPerson['fullName'], 'icon' => $this->tablePersons->icon($recPerson), 'class' => 'e10-small'];
			}

			if ($r['symbol2'] !== '')
				$title [] = ['text' => $r['symbol2'], 'prefix' => 'SS', 'class' => 'pull-right'];
			$title [] = ['text' => $r['symbol1'], 'prefix' => 'VS', 'class' => 'pull-right'];

			$content['tables'][] = ['title' => $title, 'header' => $b['header'], 'table' => $b['rows']];
		}

		$this->addContent ('body', $content);
	}

	function loadBalance($balanceRow, $balanceInfo)
	{
		$requestColumn = 'request';
		$paymentColumn = 'payment';
		$requestColumnLabel = ' Předpis';
		$paymentColumnLabel = ' Uhrazeno';
		$restColumnLabel = ' Zbývá';
		if (isset($balanceInfo['type']) && $balanceInfo['type'] === 'hc')
		{
			$requestColumn = 'requestHc';
			$paymentColumn = 'paymentHc';
			$requestColumnLabel = ' Předpis DM';
			$paymentColumnLabel = ' Uhrazeno DM';
			$restColumnLabel = ' Zbývá DM';
		}

		$q = 'SELECT b.[docHead] as docHead, h.[docType], h.[docNumber] as docNumber, h.[dateAccounting] as dateAccounting, b.[date] as dateDue,' .
				' b.['.$requestColumn.'] as request, b.['.$paymentColumn.'] as payment, h.[initState] as initState, h.[fiscalYear] as fiscalYear' .
				' FROM [e10doc_balance_journal] as b LEFT JOIN [e10doc_core_heads] as h ON b.[docHead] = h.[ndx]' .
				' WHERE (b.[pairId] = %s AND (b.[fiscalYear] = %i OR (b.[fiscalYear] = %i AND h.initState = 1)))' .
				' ORDER BY [dateAccounting]';
		$rows = $this->table->db()->query ($q, $balanceRow['pairId'], $balanceRow['fiscalYear'], $this->getNextFiscalYear($balanceRow['fiscalYear']));

		if (count ($rows) == 0)
			return FALSE;

		$rest = 0.0;
		$dataRows = [];
		$sumtotal = ['request' => 0.0, 'payment' => 0.0];
		$sumtotal['_options'] = ['class' => 'sum'];

		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$item['docType'] = $this->docTypes[$r['docType']]['shortcut'];
			$item['docNumber'] = ['text'=> $r['docNumber'], 'icon' => $this->docTypes[$r['docType']]['icon'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']];

			if ($r['docType'] == 'cmnbkp' && $r['initState'])
				$item['_options'] = ['class' => 'subtotal'];

			if ($r['fiscalYear'] == $balanceRow['fiscalYear'] || $r['initState'] != 1 || $r['docType'] != 'cmnbkp')
			{
				$rest = round ($rest + $r['request'] - $r['payment'], 2);
				$sumtotal['request'] += $r['request'];
				$sumtotal['payment'] += $r['payment'];
			}

			$item['rest'] = $rest;
			$sumtotal['rest'] = $rest;

			if ($r['request'] == 0.0)

				$item['dateDue'] = '';
			if ($item ['request'] === 0.0)
				unset ($item ['request']);
			if ($item ['payment'] === 0.0)
				unset ($item ['payment']);

			if ($r['docHead'] == $balanceRow['docHead'])
				$item['_options'] = ['class' => 'e10-row-this'];
			else
				if ($r['docType'] == 'cmnbkp' && $r['initState'])
					$item['_options'] = ['class' => 'e10-row-info'];

			$dataRows[] = $item;
		}

		if (count ($rows) > 1)
			$dataRows[] = $sumtotal;

		$h = [
				'#', 'docType' => 'DD', 'docNumber' => 'Doklad', 'dateAccounting' => 'Úč. datum', 'dateDue' => 'Splatnost',
				'request' => $requestColumnLabel, 'payment' => $paymentColumnLabel, 'rest' => $restColumnLabel
		];
		$data = ['rows' => $dataRows, 'header' => $h];
		return $data;
	}

	function getNextFiscalYear ($fiscalYear)
	{
		$nextFiscalYear = 0;
		foreach ($this->app->cfgItem ('e10doc.acc.periods') as $actualPeriod)
		{
			if ($actualPeriod['ndx'] == $fiscalYear)
			{
				$nextFiscalYearBegin = FALSE;
				foreach ($this->app->cfgItem ('e10doc.acc.periods') as $nextPeriod)
				{
					if (($nextFiscalYearBegin === FALSE && $actualPeriod['end'] < $nextPeriod['begin']) ||
							($nextFiscalYearBegin !== FALSE && $nextFiscalYearBegin > $nextPeriod['begin']))
					{
						$nextFiscalYearBegin = $nextPeriod['begin'];
						$nextFiscalYear = $nextPeriod['ndx'];
					}
				}
				break;
			}
		}
		return $nextFiscalYear;
	}

	function createContentAccounting ()
	{
		$a = $this->table->loadAccounting ($this->recData);

		if ($a === FALSE)
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'system/iconList', 'text' => 'Doklad '.$this->recData['docNumber'].' nemá účetní zápisy'],
					'header' => [], 'table' => []]);
		else
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Zaúčtování dokladu '.$this->recData['docNumber'], 'class' => 'red'],
					'header' => $a['accRowsHeader'], 'table' => $a['accRows']]);

		if (isset($a['accNotes']))
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Problémy v zaúčtování'],
					'header' => $a['accNotesHeader'], 'table' => $a['accNotes']]);
	}

	function createVatCSReport($recData)
	{
		$vatId = 'neuvedeno';
		$q[] = 'SELECT [rows].*, filings.title as filingTitle ';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS] as [rows]');
		array_push($q, ' LEFT JOIN [e10doc_taxes_filings] as filings ON [rows].filing = filings.ndx');
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$data = [];
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$data[] = $r->toArray();
			if (strlen($r['vatId']) > 0)
				$vatId = $r['vatId'];
		}

		if (!count($data))
		{
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'report/generalLedger', 'text' => 'Doklad '.$recData['docNumber'].' nemá záznamy pro kontrolní hlášení DPH'],
					'header' => [], 'table' => []]);
			return;
		}

		$h = ['filingTitle' => 'Podání', 'rowKind' => 'Oddíl', 'base1' => ' Zák. 1', 'tax1' => ' Daň 1',  'base2' => ' Zák. 2', 'tax2' => ' Daň 2',  'base3' => ' Zák. 3', 'tax3' => ' Daň 3'];
		$t = [['icon' => 'report/generalLedger', 'text' => 'Záznamy pro kontrolní hlášení DPH']];
		$t [] = ['text' => $vatId, 'prefix' => 'DIČ', 'class' => 'pull-right'];
		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $data, 'header' => $h, 'title' => $t, 'params' => ['disableZeros' => 1]];
		$this->addContent ('body', $content);
	}

	function createVatReport($recData)
	{
		$q[] = 'SELECT [rows].*, filings.title as filingTitle, reports.title AS reportTitle ';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] as [rows]');
		array_push($q, ' LEFT JOIN [e10doc_taxes_filings] AS filings ON [rows].filing = filings.ndx');
		array_push($q, ' LEFT JOIN [e10doc_taxes_reports] AS reports ON [rows].report = reports.ndx');
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$data = [];
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$taxCode = E10Utils::taxCodeCfg($this->app(), $r['taxCode']);
			if ($taxCode)
				$item['tc'] = $taxCode['fullName'];

			$data[] = $item;
		}

		if (!count($data))
		{
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'report/generalLedger', 'text' => 'Doklad '.$recData['docNumber'].' nemá záznamy pro přiznání DPH'],
					'header' => [], 'table' => []]);
			return;
		}

		$h = ['reportTitle' => 'Přiznání', 'filingTitle' => 'Podání', 'tc' => 'Sazba', 'taxPercents' => ' %', 'base' => ' Základ', 'tax' => ' Daň'];
		$t = [['icon' => 'report/generalLedger', 'text' => 'Záznamy pro přiznání DPH']];
		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $data, 'header' => $h, 'title' => $t, 'params' => ['disableZeros' => 1]];
		$this->addContent ('body', $content);
	}

	function createOSSReport($recData)
	{
		$q[] = 'SELECT [rows].*, filings.title as filingTitle, reports.title AS reportTitle ';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatOSS] as [rows]');
		array_push($q, ' LEFT JOIN [e10doc_taxes_filings] AS filings ON [rows].filing = filings.ndx');
		array_push($q, ' LEFT JOIN [e10doc_taxes_reports] AS reports ON [rows].report = reports.ndx');
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$data = [];
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$taxCode = E10Utils::taxCodeCfg($this->app(), $r['taxCode']);
			if ($taxCode)
				$item['tc'] = $taxCode['print'];
			$item['cc'] = strtoupper($r['countryConsumption']);

			$item['docCurrency'] = $this->app()->cfgItem ('e10.base.currencies.'.$r['docCurrency'].'.shortcut');

			$data[] = $item;
		}

		if (!count($data))
		{
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'report/generalLedger', 'text' => 'Doklad '.$recData['docNumber'].' nemá záznamy pro přiznání OSS'],
					'header' => [], 'table' => []]);
			return;
		}

		$h = [
			'reportTitle' => 'Přiznání', 'filingTitle' => 'Podání', 'cc' => 'Země', 'tc' => 'Sazba', 'taxPercents' => ' %',
			'baseDC' => ' Základ', 'taxDC' => ' Daň', 'docCurrency' => ' Měna',
			'baseTC' => ' Základ EUR', 'taxTC' => ' Daň EUR'
		];
		$t = [['icon' => 'report/generalLedger', 'text' => 'Záznamy pro přiznání OSS']];
		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $data, 'header' => $h, 'title' => $t, 'params' => ['disableZeros' => 1]];
		$this->addContent ('body', $content);
	}

	function createRos($recData)
	{
		if (!$recData['rosReg'])
			return;

		$rosRegs = $this->app()->cfgItem('terminals.ros.regs', NULL);
		if (!$rosRegs || !count($rosRegs))
			return;

		$rosTypes = $this->app()->cfgItem('terminals.ros.types', NULL);
		if (!$rosTypes)
			return;

		$rosReg = $rosRegs[$recData['rosReg']];
		$rosType = $rosTypes[$rosReg['rosType']];

		$rosEngine = $this->app()->createObject($rosType['engine']);
		if ($rosEngine)
		{
			$content = $rosEngine->documentDetail ($recData);
			$this->addContent('body', $content);
		}
		else
		{
			$this->addContent('body', [
				'type' => 'line', 'pane' => 'e10-pane e10-pane-table e10-warning3',
				'line' => ['text' => 'Chybný typ evidence tržeb `'.$recData['rosReg'].'`', 'class' => 'h1', 'icon' => 'system/iconWarning']
			]);
		}
	}

	function createInventory($recData)
	{
		if ($this->app()->model()->table ('e10doc.inventory.journal') === FALSE)
			return;

		$docClass = 'e10-bg-t8';
		$invClass = 'e10-bg-t6';
		$nonInvClass = 'e10-bg-t9';

		$operations = $this->app()->cfgItem('e10.docs.operations', []);
		$invDirections = $this->tableRows->columnInfoEnum ('invDirection', 'cfgText');

		$q = [];
		array_push($q, 'SELECT [rows].*,');
		array_push($q, ' [journal].[quantity] AS jQuantity, [journal].[price] AS jPrice, [journal].[unit] AS jUnit, [journal].[item] AS jItem,');
		array_push($q, ' [witems].fullName AS witemName, [witems].[id] AS witemId');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');

		array_push($q, ' LEFT JOIN [e10doc_inventory_journal] AS [journal] ON ([rows].document = [journal].docHead AND [rows].ndx = [journal].docRow)');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [witems] ON [rows].item = [witems].ndx');

		array_push($q, ' WHERE [rows].document = %i', $recData ['ndx']);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');

		$cfgUnits = $this->app->cfgItem ('e10.witems.units');
		$rows = $this->table->db()->query($q);
		$list = [];
		$lastTopRowNdx = -1;

		forEach ($rows as $r)
		{
			//if (!$r['jItem'])
			//	continue;
			$docUnit = (isset($cfgUnits[$r['unit']])) ? $cfgUnits[$r['unit']]['shortcut'] : '';

			$op = ['text' => $operations[$r['operation']]['title'], 'title' => utils::nf($r['operation']), 'class' => ''];

			$rowText = ['text' => $r['text'], 'class' => ''];
			if ($r['ownerRow'] === $lastTopRowNdx)
				$rowText['icon'] = 'icon-level-up fa-rotate-90 fa-fw';


			$rowItem = [
				'text' => $rowText,
				'item' => ['text' => $r['witemId'], 'title' => $r['witemName'], 'class' => '', 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['item']],
				'docQuantity' => $r['quantity'], 'docUnit' => $docUnit, 'docPriceAll' => $r['priceAllHc'],
				'invDir' => $invDirections[$r['invDirection']], 'operation' => $op,
			];

			$rowItem['_options'] = ['cellClasses' => [
					'docQuantity' => $docClass.' number', 'docUnit' => $docClass, 'docPriceAll' => $docClass.' number', 'invDir' => $docClass, 'operation' => $docClass,
					'invQuantity' => $invClass.' number', 'invUnit' => $invClass, 'invPriceAll' => $invClass.' number'
				]
			];

			if ($r['jItem'])
			{
				$rowItem['invQuantity'] = $r['jQuantity'];
				$rowItem['invPriceAll'] = $r['jPrice'];
				$invUnit = (isset($cfgUnits[$r['jUnit']])) ? $cfgUnits[$r['jUnit']]['shortcut'] : '!'.$r['jUnit'];
				$rowItem['invUnit'] = $invUnit;
			}
			else
			{
				$rowItem['_options']['cellClasses']['invQuantity'] = $nonInvClass.' center';
				$rowItem['_options']['cellClasses']['invPriceAll'] = $nonInvClass;
				$rowItem['_options']['cellClasses']['invUnit'] = $nonInvClass;
				$rowItem['_options']['class'] = 'e10-off';
				$rowItem['_options']['colSpan']['invQuantity'] = 3;
				$rowItem['invQuantity'] = '---';
			}

			$list[] = $rowItem;

			if (!$r['ownerRow'])
				$lastTopRowNdx = $r['ndx'];
		}

		$header =
		[
			[
				'docQuantity' => 'Doklad',
				'invQuantity' => 'Zásoby',
				'_options' => ['colSpan' => ['docQuantity' => 5, 'invQuantity' => 3], 'cellClasses' => ['docQuantity' => $docClass.' center', 'invQuantity' => $invClass.' center']]
			],
			[
				'#' => '#', 'text' => 'Text řádku', 'item' => 'Pol.',
				'docQuantity' => ' Množství', 'docUnit' => 'Jed.',
				'docPriceAll' => ' Cena celkem',
				'invDir' => 'Směr', 'operation' => 'Pohyb',
				'invQuantity' => ' Množství', 'invUnit' => 'Jed.', 'invPriceAll' => ' Cena celkem',
			]
		];

		$hh = $header[1];
		$hh ['invPriceAll'] = '+' . $hh ['invPriceAll'];

		$this->addContent([
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'e10doc-inventory/inventoryStates', 'text' => 'Zásoby'],
			'header' => $hh, 'table' => $list, 'params' => ['header' => $header]
			]);
	}

	public function init()
	{
		$this->tablePersons = new \E10\Persons\TablePersons ($this->app());
		$this->tableRows = $this->app()->table('e10doc.core.rows');

		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');
	}

	public function createContent ()
	{
		$this->init();

		$this->createContentAccounting();
		$this->createBalances($this->recData);
		$this->createVatReport($this->recData);
		$this->createVatCSReport($this->recData);
		$this->createOSSReport($this->recData);
		$this->createRos($this->recData);
		$this->createInventory($this->recData);
	}
}
