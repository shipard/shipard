<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class DCSetup
 */
class DCSetup extends \mac\iot\libs\dc\DCEventBased
{
	public function createContentBody ()
	{
		// -- eventsOn
		$item = [
			'handle' => ['text' => 'Události', 'class' => 'block h1'],
			'_options' => ['colSpan' => ['handle' => 2]]
		];
		$this->rows[] = $item;
		$this->addEventsOn('mac.iot.setups', $this->recData['ndx']);

		$h = ['handle' => '', 'content' => 'test'];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $this->rows, 'params' => ['hideHeader' => 1, '__forceTableClass' => 'properties fullWidth']]);
	}

	public function createContent ()
	{
		$this->init();
		$this->createContentBody ();
	}
}
