<?php

namespace e10pro\reports\waste_cz;

use \E10\utils;


/**
 * Class ReportWasteByPersons
 * @package E10Pro\Reports\Waste_CZ
 */
class ReportWasteByPersons extends \e10pro\reports\waste_cz\ReportWasteCore
{
	var $rawData = [];
	var $totalWeight = 0.0;
	var $persons = [];

	function createContent ()
	{
		$this->createContent_State ();
	}

	function createContent_State ()
	{
		$this->appendRows (1, 1);
		//$this->appendRows (1, 0);
		//$this->appendRows (-1, 1);

		$data = [];
		forEach ($this->rawData as $personNdx => $personData)
		{
			$personInfo = [];
			$personInfo[] = [
				'text' => $personData['personName'], 'icon' => 'icon-building-o',
				'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $personData['personNdx']
			];
			$this->persons[] = $personData['personNdx'];

			// -- print button
			$btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'icon-print', 'text' => 'Přehled',
				'data-report' => 'e10pro.reports.waste_cz.ReportWasteOnePerson',
				'data-table' => 'e10.persons.persons', 'data-pk' => $personData['personNdx'], 'data-param-calendar-year' => $this->year,
				'actionClass' => 'btn-xs', 'class' => 'pull-right'];
			$btn['subButtons'] = [];
			$btn['subButtons'][] = [
				'type' => 'action', 'action' => 'addwizard', 'icon' => 'icon-envelope-o', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
				'data-table' => 'e10.persons.persons', 'data-pk' => $personData['personNdx'], 'data-param-calendar-year' => $this->year,
				'data-class' => 'e10.SendFormReportWizard',
				'data-addparams' => 'reportClass=' . 'e10pro.reports.waste_cz.ReportWasteOnePerson' . '&documentTable=' . 'e10.persons.persons'
			];
			$personInfo[] = $btn;

			if ($this->rawData[$personNdx]['unknownWasteWorkshop'])
			{
				$btn = [
					'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10.persons.persons',
					'text' => 'Načíst provozovny', 'data-class' => 'e10pro.purchase.WasteWorkshopWizard', 'icon' => 'icon-play',
					'data-addparams' => 'personNdx='.$personData['personNdx'],
					'actionClass' => 'btn-xs', 'class' => 'pull-right'
				];
				$personInfo[] = $btn;
			}

			$data[] = [
				'icp' => $personInfo,
				'_options' => ['class' => 'subtotal', 'colSpan' => ['icp' => 4]]
			];
			$data[] = [
				'icp' => 'IČP', 'code' => 'Kód odp.', 'title' => 'Název',
				'weight' => 'Hmotnost [t]',
				'_options' => ['class' => 'subtotal']
			];
			$data = array_merge ($data, \e10\sortByOneKey($personData['rows'], 'code'));

			if (count($personData['rows']) > 1)
				$data[] = ['icp' => 'Celkem', 'weight' => $personData['weight'], '_options' => ['class' => 'subtotal', 'colSpan' => ['icp' => 3]]];

			$data[] = ['icp' => ' ', '_options' => ['class' => 'separator', 'colSpan' => ['icp' => 3]]];
		}

		$data[] = ['icp' => 'CELKEM', 'weight' => $this->totalWeight, '_options' => ['class' => 'sumtotal']];

		$h = ['icp' => 'IČP', 'code' => 'Kód odpadu', 'title' => 'Název', 'weight' => ' Hmotnost [t]'];

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'params' => ['precision' => 3, 'hideHeader' => 1]]);

		$this->setInfo('title', 'Přehled odpadů za Osoby');
	}

	public function appendRows ($dir, $company)
	{
		$q[] = 'SELECT';

		if ($company)
			array_push ($q, ' persons.ndx as personNdx, persons.fullName as personFullName, address.specification as specification,');
		else
			array_push ($q, ' 0 as personNdx, %s as personFullName,', 'Občané');

		array_push ($q, ' [rows].item as item, [rows].unit as unit, SUM([rows].quantity) as quantity');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_address AS address ON heads.otherAddress1 = address.ndx');
		array_push ($q, ' WHERE  1');

		array_push ($q, ' AND persons.company = %i', $company);

		if ($this->inventory)
		{
			if ($dir === 1)
				array_push ($q, ' AND [rows].invDirection = 1 AND heads.docType IN %in', ['stockin', 'purchase']);
			else
				array_push ($q, ' AND [rows].invDirection = -1 AND heads.docType IN %in', ['invno']);
		}
		else
		{
			if ($dir === 1)
				array_push ($q, ' AND heads.docType IN %in', ['invni', 'purchase']);
			else
				array_push ($q, ' AND heads.docType IN %in', ['invno']);
		}

		array_push ($q, ' AND heads.docState = 4000 AND heads.initState = 0 AND YEAR(heads.dateAccounting) = %i', $this->year);
		array_push ($q, ' GROUP BY 1, 2, 3, 4, 5 ORDER BY personFullName, item');

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$itemWasteCode = $this->itemWasteCode ($r['item']);
			if (!$itemWasteCode)
				continue;

			$wasteCode = $itemWasteCode['code'];
			$person = $r['personNdx'];

			if (!isset($this->rawData[$person]))
			{
				$this->rawData[$person] = [
					'personNdx' => $r['personNdx'], 'personName' => $r['personFullName'],
					'cid' => $this->personCid($person),
					'rows' => [], 'weight' => 0.0,
					'unknownWasteWorkshop' => 0
				];
			}

			if (!isset($this->rawData[$person]['rows'][$wasteCode]))
			{
				$this->rawData[$person]['rows'][$wasteCode] = [
					'icp' => ($r['specification']) ? $r['specification'] : '1',
					'weight' => 0, 'code' => $itemWasteCode['code'], 'title' => $itemWasteCode['name']
				];
			}
			$quantity = $this->quantity($r['quantity'], $r['unit']) / 1000; // tuny

			$this->rawData[$person]['rows'][$wasteCode]['weight'] += $quantity;
			$this->rawData[$person]['weight'] += $quantity;

			if (!$r['specification'])
				$this->rawData[$person]['unknownWasteWorkshop'] = 1;

			$this->totalWeight += $quantity;
		}
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		$buttons[] = [
			'text' => 'Rozeslat hromadně emailem', 'icon' => 'icon-envelope',
			'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.reports.waste_cz.ReportWasteOnePersonWizard',
			'data-param-calendar-year' => $this->year,
			'data-table' => 'e10.persons.persons', 'data-pk' => '0',
			'class' => 'btn-primary'
		];
		return $buttons;
	}
}
