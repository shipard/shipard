<?php
namespace e10doc\accBal\libs;

use \Shipard\Viewer\TableViewDetail;



class AccBalanceViewerDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => 'POKUS: '.$this->item['ndx']));
	}
}
