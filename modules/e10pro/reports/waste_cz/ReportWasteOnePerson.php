<?php

namespace e10pro\reports\waste_cz;


use \Shipard\Report\FormReport;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\Utils;

/**
 * Class ReportWasteOnePerson
 * @package e10pro\reports\waste_cz
 */
class ReportWasteOnePerson extends FormReport
{
	public $calendarYear = 0;
	var $wasteCodes;
	var $itemWasteCodes = [];
	protected $inventory;

	var $sumData = [];
	var $itemsData = [];

	var $currencies;
	var $tablePersons;
	var $tableDocHeads;

	var $messageMoney = 0.0;
	var $messageCurrency = '';

	function init ()
	{
		$this->reportId = 'reports.default.e10pro.reports.waste_cz.reportWasteOnePerson';
		$this->reportTemplate = 'reports.default.e10pro.reports.waste_cz.reportWasteOnePerson';
	}

	public function setOutsideParam ($param, $value)
	{
		if ($param === 'data-param-calendar-year')
		{
			$this->calendarYear = intval($value);
			$this->data['calendarYear'] = strval ($this->calendarYear);
		}
	}

	public function itemWasteCode ($itemNdx)
	{
		if (isset($this->itemWasteCodes[$itemNdx]))
			return $this->itemWasteCodes[$itemNdx];

		$this->itemWasteCodes[$itemNdx] = NULL;

		$q = 'SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = %i AND [group] = %s AND [property] = %s';
		$rowCode = $this->app->db()->query ($q, 'e10.witems.items', $itemNdx, 'odpad', 'kododp')->fetch();

		if ($rowCode && isset($rowCode['valueString']) && $rowCode['valueString'] !== '')
		{
			if (isset ($this->wasteCodes[$rowCode['valueString']]))
			{
				$this->itemWasteCodes[$itemNdx] = $this->wasteCodes[$rowCode['valueString']];
				$this->itemWasteCodes[$itemNdx]['code'] = $rowCode['valueString'];
			}
			else
			{
				$this->itemWasteCodes[$itemNdx] = array ('code' => $rowCode['valueString'], 'name' => '---', 'group' => 'other');
			}
		}

		return $this->itemWasteCodes[$itemNdx];
	}

	protected function quantity ($quantity, $unit)
	{
		switch ($unit)
		{
			case 'kg': return $quantity;
			case 'g': return $quantity / 1000;
		}
		return 0;
	}

	public function checkDocumentInfo (&$documentInfo)
	{
		$documentInfo['messageDocKind'] = 'outbox-default';
	}

	public function loadData2 ()
	{
		//$this->inventory = ($this->app->model()->table ('e10doc.inventory.journal') !== FALSE);
		$this->inventory = FALSE;
		$fn = __SHPD_MODULES_DIR__.'/e10pro/reports/waste_cz/config/wastecodes.json';
		$this->wasteCodes = Utils::loadCfgFile($fn);

		$cy = $this->app()->testGetParam ('data-param-calendar-year');
		if ($this->calendarYear === 0 && $cy !== '')
			$this->calendarYear = intval($cy);

		$this->data['calendarYear'] = strval ($this->calendarYear);

		$this->fiscalYear = E10Utils::todayFiscalYear($this->app);
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$allProperties = $this->app()->cfgItem ('e10.base.properties', []);


		// person
		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data ['person'] = $this->table->loadItem ($this->recData ['ndx'], 'e10_persons_persons');
		$this->data ['person']['lists'] = $tablePersons->loadLists ($this->data ['person']);
		$this->data ['person']['address'] = $this->data ['person']['lists']['address'][0];
		// persons country
		$country = $this->app->cfgItem ('e10.base.countries.'.$this->data ['person']['lists']['address'][0]['country']);
		$this->data ['person']['address']['countryName'] = $country['name'];
		$this->data ['person']['address']['countryNameEng'] = $country['engName'];
		$this->data ['person']['address']['countryNameSC2'] = $country['sc2'];
		$this->lang = $country['lang'];

		forEach ($this->data ['person']['lists']['properties'] as $iii)
		{
			if ($iii['group'] != 'ids') continue;
			$name = '';
			if ($iii['property'] == 'taxid') $name = 'DIČ';
			else if ($iii['property'] == 'oid') $name = 'IČ';
			else if ($iii['property'] == 'idcn') $name = 'OP';
			else if ($iii['property'] == 'birthdate') $name = 'DN';
			else if ($iii['property'] == 'pid') $name = 'RČ';

			if ($name === '')
				continue;

			$this->data ['person_identifiers'][] = array ('name'=> $name, 'value' => $iii['value']);
		}


		// owner
		$ownerNdx = intval($this->app->cfgItem ('options.core.ownerPerson', 0));
		if ($ownerNdx)
		{
			$this->data ['owner'] = $this->table->loadItem ($ownerNdx, 'e10_persons_persons');
			$this->data ['owner']['lists'] = $tablePersons->loadLists ($this->data ['owner']);
			$ownerCountry = '';
			if (isset($this->data ['owner']['lists']['address'][0]))
			{
				$this->data ['owner']['address'] = $this->data ['owner']['lists']['address'][0];
				$ownerCountry = $this->app->cfgItem('e10.base.countries.' . $this->data ['owner']['lists']['address'][0]['country']);
				$this->data ['owner']['address']['countryName'] = $ownerCountry['name'];
				$this->data ['owner']['address']['countryNameEng'] = $ownerCountry['engName'];
				$this->data ['owner']['address']['countryNameSC2'] = $ownerCountry['sc2'];
			}
			forEach ($this->data ['owner']['lists']['properties'] as $iii)
			{
				if ($iii['group'] == 'ids')
				{
					$name = '';
					if ($iii['property'] == 'taxid')
					{
						$name = 'DIČ';
						$this->data ['owner']['vatId'] = $iii['value'];
						$this->data ['owner']['vatIdCore'] = substr ($iii['value'], 2);
					}
					else
						if ($iii['property'] == 'oid')
							$name = 'IČ';

					if ($name != '')
						$this->data ['owner_identifiers'][] = array ('name'=> $name, 'value' => $iii['value']);
				}
				if ($iii['group'] == 'contacts')
				{
					$name = $allProperties[$iii['property']]['name'];
					$this->data ['owner_contacts'][] = array ('name'=> $name, 'value' => $iii['value']);
				}
			}
		}

		// author
		$authorNdx = $this->app->user()->data ('id');
		$this->data ['author'] = $this->table->loadItem ($authorNdx, 'e10_persons_persons');
		$this->data ['author']['lists'] = $tablePersons->loadLists ($authorNdx);

		$authorAtt = \E10\Base\getAttachments ($this->table->app(), 'e10.persons.persons', $authorNdx, TRUE);
		$this->data ['author']['signature'] = \E10\searchArray ($authorAtt, 'name', 'podpis');

		if (isset($this->data ['author']['lists']['address'][0]))
			$this->data ['author']['address'] = $this->data ['author']['lists']['address'][0];


		$this->loadData_Rows ();
	}

