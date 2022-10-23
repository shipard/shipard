<?php

namespace Shipard\UI\OldMobile;


/**
 * Class Viewer
 */
class Viewer extends \Shipard\UI\OldMobile\PageObject
{
	var $viewer = NULL;

	public function createContent ()
	{
		$this->viewer = $this->app->viewer($this->definition['table'], $this->definition['viewer']);
		$this->viewer->mobile = TRUE;
		$this->viewer->init();
		$this->viewer->selectRows();
		$this->viewer->renderViewerData ('html');
	}

	public function createContentCodeInside ()
	{
		$c = '';
		$c .= $this->viewer->createViewerCode('html', 1);
		return $c;
	}

	public function createContentCodeBegin ()
	{
		$this->pageInfo['viewerScroll'] = 1;
		return '';
	}

	public function createContentCodeEnd ()
	{
		$c = '';

		return $c;
	}

	public function title1 ()
	{
		return $this->definition['t1'];
	}

	public function leftPageHeaderButton ()
	{
		if ($this->embeddMode)
			return NULL;

		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0'], 'backButton' => 1];
		return $lmb;
	}

	public function rightPageHeaderButtons ()
	{
		$rmbs = [];
		$b = ['icon' => 'system/iconSearch', 'action' => 'viewer-search'];
		$rmbs[] = $b;

/*
		$b = ['icon' => 'icon-ellipsis-v', 'path' => '#'];
		$rmbs[] = $b;
*/

		return $rmbs;
	}

	public function pageType () {return 'viewer';}
}
