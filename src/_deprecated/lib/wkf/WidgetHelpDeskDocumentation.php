<?php

namespace lib\wkf;


/**
 * Class WidgetHelpDeskDocumentation
 * @package lib\wkf
 */
class WidgetHelpDeskDocumentation extends \lib\wkf\WidgetIframe
{
	public function init()
	{
		$this->url = 'https://doc.shipard.app';

		parent::init();
	}
}
