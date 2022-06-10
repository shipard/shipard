<?php

namespace e10doc\core\libs\reports;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \Shipard\Report\FormReport;
use \Shipard\Utils\World;


/**
 * Class DocReportBase
 * @package e10doc\core\libs\reports
 */
class DocReportBase extends FormReport
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;

	function init ()
	{
		parent::init();
	}

	public function setReportId($baseReportId)
	{

		if (str_starts_with($baseReportId, 'reports.default.'))
		{
			$reportId = $baseReportId;
		}
		else
		{
			$reportType = $this->app()->cfgItem ('options.experimental.docReportsType', 'default');
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
		parent::loadData();

		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->allProperties = $this->app()->cfgItem('e10.base.properties', []);
	}

	function loadData_MainPerson($columnId)
	{
		$personNdx = $this->recData [$columnId];
		$this->data [$columnId] = $this->tablePersons->loadItem($personNdx);
		if ($personNdx)
		{
			$this->lang = $this->data [$columnId]['language'];
			$this->data [$columnId]['lists'] = $this->tablePersons->loadLists($this->data [$columnId]);
			if (isset($this->data [$columnId]['lists']['address'][0]))
				$this->data [$columnId]['address'] = $this->data [$columnId]['lists']['address'][0];
			
			// persons country / language
			World::setCountryInfo($this->app(), $this->data [$columnId]['lists']['address'][0]['worldCountry'], $this->data [$columnId]['address']);
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
				else if ($iii['property'] == 'idcn') $name = 'OP';
				else if ($iii['property'] == 'birthdate') $name = 'DN';
				else if ($iii['property'] == 'pid') $name = 'RČ';

				if ($name === '')
					continue;

				$propertyDef = $this->allProperties [$iii['property']];
				if (isset($propertyDef['personalData']) && $propertyDef['personalData'])
					continue;

				$this->data [$columnId.'_identifiers'][] = ['name' => $name, 'value' => $iii['value']];
			}
		}

		if (isset($this->data [$columnId]['address']['countryNameSC2']))
			$this->country = $this->data [$columnId]['address']['countryNameSC2'];
	}

	function loadData_Author ()
	{
		$this->data ['author'] = $this->tablePersons->loadItem($this->recData ['author']);
		$this->data ['author']['lists'] = $this->tablePersons->loadLists($this->data ['author']);

		$authorAtt = \E10\Base\getAttachments($this->table->app(), 'e10.persons.persons', $this->recData ['author'], TRUE);
		$this->data ['author']['signature'] = \E10\searchArray($authorAtt, 'name', 'podpis');

		if (isset($this->data ['author']['lists']['address'][0]))
			$this->data ['author']['address'] = $this->data ['author']['lists']['address'][0];
	}

	function loadData_DocumentOwner ()
	{
		$this->ownerNdx = $this->recData ['owner'];
		if ($this->ownerNdx == 0)
			$this->ownerNdx = intval($this->app()->cfgItem('options.core.ownerPerson', 0));
		if ($this->ownerNdx)
		{
			$this->data ['owner'] = $this->tablePersons->loadItem($this->ownerNdx);
			$this->data ['owner']['lists'] = $this->tablePersons->loadLists($this->data ['owner']);
			$this->ownerCountry = FALSE;
			if (isset($this->data ['owner']['lists']['address'][0]))
			{
				$this->data ['owner']['address'] = $this->data ['owner']['lists']['address'][0];
				World::setCountryInfo($this->app(), $this->data ['owner']['lists']['address'][0]['worldCountry'], $this->data ['owner']['address']);
				if (isset($this->data ['owner']['address']['countryNameSC2']))
					$this->ownerCountry = $this->data ['owner']['address']['countryNameSC2'];
			}
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

	function loadDataPerson ($columnId)
	{
		if (!isset($this->recData [$columnId]) || !$this->recData [$columnId])
			return;

		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data [$columnId] = $this->table->loadItem ($this->recData [$columnId], 'e10_persons_persons');
		$this->lang = $this->data [$columnId]['language'];
		$this->data [$columnId]['lists'] = $tablePersons->loadLists ($this->data [$columnId]);
		if (isset($this->data [$columnId]['lists']['address'][0]))
			$this->data [$columnId]['address'] = $this->data [$columnId]['lists']['address'][0];
		// persons country
		if (isset ($this->data [$columnId]['lists']['address']) && isset ($this->data [$columnId]['lists']['address'][0]))
		{
			World::setCountryInfo($this->app(), $this->data [$columnId]['lists']['address'][0]['worldCountry'], $this->data [$columnId]['address']);
			if ($this->lang == '' && isset($this->data [$columnId]['address']['countryLangSC2']))
				$this->lang = $this->data [$columnId]['address']['countryLangSC2'];
		}
		forEach ($this->data [$columnId]['lists']['properties'] as $iii)
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

			$this->data [$columnId.'_identifiers'][] = ['name'=> $name, 'value' => $iii['value']];
		}
	}
}
