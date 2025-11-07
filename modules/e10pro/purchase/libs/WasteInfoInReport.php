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

		if ($this->recData ['otherAddress1Mode'] == 1)
		{ // city & code
			$nomencCityRecData = $this->app()->loadItem($this->recData ['personNomencCity'], 'e10.base.nomencItems');

			$this->data ['flags']['useORP'] = 1;
			$this->data ['ORP']['code'] = substr($nomencCityRecData['itemId'], 2);
			$this->data ['ORP']['name'] = $nomencCityRecData['fullName'];
			$this->data ['flags']['useAddressPersonOffice'] = 0;
			$this->data ['flags']['usePersonsAddress'] = 1;
		}

		if ($this->recData['wasteOrigin'])
		{
			$wasteOrigin = $this->app()->cfgItem('e10doc.base.wasteOrigins.'.$this->recData['wasteOrigin'], NULL);
			if ($wasteOrigin)
			{
				$this->data['flags']['wasteOrigin']['text'] = ($wasteOrigin['tfr'] !== '') ? $wasteOrigin['tfr'] : $wasteOrigin['fn'];
			}
		}

		if ($this->recData['wasteOriginAdmUnit'] && $this->recData['personType'] == 1)
		{
			$admUnit = $this->app()->loadItem($this->recData['wasteOriginAdmUnit'], 'e10.world.admUnits');
			if ($admUnit)
			{
				$this->data['woAdmUnit'] = $admUnit;
				$this->data['woAdmUnitX'] = json_encode($admUnit);
			}
		}
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