	public function loadData_Rows ()
	{
		$tableHeads = $this->app()->table ('e10doc.core.heads');

		$q[] = 'SELECT';

		array_push ($q, ' persons.ndx as personNdx, persons.fullName as personFullName, address.specification as specification,');
		array_push ($q, ' [rows].item as item, [rows].unit as unit, [rows].quantity as quantity, [rows].itemType as itemType,');
		array_push ($q, ' heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.warehouse as warehouse');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_address AS address ON heads.otherAddress1 = address.ndx');
		array_push ($q, ' WHERE  1');

		array_push ($q, ' AND heads.person = %i', $this->recData ['ndx']);

		if ($this->inventory)
		{
			array_push ($q, ' AND [rows].invDirection = 1 AND heads.docType IN %in', ['stockin', 'purchase']);
		}
		else
		{
			array_push ($q, ' AND heads.docType IN %in', ['invni', 'purchase']);
			array_push ($q, ' AND [rows].[rowType] = %i', 0);
			array_push ($q, ' AND [rows].[item] != %i', 0);
		}

		array_push ($q, ' AND heads.docState = 4000 AND heads.initState = 0 AND YEAR(heads.dateAccounting) = %i', $this->calendarYear);
		array_push ($q, ' ORDER BY item');

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$itemWasteCode = $this->itemWasteCode ($r['item']);
			if (!$itemWasteCode)
				continue;

			$wasteCode = $itemWasteCode['code'];
			$quantity = $this->quantity($r['quantity'], $r['unit']) / 1000; // tuny
			$docIdentifiers = $tableHeads->docAdditionsOur ($r, $r);
			$id_icz_our = '';
			$id_icp_our = '';
			foreach ($docIdentifiers as $di)
			{
				if ($di['id'] === 'icz')
					$id_icz_our = $di['identifier'];
				elseif ($di['id'] === 'icp')
					$id_icp_our = $di['identifier'];
			}

			$id_icp_theirs = ($r['specification']) ? $r['specification'] : '1';
			$sumRowId = $wasteCode.'-'.$id_icp_our.'-'.$id_icz_our.'-'.$id_icp_theirs;
			if (!isset($this->sumData[$sumRowId]))
			{
				$this->sumData[$sumRowId] = [
					'weight' => 0, 'code' => $itemWasteCode['code'], 'title' => $itemWasteCode['name'],
					'icp_our' => $id_icp_our, 'icz_our' => $id_icz_our, 'icp_theirs' => $id_icp_theirs
				];
			}
			$this->sumData[$sumRowId]['weight'] += $quantity;

			$itemsRowId = $wasteCode.'-'.$r['docNumber'];
			if (!isset($this->itemsData[$itemsRowId]))
			{
				$this->itemsData[$itemsRowId] = [
					'weight' => 0, 'code' => $itemWasteCode['code'], 'title' => $itemWasteCode['name'],
					'docNumber' => $r['docNumber'], 'date' => $r['dateAccounting'], 'o' => $r['docNumber'].'-'.$itemWasteCode['code']
				];
			}
			$this->itemsData[$itemsRowId]['weight'] += $quantity;
		}

		$headerSum = [
			'icp_our' => 'Naše IČP', 'icz_our' => 'Naše IČZ', 'icp_theirs' => 'VAŠE IČP',
			'code' => 'Kód odpadu', 'title' => 'Název', 'weight' => '+Hmotnost [t]'
		];
		$this->data['sumRows'] = [
			[
				'type' => 'table', 'title' => 'Celková množství odpadů, které jsme odebrali v roce '.$this->calendarYear,
				'table' => \e10\sortByOneKey($this->sumData, 'code'), 'header' => $headerSum,
				'params' => ['precision' => 3]
			]
		];

		$headerItems = ['docNumber' => 'Č. dokladu', 'date' => 'Datum', 'code' => 'Kód odpadu', 'title' => 'Název', 'weight' => '+Hmotnost [t]'];
		$this->data['itemsRows'] = [
			[
				'type' => 'table', 'title' => 'Položkový soupis',
				'table' => \e10\sortByOneKey($this->itemsData, 'o'), 'header' => $headerItems,
				'params' => ['precision' => 3, 'tableClass' => 'rowsSmall']]
		];
	}
}
