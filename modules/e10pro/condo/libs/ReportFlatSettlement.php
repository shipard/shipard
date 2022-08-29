<?php

namespace e10pro\condo\libs;
use \Shipard\Report\FormReport;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;


/**
 * class ReportFlatSettlement
 */
class ReportFlatSettlement extends FormReport
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;


	function init ()
	{
		$this->reportId = 'e10pro.condo.flatsettlement';
		$this->reportTemplate = 'reports.modern.e10pro.condo.flatsettlement';
	}

	public function loadData ()
	{
    parent::loadData();

		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->allProperties = $this->app()->cfgItem('e10.base.properties', []);


		$this->data['calcReport'] = $this->app()->loadItem($this->recData['report'], 'e10doc.reporting.calcReports');
		$this->data['workOrder'] = $this->app()->loadItem($this->recData['workOrder'], 'e10mnf.core.workOrders');
		$this->data['person'] = $this->app()->loadItem($this->data['workOrder']['customer'], 'e10.persons.persons');


		$this->loadData_DocumentOwner ();

    $resContent = Json::decode($this->recData['resContent']);

    $this->data['contents'] = $resContent;
	}

	function loadData_DocumentOwner ()
	{
		//$this->ownerNdx = $this->recData ['owner'];
		//if ($this->ownerNdx == 0)
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
}