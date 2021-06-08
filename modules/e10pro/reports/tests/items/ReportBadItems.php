<?php

namespace e10pro\reports\tests\items;

use e10doc\core\libs\E10Utils, \Shipard\Utils\Utils;


/**
 * Class ReportBadItems
 */
class ReportBadItems extends \e10doc\core\libs\reports\GlobalReport
{
	/** @var \e10doc\core\TableHeads */
	var $tableHeads;
	/** @var \e10\witems\TableItems */
	var $tableItems;

	var $today;
	var $testCycle = '';

	var $docTypes;
	var $defaultFiscalPeriod = FALSE;
	var $fiscalPeriod = '';

	function init ()
	{
		if ($this->subReportId === '')
			$this->subReportId = 'badItems';

		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->tableItems = $this->app()->table('e10.witems.items');

		$this->today = Utils::today();

		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		if ($this->defaultFiscalPeriod === FALSE)
			$this->defaultFiscalPeriod = E10Utils::prevFiscalMonth($this->app());

		if ($this->subReportId !== 'badItems')
			$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years'], 'defaultValue' => $this->defaultFiscalPeriod]);

		parent::init();

		if ($this->fiscalPeriod === '')
			$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];

		$this->setInfo('icon', 'tables/e10.witems.items');
		if ($this->subReportId !== 'badItems')
			$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
	}

	public function setTestCycle ($cycle, $testEngine)
	{
		parent::setTestCycle($cycle, $testEngine);

		$this->subReportId = 'ALL';
		$this->testCycle = $cycle;

		switch ($cycle)
		{
			case 'thisMonth': $this->fiscalPeriod = E10Utils::todayFiscalMonth($this->app()); break;
			case 'prevMonth': $this->fiscalPeriod = E10Utils::prevFiscalMonth($this->app()); break;
		}
	}

	public function testTitle ()
	{
		$t = [];
		$t[] = [
			'text' => 'Byly nalezeny problémy ohledně položek',
			'class' => 'subtitle e10-me h1 block mt1 bb1 lh16'
		];
		return $t;
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'badItems': $this->createContent_BadItems(); break;
			case 'badDocs': $this->createContent_BadDocuments(); break;
			case 'ALL': $this->createContent_All(); break;
		}
	}

	function createContent_All ()
	{
		if ($this->testCycle === 'thisMonth')
			$this->createContent_BadItems();

		$this->createContent_BadDocuments();
	}

	function createContent_BadItems()
	{
		$q [] = 'SELECT witems.*';
		array_push ($q, ', [types].fullName AS typeName, [types].validTo AS typeValidTo');
		array_push ($q, ' FROM [e10_witems_items] AS witems');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [types] ON witems.itemType = [types].ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND (');
		array_push ($q, ' (witems.validTo IS NULL AND [types].validTo IS NOT NULL)');
		array_push ($q, ' OR (witems.validTo IS NOT NULL AND [types].validTo IS NOT NULL AND witems.validTo > [types].validTo)');
		array_push ($q, ')');

		array_push ($q, ' ORDER BY witems.fullName, witems.ndx');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$newItem = [
				'in' => ['text'=> $r['id'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r['ndx'], 'icon' => $this->tableItems->tableIcon($r)],

				'title' => $r['fullName'], 'it' => $r['typeName']
			];
			$newItem['_options']['cellClasses']['in'] = $this->itemStateClass($r);

			if (!Utils::dateIsBlank($r['typeValidTo']) && $r['typeValidTo'] < $this->today)
			{
				$newItem['_options']['cellClasses']['it'] = 'e10-warning2';
				$newItem['note'] = 'Platnost typu položky vypršela '.Utils::datef($r['typeValidTo']);
			}

			if (Utils::dateIsBlank($r['validTo']) && Utils::dateIsBlank($r['typeValidTo']) && $r['validTo'] > $r['typeValidTo'])
			{
				$newItem['note'] = 'Platnost typu položky vypršela '.Utils::datef($r['typeValidTo']);
			}

			$data[] = $newItem;
		}

		$q = [];
		$q [] = 'SELECT itemSets.*,';
		array_push ($q, ' [srcItems].fullName AS srcItemName, [srcItems].id AS srcItemId,');
		array_push ($q, ' [srcItems].docState AS docState, [srcItems].docStateMain AS docStateMain,');
		array_push ($q, ' [dstItems].fullName AS dstItemName, [dstItems].id AS dstItemId,');
		array_push ($q, ' [srcTypes].fullName AS srcTypeName');
		array_push ($q, ' FROM [e10_witems_itemsets] AS itemSets');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS srcItems ON itemSets.itemOwner = srcItems.ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS dstItems ON itemSets.item = dstItems.ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [srcTypes] ON srcItems.itemType = [srcTypes].ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [dstTypes] ON dstItems.itemType = [dstTypes].ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND srcItems.isSet = %i', 1);
		array_push ($q, ' AND itemSets.setItemType = %i', 0);
		array_push ($q, ' AND dstTypes.[type] != %i', 1);
		array_push ($q, ' ORDER BY srcItems.fullName, srcItems.ndx');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$newItem = [
				'in' => ['text'=> $r['srcItemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r['itemOwner'], 'icon' => $this->tableItems->tableIcon($r)],
				'title' => $r['srcItemName'], 'it' => $r['srcTypeName']
			];
			$newItem['_options']['cellClasses']['in'] = $this->itemStateClass($r);
			$newItem['note'] = 'Sada obsahuje položku #'.$r['dstItemId'].' `'.$r['dstItemName'].'`, která není typu Zásoba';

			$data[] = $newItem;
		}



		$this->setInfo('title', 'Problémy s položkami');
		if (count($data))
		{
			$h = ['#' => '#', 'in' => '_Položka', 'title' => '_Název', 'it' => 'Typ', 'note' => 'Pozn.'];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Problémy s položkami', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');

		$this->paperOrientation = 'landscape';
	}

	function createContent_BadDocuments ()
	{
		$q [] = 'SELECT [rows].*,';
		array_push ($q, ' [heads].docNumber AS docNumber, [heads].[docState], [heads].[docStateMain], [heads].[docType], [heads].dateAccounting AS headDateAccounting,');
		array_push ($q, ' persons.fullName AS personName,');
		array_push ($q, ' [witems].fullName AS itemName, [witems].id AS itemId, [witems].docState AS itemDocState, [witems].docStateMain AS itemDocStateMain,');
		array_push ($q, ' [witems].validTo AS itemValidTo, [witems].[type] as itemItemType,');
		array_push ($q, ' [witems].successorItem AS itemSuccessorItem, [witems].[successorDate] as itemSuccessorDate,');
		array_push ($q, ' [successors].fullName AS successorName, [successors].id AS successorId,');
		array_push ($q, ' [itemTypes].validTo AS typeValidTo');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS [witems] ON [rows].[item] = [witems].ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS [successors] ON [witems].[successorItem] = [successors].ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [itemTypes] ON witems.itemType = [itemTypes].ndx');
		array_push ($q, ' WHERE heads.docState = 4000');
		array_push ($q, ' AND (');
		array_push ($q, ' ([witems].validTo IS NOT NULL AND [heads].dateAccounting > [witems].validTo)');
		array_push ($q, ' OR ([itemTypes].validTo IS NOT NULL AND [heads].dateAccounting > [itemTypes].validTo)');
		array_push ($q, ' OR ([witems].docState = %i)', 9800);
		array_push ($q, ' OR (witems.[type] != [rows].itemType)');
		array_push ($q, ' )');

		E10Utils::fiscalPeriodQuery ($q, $this->fiscalPeriod);

		array_push ($q, ' ORDER BY heads.dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);

		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$itemRecData = ['ndx' => $r['item'], 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']];

			$newItem = [
				'dn' => [
					'text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['document'], 'icon' => $docType ['icon']],
					'person' => $r['personName'], 'date' => Utils::datef($r['headDateAccounting'], '%d'), 'dt' => $docType ['shortcut'],
					'in' => ['text'=> $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r['item']],
					'itemName' => $r['itemName']
			];
			$newItem['_options']['cellClasses']['dn'] = $this->docStateClass($r);
			$newItem['_options']['cellClasses']['in'] = $this->itemStateClass($itemRecData);
			$newItem['_options']['cellClasses']['itemName'] = $newItem['_options']['cellClasses']['in'];

			$newItem['note'] = [];
			if ($r['itemSuccessorItem'] && ['itemSuccessorItem'] !== $r['item'])
				$newItem['note'][] = ['text' => "Položka není nahrazena nástupcem `#".$r['successorId'].'` ('.$r['successorName'].')', 'class' => 'block'];
			if ($r['itemDocState'] === 9800)
				$newItem['note'][] = ['text' => 'Položka je smazaná', 'class' => 'block'];
			if (!Utils::dateIsBlank($r['itemValidTo']) && $r['itemValidTo'] < $r['headDateAccounting'])
				$newItem['note'][] = ['text' => 'Položka je neplatná k '.Utils::datef($r['itemValidTo']), 'class' => 'block'];
			if (!Utils::dateIsBlank($r['typeValidTo']) && $r['typeValidTo'] < $r['headDateAccounting'])
				$newItem['note'][] = ['text' => 'Typ položky je neplatný k '.Utils::datef($r['typeValidTo']), 'class' => 'block'];
			if ($r['itemItemType'] !== $r['itemType'])
				$newItem['note'][] = ['text' => "Nesprávný typ položky; je `".$r['itemType'].'`, má být `'.$r['itemItemType'].'`', 'class' => 'block'];

			$data[] = $newItem;
		}

		$this->setInfo('title', 'Chybné položky v dokladech');
		if (count($data))
		{
			$h = [
				'#' => '#', 'dn' => '_Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba',
				'in' => 'Pol.', 'itemName' => 'Název',
				'note' => 'Pozn.'
			];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Chybné položky v dokladech '.$this->fiscalPeriod, 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'badItems', 'icon' => 'detailReportItems', 'title' => 'Položky'];
		$d[] = ['id' => 'badDocs', 'icon' => 'detailReportDocuments', 'title' => 'Doklady'];

		return $d;
	}

	function docStateClass($r)
	{
		$docStates = $this->tableHeads->documentStates($r);
		$docStateClass = $this->tableHeads->getDocumentStateInfo($docStates, $r, 'styleClass');
		return $docStateClass;
	}

	function itemStateClass($r)
	{
		$docStates = $this->tableItems->documentStates($r);
		$docStateClass = $this->tableItems->getDocumentStateInfo($docStates, $r, 'styleClass');
		return $docStateClass;
	}
}
