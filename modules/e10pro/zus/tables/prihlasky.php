<?php

namespace E10Pro\Zus;

require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \E10\Application, \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class TablePrihlasky
 * @package E10Pro\Zus
 */
class TablePrihlasky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.prihlasky", "e10pro_zus_prihlasky", "Přihlášky ke studiu", 1216);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['svp']))
			$recData['svp'] = key($this->app()->cfgItem ('e10pro.zus.svp'));

		if (!isset($recData['skolniRok']))
			$recData['skolniRok'] = strval (\E10Pro\Zus\aktualniSkolniRok ());
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		$this->createName('S', $recData);
		//$this->createName('M', $recData);
		//$this->createName('F', $recData);

		$rocnik = $this->app()->cfgItem ('e10pro.zus.rocniky.'.$recData['rocnik'], FALSE);
		if ($rocnik !== FALSE)
			$recData['stupen'] = $rocnik['stupen'];
	}

	public function createName ($who, &$recData)
	{
		if ($recData['complicatedName'.$who] === 0)
		{
			$recData ['beforeName'.$who] = '';
			$recData ['afterName'.$who] = '';
			$recData ['middleName'.$who] = '';
		}
		$recData ['fullName'.$who] = str_replace('  ', ' ', trim ($recData ['beforeName'.$who].' '.$recData ['lastName'.$who].' '.$recData ['firstName'.$who].' '.$recData ['middleName'.$who].' '.$recData ['afterName'.$who]));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
		{
			$hdr ['icon'] = 'icon-asterisk';

			$hdr ['info'][] = ['class' => 'title', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			return $hdr;
		}

		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
		$tablePlaces = $this->app()->table('e10.base.places');

		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = [
			'class' => 'title',
			'value' => $recData['fullNameS']
		];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$recData ['svpOddeleni']}.nazev")],
				['text' => 'obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$recData ['svpObor']}.nazev"), 'class' => 'pull-right']
			]
		];

		$place = $tablePlaces->loadItem ($recData['misto']);
		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['icon' => 'icon-map-marker', 'text' => $place['fullName']],
				['text' => isset($skolniRoky [$recData['skolniRok']]) ?$skolniRoky [$recData['skolniRok']]['nazev'] : '', 'class' => 'pull-right', 'prefix' => ' ']
			]
		];

		return $hdr;
	}

	public function columnInfoEnumTest_Disabled ($columnId, $cfgKey, $cfgItem, \E10\TableForm $form = NULL)
	{
		$r = zusutils::columnInfoEnumTest ($columnId, $cfgItem, $form);
		return ($r !== NULL) ? $r : parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * Class ViewPrihlasky
 * @package E10Pro\Zus
 */
class ViewPrihlasky extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = array ('id' => 'aktualni', 'title' => 'Aktuální');
		$mq [] = array ('id' => 'archiv', 'title' => 'Archív');
		$mq [] = array ('id' => 'vse', 'title' => 'Vše');
		$mq [] = array ('id' => 'kos', 'title' => 'Koš');

		$this->setMainQueries ($mq);
	} // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT prihlasky.*, places.fullName as nazevPobocky FROM [e10pro_zus_prihlasky] as prihlasky ';
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON prihlasky.misto = places.ndx ');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($dotaz != '')
		{
			array_push ($q, " AND (");
			array_push ($q, " [fullNameS] LIKE %s", '%'.$dotaz.'%');
			array_push ($q, " OR [fullNameM] LIKE %s", '%'.$dotaz.'%');
			array_push ($q, ")");
		}

		// -- aktuální
		if ($mainQuery == 'aktualni' || $mainQuery == '')
			array_push ($q, " AND prihlasky.[docStateMain] < %i AND [skolniRok] >= %i", 4, \E10Pro\Zus\aktualniSkolniRok ());

		// -- archív
		if ($mainQuery == 'archiv')
			array_push ($q, " AND prihlasky.[docStateMain] < %i AND [skolniRok] < %i", 4, \E10Pro\Zus\aktualniSkolniRok ());

		// -- koš
		if ($mainQuery == 'kos')
			array_push ($q, " AND prihlasky.[docStateMain] = %i", 4);


		array_push ($q, ' ORDER BY prihlasky.[docStateMain], fullNameS DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['fullNameS'];

		$listItem ['t2'] = $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev");
		$listItem ['i2'] = 'obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$item ['svpObor']}.nazev");
		$listItem ['t3'] = [
			['icon' => 'icon-map-marker', 'text' => $item ['nazevPobocky']]
		];
		if (isset($skolniRoky [$item['skolniRok']]))
			$listItem ['t3'][] = ['text' => $skolniRoky [$item['skolniRok']]['nazev'], 'class' => 'pull-right'];

		return $listItem;
	}
}


