<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class DCScene
 */
class DCScene extends \mac\iot\libs\dc\DCEventBased
{
	public function createContentBody ()
	{
		// -- settings - eventsDo
		$item = [
			'handle' => ['text' => 'Nastavení scény', 'class' => 'block h1'],
			'_options' => ['colSpan' => ['handle' => 2]]
		];
		$this->rows[] = $item;
		$this->bgClass = $this->eventsBgs[$this->eventsBgsIdx];
		$this->addEventsDo('mac.iot.scenes', $this->recData['ndx'], 0);
		$this->eventsBgsIdx++;

		// -- eventsOn
		$item = [
			'handle' => ['text' => 'Události', 'class' => 'block h1'],
			'_options' => ['colSpan' => ['handle' => 2], 'beforeSeparator' => 'separator']
		];
		$this->rows[] = $item;
		$this->addEventsOn('mac.iot.scenes', $this->recData['ndx']);

		$h = ['handle' => '', 'content' => 'test'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $this->rows,
			'params' => ['hideHeader' => 1, '_tableClass' => 'stripped']
		]);
	}

	public function createContent ()
	{
		$this->init();
		$this->createContentBody ();
	}
}
