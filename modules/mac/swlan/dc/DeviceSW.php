<?php

namespace mac\swlan\dc;


/**
 * Class DeviceSW
 * @package mac\swlan\dc
 */
class DeviceSW extends \e10\DocumentCard
{
	/** @var \mac\swlan\libs\SWInfoOnDevice */
	var $swInfo;

	function addDevices()
	{
		if (count( $this->swInfo->swTable))
		{
			$header = [
				'sw' => 'SW', 'version' => 'Verze',
			];

			$this->addContent('body', [
				'pane' => 'e10-pane e10-pane-table', 'table' => $this->swInfo->swTable, 'header' => $header,
				'params' => ['hideHeader' => 1]
			]);
		}
	}

	public function createContentBody ()
	{
		$this->addDevices();
	}

	public function createContent ()
	{

		$this->swInfo = new \mac\swlan\libs\SWInfoOnDevice($this->app());
		$this->swInfo->init();
		$this->swInfo->setDevice($this->recData['ndx']);
		$this->swInfo->run();

		$this->createContentBody ();
	}
}
