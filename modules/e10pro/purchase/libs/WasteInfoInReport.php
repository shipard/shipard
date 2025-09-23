<?php

namespace e10pro\purchase\libs;


/**
 * WasteInfoInReport
 */
class WasteInfoInReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10pro.purchase.wasteInfoIn');
	}

	public function loadData ()
	{
		//$this->sendReportNdx = 2000;

		parent::loadData();

		$wie = new \e10pro\purchase\libs\WasteInfoEngine($this->app());
		$wie->setDocument($this->recData['ndx']);
		$wie->loadData();
		$this->data['infoWasteCodes'] = $wie->wasteCodes;
		$wie->loadWasteReportInfo($this->data);
		$this->data['infoWasteCodes'] = array_values($this->data['infoWasteCodes']);
		$this->data['wasteSettings'] = $wie->wasteSettings;
	}

	public function createToolbarSaveAs (&$printButton)
	{
	}

	public function saveAsFileName ($type)
	{
		$fn = 'PIO'.'-';
		$fn .= $this->recData['docNumber'].'.pdf';
		return $fn;
	}
}

