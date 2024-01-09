<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewDetailVyukaKlasifikace
 */
class ViewDetailVyukaKlasifikace extends TableViewDetail
{

	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.DCVyukaKlasifikace');
	}
}
