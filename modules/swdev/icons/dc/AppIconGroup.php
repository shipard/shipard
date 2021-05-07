<?php

namespace swdev\icons\dc;

use e10\utils, e10\json;


/**
 * Class SetsIcon
 * @package swdev\icons\dc
 */
class AppIconGroup extends \e10\DocumentCard
{

	/** @var \swdev\icons\libs\IconsCfgGenerator */
	var $cfgGenerator;

	public function createContentBody ()
	{
		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => $this->cfgGenerator->text]);
	}

	public function createContent ()
	{
		$this->cfgGenerator = new \swdev\icons\libs\IconsCfgGenerator($this->app());
		$this->cfgGenerator->run();


		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
