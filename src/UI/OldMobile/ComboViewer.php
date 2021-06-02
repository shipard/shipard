<?php

namespace ui\mobile;


/**
 * Class ComboViewer
 * @package ui\mobile
 */
class ComboViewer extends \ui\mobile\PageObject
{
	/** @var \e10\TableView */
	var $viewer = NULL;

	public function createContent ()
	{
		$tableId = $this->app->requestPath(2);
		$viewerId = $this->app->requestPath(3);

		$this->viewer = $this->app->viewer($tableId, $viewerId);
		$this->viewer->mobile = TRUE;
		$this->viewer->rowAction = 'call';
		$this->viewer->rowActionClass = 'e10.form.comboViewerDone';
		$this->viewer->comboSettings = [];
		$this->viewer->init();
		$this->viewer->selectRows();
		$this->viewer->renderViewerData ('html');
	}

	public function createPageCodeTitle ()
	{
		return '';
	}

	public function createContentCodeInside ()
	{
		$c = '';
		$c .= $this->viewer->createViewerCode('html', 1);
		return $c;
	}

	public function createContentCodeBegin ()
	{
		return '';
	}

	public function createContentCodeEnd ()
	{
		return '';
	}

	public function title1 ()
	{
		return '';
	}

	public function leftPageHeaderButton ()
	{
		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0'], 'backButton' => 1];
		return $lmb;
	}

	public function rightPageHeaderButtons ()
	{
		$rmbs = [];
		return $rmbs;
	}

	public function pageType () {return 'viewer';}
}
