<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class IoTDevice
 */
class IoTDevice extends \Shipard\Base\DocumentCard
{
	var $scriptGenerator = NULL;

	public function createContentBody ()
	{
		//$tabs = [];

		//$this->createContentBody_IotBox($tabs);

		// -- final content
		//$this->addContent('body', ['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
