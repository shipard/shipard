<?php

namespace E10Pro\Zus;

require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \Shipard\Utils\Utils, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewPanel;
use \Shipard\Viewer\TableView;


/**
 * class TablePrihlasky
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

		if (!isset($recData['docNumber']) || $recData['docNumber'] === '')
			$recData['docNumber'] = Utils::today('y').Utils::createToken(4, FALSE, TRUE);

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
			'value' => [
				['text' => $recData['fullNameS'], 'class' => ''],
				['text' => $recData['docNumber'], 'class' => 'pull-right id'],
			]
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
				['icon' => 'tables/e10.base.places', 'text' => $place['fullName']],
				['text' => isset($skolniRoky [$recData['skolniRok']]) ?$skolniRoky [$recData['skolniRok']]['nazev'] : '', 'class' => 'pull-right', 'prefix' => ' ']
			]
		];

		return $hdr;
	}

	public function columnInfoEnumTest_Disabled ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		$r = zusutils::columnInfoEnumTest ($columnId, $cfgItem, $form);
		return ($r !== NULL) ? $r : parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * class ViewPrihlasky
 */
class ViewPrihlasky extends TableView
{
	var $officesParam = NULL;
	var $offices;
	var $mistaStudia;

	public function init ()
	{
		parent::init();
		$this->setPanels (TableView::sptQuery);

		$this->offices = [0 => 'Vše'];
		$this->offices += $this->db()->query('SELECT ndx, shortName FROM [e10_base_places] WHERE docStateMain < 4 AND placeType = %s ORDER BY [fullName]', 'lcloffc')->fetchPairs ('ndx', 'shortName');
		$this->mistaStudia = $this->app()->cfgItem('e10pro.zus.mistaStudia');


		if ($this->offices > 1)
			$this->usePanelLeft = TRUE;

		if ($this->usePanelLeft)
		{
			$enum = [];
			forEach ($this->offices as $officeNdx => $officeName)
			{
				$enum[$officeNdx] = ['text' => $officeName, 'addParams' => ['misto' => $officeNdx]];
			}

			$this->officesParam = new \Shipard\UI\Core\Params ($this->app);
			$this->officesParam->addParam('switch', 'office', ['title' => '', 'switch' => $enum, 'list' => 1]);
			$this->officesParam->detectValues();
		}

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();

		$officeNdx = 0;
		if ($this->officesParam)
			$officeNdx = intval($this->officesParam->detectValues()['office']['value']);


		$q [] = 'SELECT prihlasky.*, places.fullName as nazevPobocky FROM [e10pro_zus_prihlasky] as prihlasky ';
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON prihlasky.misto = places.ndx ');
		array_push ($q, ' WHERE 1');

		if ($officeNdx)
		array_push ($q, ' AND [prihlasky].[misto] = %i', $officeNdx);

		// -- fulltext
		if ($dotaz != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullNameS] LIKE %s', '%'.$dotaz.'%');
			array_push ($q, ' OR [fullNameM] LIKE %s', '%'.$dotaz.'%');
			array_push ($q, ')');
		}

		// special queries
		$qv = $this->queryValues ();

		if (isset ($qv['talentovaZkouska']))
			array_push ($q, " AND [prihlasky].[talentovaZkouska] IN %in", array_keys($qv['talentovaZkouska']));
		if (isset ($qv['keStudiu']))
			array_push ($q, " AND [prihlasky].[keStudiu] IN %in", array_keys($qv['keStudiu']));

		if (isset($qv['obor']['']) && $qv['obor'][''] != 0)
			array_push ($q, " AND prihlasky.[svpObor] = %i", $qv['obor']['']);
		if (isset($qv['oddeleni']['']) && $qv['oddeleni'][''] != 0)
			array_push ($q, " AND prihlasky.[svpOddeleni] = %i", $qv['oddeleni']['']);
		if (isset ($qv['mistoStudia']))
			array_push ($q, " AND [prihlasky].[mistoStudia] IN %in", array_keys($qv['mistoStudia']));

