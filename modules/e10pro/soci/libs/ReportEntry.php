<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;


/**
 * ReportEntry
 */
class ReportEntry extends \e10doc\core\libs\reports\DocReportBase
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;

	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.soci.entry';
		$this->reportTemplate = 'reports.modern.e10pro.soci.entry';

		parent::init();
	}

	public function loadData ()
	{
		$this->sendReportNdx = 5003;

		parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->allProperties = $this->app()->cfgItem('e10.base.properties', []);

		parent::loadData();


    $entryTo = $this->app()->loadItem($this->recData['entryTo'], 'e10mnf.core.workOrders');
    $this->data['entryTo'] = $entryTo;

    $vdsData = Json::decode($entryTo['vdsData']);
    if ($vdsData)
    {
      $this->data['entryToInfo'] = $vdsData;
    }

    if ($entryTo['place'])
      $this->data['place'] = $this->app()->loadItem($entryTo['place'], 'e10.base.places');

    $linkedPersons = UtilsBase::linkedPersons ($this->table->app(), 'e10mnf.core.workOrders', $this->recData['entryTo']);
		if ($linkedPersons && isset ($linkedPersons [$this->recData['entryTo']]['e10mnf-workRecs-admins']))
		{
      $admins = [];
      foreach ($linkedPersons [$this->recData['entryTo']]['e10mnf-workRecs-admins'] as $lp)
        $admins[] = $lp['text'];

      $this->data['eventAdmins'] = implode(', ', $admins);
		}

		$this->data ['webSentDate'] = Utils::datef($this->recData['webSentDate'], '%d, %T');
	}
}
