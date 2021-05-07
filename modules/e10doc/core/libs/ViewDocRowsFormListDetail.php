<?php

namespace e10doc\core\libs;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail;


/**
 * Class ViewDocRowsFormListDetail
 * @package e10doc\core\libs
 */
class ViewDocRowsFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'řádek #'.$this->item['ndx']]]);
	}
}
