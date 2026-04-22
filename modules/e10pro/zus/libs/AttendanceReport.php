<?php

namespace e10pro\zus\libs;
//require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\Utils;


/**
 * class AttendanceReport
 */
class AttendanceReport extends \Shipard\Report\GlobalReport
{
  var $calendarYear = 0;
	var $calendarMonth = 0;
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $workingHoursNdx = 0;

  function init ()
	{
		//$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);
    $this->addParam ('calendarMonth', 'calendarPeriod', ['__flags' => ['quarters', 'halfs', 'years'],]);
    $this->addPersonsParam();

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.vyuky');
		$this->setInfo('title', 'Docházka');

		$cm = $this->reportParams ['calendarPeriod']['value'];
    $this->calendarYear = intval(substr($cm, 0, 4));
    $this->calendarMonth = intval(substr($cm, 4, 2));
		$this->periodBegin = utils::createDateTime(sprintf('%4d-%02d-01', $this->calendarYear, $this->calendarMonth));
		$this->periodEnd = Utils::createDateTime(sprintf('%4d-%02d-', $this->calendarYear, $this->calendarMonth).$this->periodBegin->format('t'));

    $this->workingHoursNdx = intval($this->reportParams ['workingHours']['value']);
  }

	function createContent ()
	{
		$this->doWorkingHours();
	}

	protected function doWorkingHours()
	{
    $q = [];
    array_push ($q, 'SELECT wh.*,');
		array_push ($q, ' persons.fullName AS personName');
		array_push ($q, ' FROM [e10pro_emps_workingHours] AS wh');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON wh.person = persons.ndx');
		array_push ($q, ' WHERE 1');
    if ($this->workingHoursNdx)
      array_push ($q, ' AND wh.ndx = %i', $this->workingHoursNdx);

    array_push ($q, ' AND wh.docState IN %in', [4000, 8000]);
		array_push ($q, ' AND (wh.validFrom IS NULL OR wh.validFrom <= %d', $this->periodBegin, ')');
		array_push ($q, ' AND (wh.validTo IS NULL OR wh.validTo >= %d', $this->periodEnd, ')');
    array_push ($q, ' ORDER BY [persons].[lastName], [persons].[firstName] ');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $ae = new \e10pro\zus\libs\AttendanceEngine($this->app());
      $ae->setParams($r['person'], $r['ndx'], $this->periodBegin, $this->periodEnd);
      $ae->loadData();
      $ae->createContent();

      /*
      $whContent = $ae->workingHoursInfo->weeklyContent;
      //$whContent['pane'] = 'e10-pane e10-pane-table e10-ds '.$ae->workingHoursInfo->docStateClass;
      $whContent['paneTitle'] = $ae->workingHoursInfo->title;
      $this->addContent($whContent);
      */

      $title = ['text' => $r['personName'], 'suffix' => Utils::dateFromTo($this->periodBegin, $this->periodEnd, NULL)];

      $this->addContent([
        'pane' => '',
        'header' => $ae->contentHeader,
        'table' => $ae->contentTable, 'title' => $title, 'params' => ['tableClass' => 'pageBreakAfter']
      ]);
    }
	}

  protected function addPersonsParam()
  {
    $enum = [];
    $enum[0] = 'Vše';

		$q = [];
    array_push ($q, 'SELECT wh.*,');
		array_push ($q, ' persons.fullName AS personName');
		array_push ($q, ' FROM [e10pro_emps_workingHours] AS wh');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON wh.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		//array_push ($q, ' AND (wh.validFrom IS NULL OR wh.validFrom <= %d', $this->periodBegin, ')');
		//array_push ($q, ' AND (wh.validTo IS NULL OR wh.validTo >= %d', $this->periodEnd, ')');
    array_push ($q, ' ORDER BY [persons].[lastName], [persons].[firstName] ');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $enum[$r['ndx']] = $r['personName'];
    }

    $this->addParam('switch', 'workingHours', ['title' => 'Pracovník', 'switch' => $enum, '__defaultValue' => 'all']);
  }
}
