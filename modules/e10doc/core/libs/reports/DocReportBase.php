<?php

namespace e10doc\core\libs\reports;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \Shipard\Report\FormReport;
use \Shipard\Utils\World;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;

/**
 * Class DocReportBase
 * @package e10doc\core\libs\reports
 */
class DocReportBase extends FormReport
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons = NULL;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;

	var $testNewPersons = 0;

	function init ()
	{
		parent::init();
		$this->testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
	}

	public function setReportId($baseReportId)
	{
		if (str_starts_with($baseReportId, 'reports.default.'))
		{
			$reportId = $baseReportId;
		}
		else
		{
			$reportType = $this->app()->cfgItem ('options.appearanceDocs.docReportsType', 'default');
			$reportIdBegin = 'reports.'.$reportType.'.';
			$reportId = $reportIdBegin.$baseReportId;

			$parts = explode ('.', $reportId);
			$tfn = array_pop ($parts);
			$templateRoot = __SHPD_ROOT_DIR__.__SHPD_TEMPLATE_SUBDIR__.'/'.implode ('/', $parts).'/'.$tfn.'/';
			$templateMainFile = $templateRoot.'page.mustache';
			if (!is_readable($templateMainFile))
			{
				$reportType = 'default';
				$reportIdBegin = 'reports.'.$reportType.'.';
				$reportId = $reportIdBegin.$baseReportId;
			}
		}

		$this->reportId = $reportId;
		$this->reportTemplate = $reportId;
	}

	public function loadData()
	{
		if (!$this->tablePersons)
			$this->tablePersons = $this->app()->table('e10.persons.persons');

		$this->testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
		$this->allProperties = $this->app()->cfgItem('e10.base.properties', []);

		parent::loadData();


		$this->data['options']['docReportsPersonsSigns'] = intval($this->app()->cfgItem ('options.appearanceDocs.docReportsPersonsSigns', 0));
		$this->data['options']['docReportsHeadLogoRight'] = intval($this->app()->cfgItem ('options.appearanceDocs.docReportsHeadLogoPlace', 1));
		$this->data['options']['docReportsTablesRoundedCorners'] = intval($this->app()->cfgItem ('options.appearanceDocs.docReportsTablesCorners', 1));
		$this->data['options']['accentColor'] = $this->app()->cfgItem ('options.appearanceDocs.accentColor', '');
		if ($this->data['options']['accentColor'] === '')
			$this->data['options']['accentColor'] = '#CFECEC';

		// -- texts
		/** @var \e10\reports\TableReportsTexts */
		$tableReportsTexts = $this->app()->table('e10.reports.reportsTexts');
		$this->data ['reportTexts'] ??= [];
		$tableReportsTexts->loadReportTexts($this, $this->data ['reportTexts']);
		if (count($this->data ['reportTexts']))
		{
			$this->data ['_subtemplatesItems'] ??= [];
			if (!count($this->data ['_subtemplatesItems']))
				$this->data ['_subtemplatesItems'][] = 'reportTexts';
			$this->data ['_textRenderItems'] ??= [];
			if (!count($this->data ['_textRenderItems']))
				$this->data ['_textRenderItems'][] = 'reportTexts';
		}
	}

	function loadData_MainPerson($columnId, $personNdx = 0)
	{
		if (!$this->tablePersons)
			$this->tablePersons = $this->app()->table('e10.persons.persons');

		if (!$personNdx)
			$personNdx = $this->recData [$columnId];

		$this->data [$columnId] = $this->tablePersons->loadItem($personNdx);
		if ($personNdx)
		{
			$this->lang = $this->data [$columnId]['language'];
			$this->data [$columnId]['lists'] = $this->tablePersons->loadLists($this->data [$columnId]);

			if ($this->testNewPersons)
			{
				$this->data [$columnId]['address'] = $this->loadPersonAddress($personNdx, mainAddress: 1);
			}
			else
			{
				if (isset($this->data [$columnId]['lists']['address'][0]))
					$this->data [$columnId]['address'] = $this->data [$columnId]['lists']['address'][0];
			}
			// persons country / language
			$this->data [$columnId]['lists']['address'] = [];
			$taxHomeCountryId = E10Utils::docTaxHomeCountryId($this->app(), $this->recData);
			$taxHomeCountryNdx = World::countryNdx($this->app(), $taxHomeCountryId);
			if (isset($this->data [$columnId]['address']))
				World::setCountryInfo($this->app(), $this->data [$columnId]['address']['worldCountry'] ?? $taxHomeCountryNdx, $this->data [$columnId]['address']);
			if ($this->lang == '' && isset($this->data [$columnId]['address']['countryLangSC2']))
				$this->lang = $this->data [$columnId]['address']['countryLangSC2'];

			if (!in_array($this->lang, ['de', 'en', 'it', 'sk', 'cs']))
				$this->lang = 'en';
		}
		else
			$this->lang = 'cs';

		if (isset($this->data [$columnId]['lists']['properties']))
		{
			foreach ($this->data [$columnId]['lists']['properties'] as $iii)
			{
				if ($iii['group'] != 'ids')
					continue;
				$name = '';
				if ($iii['property'] == 'taxid') $name = 'DIČ';
				else if ($iii['property'] == 'oid') $name = 'IČ';
				else if ($iii['property'] == 'cz_icob') $name = 'IČOB';
				else if ($iii['property'] == 'idcn') $name = 'OP';
				else if ($iii['property'] == 'birthdate') $name = 'DN';
				else if ($iii['property'] == 'pid') $name = 'RČ';

				if ($name === '')
					continue;

				$propertyDef = $this->allProperties [$iii['property']] ?? [];
				if (isset($propertyDef['personalData']) && $propertyDef['personalData'])
					continue;

				$this->data [$columnId.'_identifiers'][] = ['name' => $name, 'value' => $iii['value']];
			}
		}

		if (isset($this->data [$columnId]['address']['countryNameSC2']))
			$this->country = $this->data [$columnId]['address']['countryNameSC2'];
	}

	function loadData_Author ($authorNdx = 0)
	{
		if (!$authorNdx)
			$authorNdx = $this->recData ['author'];

		$this->data ['author'] = $this->tablePersons->loadItem($authorNdx);
		$this->data ['author']['lists'] = $this->tablePersons->loadLists($authorNdx);

		$authorAtt = \E10\Base\getAttachments($this->table->app(), 'e10.persons.persons', $authorNdx, TRUE);
		$this->data ['author']['signature'] = Utils::searchArray($authorAtt, 'name', 'podpis');
		if (isset($this->data ['author']['signature']))
			$this->data ['author']['signature']['rfn'] = 'att/'.$this->data ['author']['signature']['path'].$this->data ['author']['signature']['filename'];

		if (isset($this->data ['author']['lists']['address'][0]))
			$this->data ['author']['address'] = $this->data ['author']['lists']['address'][0];
	}

	function loadData_DocumentOwner ()
	{
		$this->ownerNdx = $this->recData ['owner'] ?? 0;
		if ($this->ownerNdx == 0)
			$this->ownerNdx = intval($this->app()->cfgItem('options.core.ownerPerson', 0));
		if ($this->ownerNdx)
		{
			$this->data ['owner'] = $this->tablePersons->loadItem($this->ownerNdx);
			$this->data ['owner']['lists'] = $this->tablePersons->loadLists($this->data ['owner']);

			if ($this->testNewPersons)
			{
				$this->data ['owner']['address'] = $this->loadPersonAddress($this->ownerNdx, mainAddress: 1);
			}
			else
			{
				$this->ownerCountry = FALSE;
				if (isset($this->data ['owner']['lists']['address'][0]))
				{
					$this->data ['owner']['address'] = $this->data ['owner']['lists']['address'][0];
				}
			}

			if (!isset($this->data ['owner']['address']))
				$this->data ['owner']['address'] = ['worldCountry' => 60];

			World::setCountryInfo($this->app(), intval($this->data ['owner']['address']['worldCountry']), $this->data ['owner']['address']);
			if (isset($this->data ['owner']['address']['countryNameSC2']))
				$this->ownerCountry = $this->data ['owner']['address']['countryNameSC2'];

			foreach ($this->data ['owner']['lists']['properties'] as $iii)
			{
				if ($iii['group'] == 'ids')
				{
					$name = '';
					if ($iii['property'] == 'taxid')
					{
						$name = 'DIČ';
						$this->data ['owner']['vatId'] = $iii['value'];
						$this->data ['owner']['vatIdCore'] = substr($iii['value'], 2);
					}
					else
						if ($iii['property'] == 'oid')
							$name = 'IČ';

					if ($name != '')
						$this->data ['owner_identifiers'][] = array('name' => $name, 'value' => $iii['value']);
				}
				if ($iii['group'] == 'contacts')
				{
					$name = $this->allProperties[$iii['property']]['name'];
					$this->data ['owner_contacts'][] = array('name' => $name, 'value' => $iii['value']);
				}
			}

			$ownerAtt = \E10\Base\getAttachments($this->table->app(), 'e10.persons.persons', $this->ownerNdx, TRUE);
			foreach ($ownerAtt as $oa)
			{
				$this->data ['owner']['logo'][$oa['name']] = $oa;
				$this->data ['owner']['logo'][$oa['name']]['rfn'] = 'att/'.$oa['path'].$oa['filename'];
			}
		}
	}

	function loadPersonAddress($personNdx, $mainAddress = 0, $addressNdx = 0)
	{
		if (!$personNdx && !$addressNdx)
			return [];

		$q = [];
		array_push($q, 'SELECT [addrs].*');
		array_push($q, ' FROM [e10_persons_personsContacts] AS [addrs]');
		array_push($q, ' WHERE 1');
		if ($personNdx)
		{
			array_push($q, ' AND [addrs].[person] = %i', $personNdx);
			array_push($q, ' AND [addrs].[docState] = %i', 4000);
		}

		array_push($q, ' AND [addrs].[flagAddress] = %i', 1);
		if ($mainAddress)
			array_push($q, ' AND [addrs].[flagMainAddress] = %i', 1);

		if ($addressNdx)
			array_push($q, ' AND [addrs].[ndx] = %i', $addressNdx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$addr = [
        'ndx' => $r['ndx'],
        'recid' => $r['person'],
        'specification' => $r['adrSpecification'],
        'street' => $r['adrStreet'],
        'city' => $r['adrCity'],
        'zipcode' => $r['adrZipCode'],
        'worldCountry' => $r['adrCountry'],



				'ids' => [],
			];

			if ($r['id1'] !== '')
				$addr['ids'][] = ['title' => 'IČP', 'value' => $r['id1']];
			if ($r['id2'] !== '')
				$addr['ids'][] = ['title' => 'IČZ', 'value' => $r['id2']];

			return $addr;
		}
	}

	function loadDataPerson ($columnId)
	{
		if (!isset($this->recData [$columnId]) || !$this->recData [$columnId])
			return;
		$personNdx = $this->recData [$columnId] ?? 0;
		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data [$columnId] = $this->table->loadItem ($personNdx, 'e10_persons_persons');
		//$this->lang = $this->data [$columnId]['language'];
		$this->data [$columnId]['lists'] = $tablePersons->loadLists ($this->data [$columnId]);

		if ($this->testNewPersons)
		{
			$this->data [$columnId]['address'] = $this->loadPersonAddress($personNdx, mainAddress: 1);
		}
		else
		{
			if (isset($this->data [$columnId]['lists']['address'][0]))
				$this->data [$columnId]['address'] = $this->data [$columnId]['lists']['address'][0];
		}
		// persons country
		if (isset ($this->data [$columnId]['address']) && isset ($this->data [$columnId]['address']['worldCountry']))
		{
			World::setCountryInfo($this->app(), $this->data [$columnId]['address']['worldCountry'], $this->data [$columnId]['address']);
			//if ($this->lang == '' && isset($this->data [$columnId]['address']['countryLangSC2']))
			//	$this->lang = $this->data [$columnId]['address']['countryLangSC2'];
		}
		forEach ($this->data [$columnId]['lists']['properties'] as $iii)
		{
			if ($iii['group'] != 'ids') continue;
			$name = '';
			if ($iii['property'] == 'taxid') $name = 'DIČ';
			else if ($iii['property'] == 'oid') $name = 'IČ';
			else if ($iii['property'] == 'cz_icob') $name = 'IČOB';
			else if ($iii['property'] == 'idcn') $name = 'OP';
			else if ($iii['property'] == 'birthdate') $name = 'DN';
			else if ($iii['property'] == 'pid') $name = 'RČ';

			if ($name === '')
				continue;

			$this->data [$columnId.'_identifiers'][] = ['name'=> $name, 'value' => $iii['value']];
		}
	}
}
