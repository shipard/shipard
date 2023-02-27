<?php

namespace Shipard\UI\ng;

use E10\utils;


/**
 * Class StartMenu
 * @package mobileui
 */
class StartMenu extends \Shipard\UI\ng\AppPageBlank
{
	public function createContent ()
	{
		$this->content['start'] = ['name' => 'Start', 'type' => 'tiles', 'order' => 100000, 'items' => []];

		// -- user info
		$this->pageInfo['userInfo'] = ['name' => $this->app->user()->data('name'), 'login' => $this->app->user()->data('login')];
	}

	public function title1 ()
	{
		return $this->app->cfgItem ('options.core.ownerShortName');
	}

	public function title2 ()
	{
		return $this->app->user()->data('name');
	}

	public function pageTitle()
	{
		return $this->title1();
	}

	public function createContentCodeInside ()
	{
		$c = 'TADY NĚCO BUDE!';

		return $c;
	}

	public function pageType () {return 'home';}
}

