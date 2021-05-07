<?php

namespace mac\swlan\libs;


use \e10\TableViewDetail, \e10\utils, \e10\json;



/**
 * Class ViewDetailSW
 * @package mac\swlan\libs
 */
class ViewDetailSW extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.swlan.dc.SW');
	}
}
