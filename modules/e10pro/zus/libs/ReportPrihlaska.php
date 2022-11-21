<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \E10\FormReport;


class ReportPrihlaska extends \e10doc\core\libs\reports\DocReportBase
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;

	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.prihlaska';
		$this->reportTemplate = 'reports.modern.e10pro.zus.prihlaska';

		parent::init();
	}

	public function loadData ()
	{
		parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->allProperties = $this->app()->cfgItem('e10.base.properties', []);

		parent::loadData();

    $pobocka = $this->app()->loadItem($this->recData['misto'], 'e10.base.places');
    if ($pobocka)
      $this->data ['pobocka'] = $pobocka;


    $obor = $this->app()->loadItem($this->recData['svpObor'], 'e10pro.zus.obory');
    if ($obor)
      $this->data ['obor'] = $obor;

    $oddeleni = $this->app()->loadItem($this->recData['svpOddeleni'], 'e10pro.zus.oddeleni');
    if ($oddeleni)
      $this->data ['oddeleni'] = $oddeleni;


		$this->data ['webSentDate'] = utils::datef($this->recData['webSentDate'], '%d, %T');
	}
}
