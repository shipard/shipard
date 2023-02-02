<?php

namespace e10pro\condo\libs;
use \Shipard\Utils\Json;


/**
 * class ReportFlatSettlement
 */
class ReportFlatSettlement extends \e10doc\core\libs\reports\DocReportBase
{
	function init ()
	{
		$this->reportId = 'e10pro.condo.flatsettlement';
		$this->reportTemplate = 'reports.modern.e10pro.condo.flatsettlement';
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2801;

    parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->data['calcReport'] = $this->app()->loadItem($this->recData['report'], 'e10doc.reporting.calcReports');
		$this->data['workOrder'] = $this->app()->loadItem($this->recData['workOrder'], 'e10mnf.core.workOrders');
		$this->data['person'] = $this->app()->loadItem($this->data['workOrder']['customer'], 'e10.persons.persons');

		$this->data['overpaid'] = 0;
		$this->data['underpaid'] = 0;

		if ($this->recData['finalAmount'] < 0.0)
		{
			$this->data['underpaid'] = 1;
		}
		elseif ($this->recData['finalAmount'] > 0.0)
		{
			$this->data['overpaid'] = 1;
		}

    $resContent = Json::decode($this->recData['resContent']);

    $this->data['contents'] = $resContent;
	}
}
