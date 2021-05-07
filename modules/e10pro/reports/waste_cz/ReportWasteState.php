<?php

namespace e10pro\reports\waste_cz;


/**
 * Class ReportWasteState
 * @package e10pro\reports\waste_cz
 */
class ReportWasteState extends \e10pro\reports\waste_cz\ReportWasteCore
{
	var $rawData = array();
	var $totalIn = 0.0;
	var $totalOut = 0.0;

	function createContent ()
	{
		$this->createContent_State ();
	}

	function createContent_State ()
	{
		$this->appendRows (1, 1);
		$this->appendRows (1, 0);
		$this->appendRows (-1, 1);

		$data = array ();
		forEach ($this->rawData as $wasteCode)
		{
			$data[] = ['title' => $wasteCode['code'].' '.$wasteCode['name'],
				'in' => 'Příjem [t]', 'out' => 'Výdej [t]', 'cid' => 'IČ', 'name' => 'Firma',
				'icp' => 'IČP', 'street' => 'Ulice', 'city' => 'Město', 'zipcode' => 'PSČ',
				'_options' => ['class' => 'subtotal']
			];
			$data = array_merge ($data, array_values($wasteCode['rows']));

			$data[] = array ('in' => $wasteCode['in'], 'out' => $wasteCode['out'], '_options' => array ('class' => 'subtotal'));
			$data[] = array ('title' => ' ', '_options' => array ('class' => 'separator', 'colSpan' => array ('title' => 5)));
		}

		$data[] = array ('title' => ' ', '_options' => array ('class' => 'separator', 'colSpan' => array ('title' => 5)));
		$data[] = array ('title' => 'CELKEM', 'in' => $this->totalIn, 'out' => $this->totalOut, '_options' => array ('class' => 'subtotal'));

		$h = [
			'title' => 'Kód odpadu', 'in' => ' Příjem [t]', 'out' => ' Výdej [t]', 'cid' => ' IČ', 'name' => '_Firma',
			'icp' => 'IČP', 'street' => 'Ulice', 'city' => 'Město', 'zipcode' => 'PSČ'
		];

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'params' => ['precision' => 3, 'hideHeader' => 1]));

		$this->setInfo('title', 'Roční hlášení o produkci a nakládání s odpady');
		$this->paperOrientation = 'landscape';
	}

	public function appendRows ($dir, $company)
	{
		$q[] = 'SELECT';

		if ($company)
			array_push ($q, ' persons.ndx as personNdx, persons.fullName as personFullName, address.specification as icp,');
		else
			array_push ($q, ' 0 as personNdx, %s as personFullName, ', 'Občané', '%s as icp, ', '');

		array_push ($q, ' [rows].item as item, [rows].unit as unit, SUM([rows].quantity) as quantity, ');

		if ($company)
			array_push ($q, ' address.street as street, address.city as city, address.zipcode as zipcode');
		else
			array_push ($q, '%s as street, ', '', '%s as city, ', '', '%s as zipcode', '');

		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_address AS address ON heads.otherAddress1 = address.ndx');
		array_push ($q, ' WHERE  1');

		array_push ($q, ' AND persons.company = %i', $company);

		if ($this->inventory)
		{
			if ($dir === 1)
				array_push ($q, ' AND [rows].invDirection = 1 AND heads.docType IN %in', array ('stockin', 'purchase'));
			else
				array_push ($q, ' AND [rows].invDirection = -1 AND heads.docType IN %in', array ('invno'));
		}
		else
		{
			if ($dir === 1)
				array_push ($q, ' AND heads.docType IN %in', ['invni', 'purchase', 'stockin']);
			else
				array_push ($q, ' AND heads.docType IN %in', ['invno']);
		}

		array_push ($q, ' AND heads.docState = 4000 AND heads.initState = 0 AND YEAR(heads.dateAccounting) = %i', $this->year);
		array_push ($q, ' GROUP BY 4, 5, 1, 2, 3 ORDER BY personFullName, item');

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$itemWasteCode = $this->itemWasteCode ($r['item']);
			if (!$itemWasteCode)
				continue;

			$wasteCode = $itemWasteCode['code'];
			$person = $r['personNdx'];

			if (!isset($this->rawData[$wasteCode]))
				$this->rawData[$wasteCode] = [
					'code' => $itemWasteCode['code'], 'name' => $itemWasteCode['name'],
					'rows' => [], 'in' => 0.0, 'out' => 0.0
				];

			$icp = ($r['icp'] !== NULL) ? $r['icp'] : '1';
			$personId = $person.'_'.$icp;

			if (!isset($this->rawData[$wasteCode]['rows'][$personId]))
				$this->rawData[$wasteCode]['rows'][$personId] = [
					'in' => 0.0, 'out' => 0.0, 'name' => $r['personFullName'],
					'icp' => $icp, 'street' => $r['street'], 'city' => $r['city'], 'zipcode' => $r['zipcode'],
					'cid' => $this->personCid ($person)
				];

			if (!$r['icp'] && $company)
			{
				$addr = $this->personAddress ($person);
				if ($addr)
				{
					$this->rawData[$wasteCode]['rows'][$personId]['street'] = $addr['street'];
					$this->rawData[$wasteCode]['rows'][$personId]['city'] = $addr['city'];
					$this->rawData[$wasteCode]['rows'][$personId]['zipcode'] = $addr['zipcode'];
				}
			}

			$quantity = $this->quantity($r['quantity'], $r['unit']) / 1000; // tuny

			if ($dir === 1)
			{
				$this->rawData[$wasteCode]['rows'][$personId]['in'] += $quantity;
				$this->rawData[$wasteCode]['in'] += $quantity;
				$this->totalIn += $quantity;
			}
			else
			{
				$this->rawData[$wasteCode]['rows'][$personId]['out'] += $quantity;
				$this->rawData[$wasteCode]['out'] += $quantity;
				$this->totalOut += $quantity;
			}
		}
	}
}
