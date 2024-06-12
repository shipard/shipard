<?php

namespace e10doc\slr\dc;
use \Shipard\Utils\Json;


/**
 * class DCEmpRec
 */
class DCEmpRec extends \Shipard\Base\DocumentCard
{
	protected function addRows()
	{
    $ae = new \e10doc\slr\libs\AccEngine($this->app());
    $ae->setEmpRec($this->recData['ndx']);
    $ae->loadData();

    $this->addContent('body',  [
      'pane' => 'e10-pane e10-pane-table',
      'table' => $ae->detailOverviewTable, 'header' => $ae->detailOverviewHeader,
    ]);
	}

	public function createContentBody ()
	{
		$this->addRows();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
