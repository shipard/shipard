<?php

namespace e10pro\custreg;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\FormReport, \E10\DbTable;


/**
 * Class RegistrationListReport
 * @package e10pro\custreg
 */
class RegistrationListReport extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.custreg.registration';
		$this->reportTemplate = 'e10pro.custreg.registration';
	}

	public function loadData()
	{
		parent::loadData ();

		$this->data ['lists'] = $this->table->loadLists ($this->recData);
		$this->data ['address'] = $this->data ['lists']['address'][0];

		$country = $this->app->cfgItem ('e10.base.countries.'.$this->data ['person']['lists']['address'][0]['country'], FALSE);
		if ($country)
		{
			$this->data ['address']['countryName'] = $country['name'];
			$this->data ['address']['countryNameEng'] = $country['engName'];
			$this->data ['address']['countryNameSC2'] = $country['sc2'];
			$this->lang = $country['lang'];
		}

		forEach ($this->data ['lists']['properties'] as $iii)
		{
			$this->data ['properties'][$iii['property']][] = $iii['value'];
		}
	}

	public function checkDocumentInfo (&$documentInfo)
	{
		$documentInfo['messageDocKind'] = 'person.custreg';
	}
}
