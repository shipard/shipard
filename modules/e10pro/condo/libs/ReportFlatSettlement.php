<?php

namespace e10pro\condo\libs;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;


/**
 * class ReportFlatSettlement
 */
class ReportFlatSettlement extends \e10doc\core\libs\reports\DocReportBase
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

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
}
