<?php

namespace e10doc\cmnbkp\libs;
use \Shipard\Utils\Utils;



class ViewCmnBkpOpenClosePeriods  extends ViewCmnBkpDocs
{
	var $dbCounterOpenNdx = 0;
	var $dbCounterCloseNdx = 0;

	var $lastFiscalYear = 0;
	public function init ()
	{
		$this->docType = 'cmnbkp';
		parent::init();
	}

	public function createBottomTabs ()
	{
		$dbCounters = $this->app()->cfgItem ('e10.docs.dbCounters.' . $this->docType, FALSE);

		$dbCounterOpen = Utils::searchArray($dbCounters, 'activitiesGroup', 'ocp');
		$this->dbCounterOpenNdx = $dbCounterOpen['ndx'];

		$dbCounterClose = Utils::searchArray($dbCounters, 'activitiesGroup', 'clp');
		$this->dbCounterCloseNdx = $dbCounterClose['ndx'];

		$bt[] = ['id' => 'open', 'title' => 'Otevření','active' => 1,
						 'addParams' => ['dbCounter' => $this->dbCounterOpenNdx]];

		$bt[] = ['id' => 'close', 'title' => 'Uzavření','active' => 0,
						 'addParams' => ['dbCounter' => $this->dbCounterCloseNdx]];

		$this->setBottomTabs ($bt);
	}

	public function qryCommon (array &$q)
	{
		$bt = $this->bottomTabId ();
		if ($bt === 'open')
			array_push ($q, ' AND heads.[dbCounter] = %i', $this->dbCounterOpenNdx);
		elseif ($bt === 'close')
			array_push ($q,' AND heads.[dbCounter] = %i', $this->dbCounterCloseNdx);

		parent::qryCommon ($q);
	}

	protected function qryFulltextSub ($fts, array &$q)
	{
		array_push ($q,' OR heads.[linkId] LIKE %s', '%'.$fts.'%');
	}

	public function renderRow ($item)
	{
		if ($this->lastFiscalYear != $item['fiscalYear'] && $item['fiscalYear'])
		{
			$fyCfg = $this->app->cfgItem ('e10doc.acc.periods.'.$item['fiscalYear']);
			$this->addGroupHeader ($fyCfg['fullName']);
		}

		$listItem = parent::renderRow($item);

		$parts = explode(';', $item['linkId']);
		if (isset($parts[0]) && $parts[0] === 'OPENBAL')
		{ // $linkId = "OPENBAL;{$this->fiscalYear};{$balance['id']};$currency;$debsAccountId";
			$balance = $this->app->cfgItem('e10.balance.'.$parts[2]);
			$listItem['t1'] = $parts[4]. ' / '.$balance['name'];
		}
		elseif (isset($parts[0]) && ($parts[0] === 'OPENACCPER' || $parts[0] === 'CLOSEACCPER'))
		{ // $linkId = "OPENACCPER;{$this->fiscalYear};$accountKind";
			// $linkId = "CLOSEACCPER;{$this->fiscalYear};$accountKind";
			$kinds = [0 => 'Aktiva', 1 => 'Pasiva', 2 => 'Náklady', 3 => 'Výnosy', 999 => 'Zisk / ztráta'];

			if (isset($kinds[intval($parts[2])]))
				$listItem['t1'] = $kinds[intval($parts[2])];
		}

		$this->lastFiscalYear = $item['fiscalYear'];
		return $listItem;
	}
}

