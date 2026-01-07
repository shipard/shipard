<?php

namespace e10doc\waster\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewWasteCompaniesOut
 */
class ViewWasteCompaniesOut extends \e10doc\waster\libs\ViewWasteCompaniesIn
{
	public function init ()
	{
		$this->wasteDir = 1;

		parent::init();
	}
}
