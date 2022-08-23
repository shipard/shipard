<?php

namespace e10pro\zus\libs;
require_once __APP_DIR__ . '/e10-modules/e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \E10\FormReport;


class ReportPrihlaska extends FormReport
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $allProperties;

	var $ownerNdx = 0;
	var $ownerCountry = FALSE;
	var $country = FALSE;

	function init ()
	{
		$this->reportId = 'e10pro.zus.prihlaska';
		$this->reportTemplate = 'e10pro.zus.prihlaska';

		parent::init();
	}

	public function loadData ()
	{
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
        
		$this->loadData_DocumentOwner();	
	}

	function loadData_DocumentOwner ()
	{
		$this->ownerNdx = intval($this->app()->cfgItem('options.core.ownerPerson', 0));
		if ($this->ownerNdx)
		{
			$this->data ['owner'] = $this->tablePersons->loadItem($this->ownerNdx);
			$this->data ['owner']['lists'] = $this->tablePersons->loadLists($this->data ['owner']);
			$this->ownerCountry = FALSE;
			if (isset($this->data ['owner']['lists']['address'][0]))
			{
				$this->data ['owner']['address'] = $this->data ['owner']['lists']['address'][0];
				$this->ownerCountry = $this->app->cfgItem('e10.base.countries.' . $this->data ['owner']['lists']['address'][0]['country']);
				$this->data ['owner']['address']['countryName'] = $this->ownerCountry['name'];
				$this->data ['owner']['address']['countryNameEng'] = $this->ownerCountry['engName'];
				$this->data ['owner']['address']['countryNameSC2'] = $this->ownerCountry['sc2'];
			}
			foreach ($this->data ['owner']['lists']['properties'] as $iii)
			{
				if ($iii['group'] == 'ids')
				{
					$name = '';
					if ($iii['property'] == 'taxid')
					{
						$name = 'DIČ';
						$this->data ['owner']['vatId'] = $iii['value'];
						$this->data ['owner']['vatIdCore'] = substr($iii['value'], 2);
					}
					else
						if ($iii['property'] == 'oid')
							$name = 'IČ';

					if ($name != '')
						$this->data ['owner_identifiers'][] = array('name' => $name, 'value' => $iii['value']);
				}
				if ($iii['group'] == 'contacts')
				{
					$name = $this->allProperties[$iii['property']]['name'];
					$this->data ['owner_contacts'][] = array('name' => $name, 'value' => $iii['value']);
				}
			}

			$ownerAtt = \E10\Base\getAttachments($this->table->app(), 'e10.persons.persons', $this->ownerNdx, TRUE);
			foreach ($ownerAtt as $oa)
			{
				$this->data ['owner']['logo'][$oa['name']] = $oa;
			}
		}
	}
}
