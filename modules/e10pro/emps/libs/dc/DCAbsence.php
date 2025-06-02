<?php

namespace e10pro\emps\libs\dc;


/**
 * class DCAbsence
 */
class DCAbsence extends \Shipard\Base\DocumentCard
{
	protected function addDetail()
	{
		$ae = new \e10pro\zus\libs\AbsenceEngine($this->app());
		$ae->setAbsence($this->recData['ndx']);
		$ae->loadData();

    $this->addContent([
      'pane' => 'e10-pane e10-pane-table',
      'header' => $ae->timeTableHeader,
      'table' => $ae->timeTableTable,
      'title' => 'Dotčená výuka', '_params' => ['hideHeader' => 1]
    ]);
	}

	public function createContentBody ()
	{
		$this->addDetail();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
