<?php

namespace Shipard\UI\OldMobile;


/**
 * Class Report
 * @package mobileui
 */
class Report extends \Shipard\UI\OldMobile\PageObject
{
	var $report = NULL;

	public function createContent ()
	{
		$this->report = $this->app->createObject ($this->definition['class']);
		if ($this->report)
		{
			$this->report->format = 'widget';
			$this->report->mobile = TRUE;
			$this->report->init();
			$this->report->createContent();
		}
	}

	public function createContentCodeInside ()
	{
		$c = '';
		$c .= $this->report->createReportContent ();
		return $c;
	}

	public function createContentCodeBegin ()
	{
		$c = '';
		return $c;
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
		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0'], 'backButton' => 1];
		return $lmb;
	}

	public function pageType () {return 'report';}
}
