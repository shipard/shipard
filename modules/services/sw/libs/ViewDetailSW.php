<?php

namespace services\sw\libs;


use \e10\TableViewDetail, \e10\utils, \e10\json;


/**
 * Class ViewDetailSW
 * @package services\sw\libs
 */
class ViewDetailSW extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.sw.dc.SW');
	}
}
