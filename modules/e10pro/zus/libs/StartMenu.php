<?php

namespace e10pro\zus\libs;
use \Shipard\Base\Utility;


/**
 * class StartMenu
 */
class StartMenu extends Utility
{
	public function create (&$content)
	{
    $content ['start']['items'][] = [
      "t1" => "Docházka", "object" => "dashboard", "dashboard" => "workInProgress", "icon" => "icon-dashboard", "order" => 27100,
    ];
	}
}
