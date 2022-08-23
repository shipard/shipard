<?php

namespace E10Pro\Zus;

//require_once __DIR__ . '/../../base/base.php';


use \E10\Application;
use \E10\TableView;
use \Shipard\Form\TableForm;
use \Shipard\Table\DbTable;


/**
 * Tabulka Znamky
 *
 */

class TableZnamky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.znamky", "e10pro_zus_znamky", "Známky");
	}

  public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if ($columnId == 'svpPredmet')
		{
			if (!$form)
				return TRUE;
			$ownerRecData = $form->option ('ownerRecData');
			if (!$ownerRecData)
				return TRUE;

			if ($cfgItem ['svp'] != 0 && $ownerRecData ['svp'] != $cfgItem ['svp'])
				return FALSE;
			if ($cfgItem ['obor'] != 0 && $ownerRecData ['svpObor'] != $cfgItem ['obor'])
				return FALSE;
      if ($cfgItem ['oddeleni'] != 0 && $ownerRecData ['svpOddeleni'] != $cfgItem ['oddeleni'])
        return FALSE;

      return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


