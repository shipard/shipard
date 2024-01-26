<?php

namespace e10pro\soci\libs;


/**
 * class ReportFlatRecordSheet
 */
class ReportWOEventRecordSheet extends \e10doc\core\libs\reports\DocReportBase
{
	var \e10pro\soci\libs\WOEventInfo $eventInfo;

	function init ()
	{
		$this->reportId = 'e10pro.soci.woEventRecordSheet';
		$this->reportTemplate = 'reports.modern.e10pro.soci.woeventrecordsheet';
		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
    $this->sendReportNdx = 5001;

    parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->data['workOrder'] = $this->app()->loadItem($this->recData['workOrder'], 'e10mnf.core.workOrders');
		$this->data['person'] = $this->app()->loadItem($this->data['workOrder']['customer'], 'e10.persons.persons');

    $this->eventInfo = new \e10pro\soci\libs\WOEventInfo($this->app());
    $this->eventInfo->setWorkOrder($this->recData['ndx']);
    $this->eventInfo->loadInfo();

    if ($this->eventInfo->data['personsList'])
    {
      $contentTitlePersons = ['text' => 'Lidé', 'class' => 'h3'];
      $cc = $this->eventInfo->data['personsList'];
      unset($cc['pane']);
      $cc['title'] = $contentTitlePersons;
      $this->data['contents'][] = $cc;
    }
	}
}
