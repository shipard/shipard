<?php

namespace e10pro\soci\libs;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;


/**
 * class ReportWOEventReturnList
 */
class ReportWOEventReturnList extends \e10doc\core\libs\reports\DocReportBase
{
	var \e10pro\soci\libs\WOEventInfo $eventInfo;

	function init ()
	{
		$this->reportId = 'e10pro.soci.woEventReturnList';
		$this->reportTemplate = 'reports.modern.e10pro.soci.woeventreturnlist';
		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
    $this->sendReportNdx = 5002;

    parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->data['person'] = $this->app()->loadItem($this->data['workOrder']['customer'], 'e10.persons.persons');

    if ($this->recData['place'])
      $this->data['place'] = $this->app()->loadItem($this->recData['place'], 'e10.base.places');

    $linkedPersons = UtilsBase::linkedPersons ($this->table->app(), 'e10mnf.core.workOrders', $this->recData['ndx']);
		if ($linkedPersons && isset ($linkedPersons [$this->recData['ndx']]['e10mnf-workRecs-admins']))
		{
      $admins = [];
      foreach ($linkedPersons [$this->recData['ndx']]['e10mnf-workRecs-admins'] as $lp)
        $admins[] = $lp['text'];

      $this->data['eventAdmins'] = implode(', ', $admins);
		}

    $now = new \DateTime();
    $this->data['printDateTime'] = Utils::datef($now, '%d%t');

    $this->eventInfo = new \e10pro\soci\libs\WOEventInfo($this->app());
    $this->eventInfo->forPrint = 1;
    $this->eventInfo->setWorkOrder($this->recData['ndx']);
    $this->eventInfo->loadInfo();

    $this->data['members']= $this->eventInfo->data['members']['table'];
    $this->data['peoples']= $this->eventInfo->data['peoples'];

    if ($this->eventInfo->data['members'])
    {
      $cc = $this->eventInfo->data['members'];
      unset($cc['pane']);
      $this->data['contents'][] = $cc;
    }
	}
}