		$this->queryMain ($q, 'prihlasky.', ['[webSentDate]', '[fullNameS]']);
		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['i1'] = ['text' => $item['docNumber'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullNameS'];

		$listItem ['i2'][] = ['icon' => 'system/iconCalendar', 'text' => Utils::datef($item ['webSentDate'], '%D, %T'), 'class' => ''];

		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev"), 'class' => ''];
		$listItem ['t2'][] = ['icon' => 'tables/e10.base.places', 'text' => $item ['nazevPobocky'], 'class' => ''];


		$listItem ['t3'] = [];


		$tz = $this->table->columnInfoEnum ('talentovaZkouska', 'cfgText');
		$listItem ['t3'][] = ['text' => 'TZ: '.$tz [$item ['talentovaZkouska']], 'class' => 'label label-default'];

		$ks = $this->table->columnInfoEnum ('keStudiu', 'cfgText');
		if ($item ['keStudiu'] === 1)
			$listItem ['t3'][] = ['text' => 'Přijetí: '.$ks [$item ['keStudiu']], 'class' => 'label label-success'];
		else
			$listItem ['t3'][] = ['text' => 'Přijetí: '.$ks [$item ['keStudiu']], 'class' => 'label label-default'];

		if ($item ['mistoStudia'] && isset($this->mistaStudia [$item ['mistoStudia']]))
			$listItem ['t3'][] = ['text' => 'V: '.$this->mistaStudia [$item ['mistoStudia']]['shortName'], 'class' => 'label label-default'];

		if (isset($skolniRoky [$item['skolniRok']]))
			$listItem ['t3'][] = ['text' => $skolniRoky [$item['skolniRok']]['nazev'], 'class' => 'pull-right'];

		return $listItem;
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->officesParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->officesParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar ()
	{
		$btns = parent::createToolbar();
		//unset($btns[0]);
		return $btns;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- talentova zkouska
		$tz = $this->table->columnInfoEnum ('talentovaZkouska', 'cfgText');
		$chbxTZ = [];
		forEach ($tz as $tzNdx => $tzName)
		{
			$chbxTZ[$tzNdx] = ['title' => $tzName, 'id' => strval($tzNdx)];
		}
		$paramsTZ = new \Shipard\UI\Core\Params ($panel->table->app());
		$paramsTZ->addParam ('checkboxes', 'query.talentovaZkouska', ['items' => $chbxTZ]);
		$qry[] = ['id' => 'talentovaZkouska', 'style' => 'params', 'title' => 'Talentová zkouška', 'params' => $paramsTZ];

		// -- keStudiu
		$ks = $this->table->columnInfoEnum ('keStudiu', 'cfgText');
		$chbxKS = [];
		forEach ($ks as $ksNdx => $ksName)
		{
			$chbxKS[$ksNdx] = ['title' => $ksName, 'id' => strval($ksNdx)];
		}
		$paramsKS = new \Shipard\UI\Core\Params ($this->app());
		$paramsKS->addParam ('checkboxes', 'query.keStudiu', ['items' => $chbxKS]);
		$qry[] = ['id' => 'keStudiu', 'style' => 'params', 'title' => 'Ke studiu', 'params' => $paramsKS];

		// -- mistoStudia
		$ms = $this->table->columnInfoEnum ('mistoStudia', 'cfgText');
		$chbxMS = [];
		forEach ($ms as $msNdx => $msName)
		{
			$chbxMS[$msNdx] = ['title' => $msName, 'id' => strval($msNdx)];
		}
		$paramsMS = new \Shipard\UI\Core\Params ($this->app());
		$paramsMS->addParam ('checkboxes', 'query.mistoStudia', ['items' => $chbxMS]);
		$qry[] = ['id' => 'mistoStudia', 'style' => 'params', 'title' => 'Přijetí ke studiu v', 'params' => $paramsMS];

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('switch', 'query.obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.oddeleni', ['title' => 'Oddělení', 'place' => 'panel', 'cfg' => 'e10pro.zus.oddeleni', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->detectValues();
		$qry[] = array ('id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows, 'class' => 'switches');

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailPrihlaska
 * @package E10Pro\Zus
 */
class ViewDetailPrihlaska extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.DCPrihlaska');
	}
}


/**
 * class FormPrihlaska
 */
class FormPrihlaska extends TableForm
{
	public function renderForm ()
	{
		$mistaStudia = $this->app()->cfgItem('e10pro.zus.mistaStudia');

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Poznámka', 'icon' => 'system/formNote'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
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
			$this->addSeparator(self::coH2);
			$this->addColumnInput('talentovaZkouska');
			$this->addColumnInput('keStudiu');
			if (count($mistaStudia) > 0)
				$this->addColumnInput('mistoStudia');
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
		//zusutils::testValues ($saveData ['recData'], $this);
	}

	public function checkNewRec ()
	{
		parent::checkNewRec();
		//zusutils::testValues ($this->recData, $this);
	}
}
