<?php

namespace swdev\world\libs;

use e10\utils;


/**
 * Class CfgReport
 * @package swdev\world\libs
 */
class CfgReport extends \e10\GlobalReport
{
	var $data = [];
	var $files = [];
	var $lanNdx = 1;

	/** @var \swdev\world\libs\CfgGenerator */
	var $cfgGenerator;

	function init ()
	{
		parent::init();
	}

	function createContent()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'countries': $this->createContent_Countries(); break;
			case 'currencies': $this->createContent_Currencies(); break;
			case 'languages': $this->createContent_Languages(); break;
		}
	}

	function createContent_Countries()
	{
		$this->setInfo('title', 'Země');

		$this->cfgGenerator->generateCountries();
		$code = '<pre><code>';
		$code .= $this->cfgGenerator->texts['countries']['json'];
		$code .= '</code></pre>';
		$this->addContent (['type' => 'line', 'line' => ['code' => $code, 'class' => 'e10-pane']]);
	}

	function createContent_Currencies()
	{
		$this->setInfo('title', 'Měny');

		$this->cfgGenerator->generateCurrencies();
		$code = '<pre><code>';
		$code .= $this->cfgGenerator->texts['currencies']['json'];
		$code .= '</code></pre>';
		$this->addContent (['type' => 'line', 'line' => ['code' => $code, 'class' => 'e10-pane']]);
	}

	function createContent_Languages()
	{
		$this->setInfo('title', 'Jazyky');

		$this->cfgGenerator->generateLanguages();
		$code = '<pre><code>';
		$code .= $this->cfgGenerator->texts['languages']['json'];
		$code .= '</code></pre>';
		$this->addContent (['type' => 'line', 'line' => ['code' => $code, 'class' => 'e10-pane']]);
	}

	public function loadData ()
	{
		$this->cfgGenerator = new \swdev\world\libs\CfgGenerator($this->app());
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'countries', 'icon' => 'icon-flag', 'title' => 'Země'];
		$d[] = ['id' => 'currencies', 'icon' => 'icon-money', 'title' => 'Měny'];
		$d[] = ['id' => 'languages', 'icon' => 'icon-language', 'title' => 'Jazyky'];

		return $d;
	}
}
