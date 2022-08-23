<?php

namespace E10Pro\Zus;

use \E10\TableForm, \E10\DbTable, E10\utils;

/**
 * Class TableVyukyRozvrh
 * @package E10Pro\Zus
 */

class TableVyukyRozvrh extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.vyukyrozvrh', 'e10pro_zus_vyukyrozvrh', 'Rozvrh výuky');
	}

	public function checkAccessToDocument ($recData)
	{
		if ($this->app()->hasRole('zusadm'))
			return 2;

		if ($this->app()->hasRole('uctl') && $recData['ucitel'] == $this->app()->userNdx())
			return 2;

		return 1; // parent::checkAccessToDocument($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$zacatekMin = utils::timeToMinutes($recData['zacatek']);
		$konecMin = utils::timeToMinutes($recData['konec']);

		if ($zacatekMin && !$konecMin)
		{
			$konecMin = $zacatekMin + 45;
			$recData['konec'] = utils::minutesToTime($konecMin);
		}

		$recData['delka'] = $konecMin - $zacatekMin;

		$recData['predmet'] = 0;
		if ($recData['vyuka'] !== 0)
		{
			$vyuka = $this->app()->loadItem($recData['vyuka'], 'e10pro.zus.vyuky');
			$recData['predmet'] = $vyuka['svpPredmet'];
			if (!$recData['pobocka'])
				$recData['pobocka'] = $vyuka['misto'];

			if (!isset ($recData['zacatek']) || $recData['zacatek'] == '')
			{
				if ($recData['ucitel'])
				{
					$q = 'SELECT * FROM e10pro_zus_vyukyrozvrh WHERE ucitel = %i AND den = %i ORDER BY konec DESC';
					$konec = $this->db()->query ($q, $recData['ucitel'], $recData['den'])->fetch();
					if ($konec && $konec['konec'] != '')
					{
						$posledniCas = utils::timeToMinutes($konec['konec']) + 5;
						$recData['zacatek'] = utils::minutesToTime($posledniCas);
						$konecMin = $posledniCas + 45;
						$recData['konec'] = utils::minutesToTime($konecMin);
					}
				}
			}
		}

		if (!isset($recData['stav']))
		{
			if ($ownerData)
			{
				$recData['stav'] = $ownerData['stav'];
				$recData['stavHlavni'] = $ownerData['stavHlavni'];
			}
			else
			{
				$recData['stav'] = 4000;
				$recData['stavHlavni'] = 2;
			}
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$vyuka = $this->app()->loadItem($recData['vyuka'], 'e10pro.zus.vyuky');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['text' => $this->app()->cfgItem ("e10pro.zus.predmety.{$vyuka['svpPredmet']}.nazev")],
				['text' => $rocniky [$vyuka['rocnik']]['nazev'], 'class' => 'pull-right']
			]
		];

		$hdr ['info'][] = [
			'class' => 'title',
			'value' => [['text' => $vyuka['nazev']]]
		];

		return $hdr;
	}
}


/**
 * Class FormVyukyRozvrh
 * @package E10Pro\Zus
 */

class FormVyukyRozvrh extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		if ($ownerRecData && $this->recData['ucitel'] === 0)
			$this->recData['ucitel'] = $ownerRecData['ucitel'];

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			if (!$ownerRecData)
				$this->addColumnInput ('vyuka');

			$this->openRow();
				$this->addColumnInput ('zacatek');
				$this->addColumnInput ('konec');
				$this->addColumnInput ('den');
			$this->closeRow();

			//$this->addColumnInput ('predmet');
			$this->addColumnInput ('pobocka');
			$this->addColumnInput ('ucebna');
			$this->addColumnInput ('ucitel');

		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10pro.zus.vyukyrozvrh')
		{
			$cp = [];
			if ($srcColumnId === 'vyuka')
				$cp = ['ucitel' => strval ($allRecData ['recData']['ucitel'])];
			if ($srcColumnId === 'ucebna')
				$cp = ['pobocka' => $allRecData ['recData']['pobocka']];
			if (count($cp))
				return $cp;
		}
		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}