/**
 * Class ViewDetailPrihlaska
 * @package E10Pro\Zus
 */
class ViewDetailPrihlaska extends TableViewDetail
{
	public function attachments ()
	{
		$this->addContentAttachments ($this->item ['ndx']);
	}

	public function createDetailContent ()
	{
		$this->attachments();
	}
}


/**
 * Class FormPrihlaska
 * @package E10Pro\Zus
 */
class FormPrihlaska extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

		$tabs ['tabs'][] = array ('text' => 'Základní', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = array ('text' => 'Poznámka', 'icon' => 'system/formNote');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->layoutOpen(TableForm::ltForm);
					$this->addName('S', 'Žák / Žákyně');
					$this->addSeparator(self::coH3);

					$this->layoutOpen(TableForm::ltHorizontal);
						$this->layoutOpen(TableForm::ltForm);
							$this->addColumnInput('datumNarozeni');
							$this->addColumnInput('rodneCislo');
							$this->addColumnInput('skolaTrida');
							$this->addColumnInput('skolaNazev');
							$this->addColumnInput('mistoNarozeni');
							$this->addColumnInput('statniPrislusnost');
							$this->addSeparator(self::coH4);
							//$this->addStatic('Bydliště', TableForm::coH2);
							$this->addColumnInput('street');
							$this->addColumnInput('city');
							$this->addColumnInput('zipcode');
						$this->layoutClose('width50');
						$this->layoutOpen(TableForm::ltForm);
							$this->addColumnInput('svpObor');
							$this->addColumnInput('svpOddeleni');
							$this->addColumnInput('misto');
							$this->addColumnInput('skolniRok');
							$this->addColumnInput('rocnik');

							$this->addSeparator(self::coH4);
							$this->layoutOpen(TableForm::ltVertical);
								$this->addColumnInput('zdravotniPostizeni', self::coRight);
								$this->addColumnInput('zdravotniPostizeniPopis');
							$this->layoutClose('pl1');

						$this->layoutClose('width50');
					$this->layoutClose();

					$this->addSeparator(TableForm::coH3);

					$this->layoutOpen(TableForm::ltHorizontal);
						$this->layoutOpen(TableForm::ltForm);
							$this->addName('M', ' Zákonný zástupce 1');
						$this->layoutClose('width50');
						$this->layoutOpen(TableForm::ltForm);
							$this->addName('F', ' Zákonný zástupce 2');
						$this->layoutClose('width50');
					$this->layoutClose();
			$this->layoutClose('padd5');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addColumnInput ("note", TableForm::coFullSizeY);
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer ();
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}


	public function addName ($who, $label)
	{
		if ($who !== 'S')
		{
			$this->addStatic($label, TableForm::coH2);
			$this->addColumnInput ('fullName'.$who);
			$this->addColumnInput ('phone'.$who);
			$this->addColumnInput ('email'.$who);
			$this->addColumnInput ('useAddress'.$who, TableForm::coRight);
			$this->addColumnInput ('street'.$who);
			$this->addColumnInput ('city'.$who);
			$this->addColumnInput ('zipcode'.$who);

			return;
		}

		$this->openRow ();
			$this->addStatic($label, TableForm::coH1|TableForm::coColW6);
			$this->addColumnInput ('complicatedName'.$who, TableForm::coColW6|TableForm::coRight);
		$this->closeRow();

		$this->layoutOpen (TableForm::ltGrid);
			$this->openRow ();
				if ($this->recData['complicatedName'.$who] == 0)
				{
					$this->addColumnInput ('firstName'.$who, TableForm::coColW6);
					$this->addColumnInput ('lastName'.$who, TableForm::coColW6);
				}
				else
				{
					$this->addColumnInput ('beforeName'.$who, TableForm::coColW1|TableForm::coPlaceholder);
					$this->addColumnInput ('firstName'.$who, TableForm::coColW4|TableForm::coPlaceholder);
					$this->addColumnInput ('middleName'.$who, TableForm::coColW2|TableForm::coPlaceholder);
					$this->addColumnInput ('lastName'.$who, TableForm::coColW4|TableForm::coPlaceholder);
					$this->addColumnInput ('afterName'.$who, TableForm::coColW1|TableForm::coPlaceholder);
				}
			$this->closeRow ();

			if ($who != 'S')
			{
				$this->openRow ();
					$this->addColumnInput ('phone'.$who, TableForm::coColW4);
					$this->addColumnInput ('email'.$who, TableForm::coColW8);
				$this->closeRow();
			}
		$this->layoutClose ();
	}

	public function checkBeforeSave (&$saveData)
	{
		parent::checkBeforeSave($saveData);
		zusutils::testValues ($saveData ['recData'], $this);
	}

	public function checkNewRec ()
	{
		parent::checkNewRec();
		zusutils::testValues ($this->recData, $this);
	}
}


