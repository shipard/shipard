<?php

namespace e10pro\zus\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCAttendance
 */
class DCAttendance extends \Shipard\Base\DocumentCard
{
  var $calendarYear = 0;
	var $calendarMonth = 0;
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  public function addCoreInfo()
  {
    $ae = new \e10pro\zus\libs\AttendanceEngine($this->app());
    $ae->setParams($this->recData['person'], $this->recData['ndx'], $this->periodBegin, $this->periodEnd);
    $ae->loadData();
    $ae->createContent();

    if ($ae->workingHoursInfoNdx)
    {
      $whContent = $ae->workingHoursInfo->weeklyContent;
      $whContent['pane'] = 'e10-pane e10-pane-table e10-ds '.$ae->workingHoursInfo->docStateClass;

      $title = [['text' => 'Pracovní doba', 'class' => 'h2']];
      $title[] = ['text' => Utils::dateFromTo($ae->workingHoursInfo->workingHoursRecData['validFrom'], $ae->workingHoursInfo->workingHoursRecData['validTo'], NULL), 'class' => 'pull-right'];
      $whContent['paneTitle'] = $title;
      $this->addContent('body', $whContent);
    }

    $this->addContent([
      'pane' => 'e10-pane e10-pane-table',
      'header' => $ae->contentHeader,
      'table' => $ae->contentTable, 'title' => 'Docházka', 'params' => ['___hideHeader' => 1]
    ]);
  }

  public function createContent ()
	{
		$cm = $this->app()->testGetParam('calendarMonth');

    $this->calendarYear = intval(substr($cm, 0, 4));
    $this->calendarMonth = intval(substr($cm, 4, 2));
		$this->periodBegin = Utils::createDateTime(sprintf('%4d-%02d-01', $this->calendarYear, $this->calendarMonth));
		if (!$this->periodBegin)
			return;

		$this->periodEnd = Utils::createDateTime(sprintf('%4d-%02d-', $this->calendarYear, $this->calendarMonth).$this->periodBegin->format('t'));

    $this->addCoreInfo();
	}
}
