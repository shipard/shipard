<?php

namespace E10Pro\Zus;

use \E10\utils;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel;
use \Shipard\Form\TableForm;
use \Shipard\Table\DbTable;
use \Shipard\Report\FormReport;
use \e10\base\libs\UtilsBase;

/**
 * Tabulka Vysvedceni
 *
 */

class TableVysvedceni extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.vysvedceni", "e10pro_zus_vysvedceni", "Vysvědčení");
	}

  public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset ($recData ['ucitel']))
			$recData ['ucitel'] = $this->app()->user()->data ('id');
		$this->loadStudentInfo ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$this->loadStudentInfo ($recData);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function loadStudentInfo (&$recData)
	{
		// -- study
		if ($recData['studium'] != 0)
		{
			$studium = $this->app()->loadItem ($recData ['studium'], 'e10pro.zus.studium');
			if ($studium)
			{
				$recData['typVysvedceni'] = $studium['typVysvedceni'];
				$recData['skolniRok'] = $studium['skolniRok'];
				if ($studium['poradoveCislo'] && !$recData['poradoveCislo'])
					$recData['poradoveCislo'] = $studium['poradoveCislo'];
				$recData['svp'] = $studium['svp'];
				$recData['svpObor'] = $studium['svpObor'];
				$recData['svpOddeleni'] = $studium['svpOddeleni'];
				$recData['rocnik'] = $studium['rocnik'];
				$recData['stupen'] = $studium['stupen'];
				$recData['urovenStudia'] = $studium['urovenStudia'];
				$recData['student'] = $studium['student'];
				$recData['ucitel'] = $studium['ucitel'];
			}
		}

		// -- student
		if ($recData['student'] != 0)
		{
			$tablePersons = $this->app()->table ('e10.persons.persons');
			$student = $this->loadItem ($recData ['student'], 'e10_persons_persons');
			if ($student)
			{
				$studentLists = $tablePersons->loadLists ($student);

				$recData ['jmeno'] = $student ['fullName'];
				$rodneCislo = \E10\searchArray ($studentLists ['properties'], 'property', 'pid');
				if ($rodneCislo)
					$recData ['rodneCislo'] = $rodneCislo ['value'];
				$datumNarozeni = \E10\searchArray ($studentLists ['properties'], 'property', 'birthdate');
				if ($datumNarozeni)
					$recData ['datumNarozeni'] = $datumNarozeni ['value']->format ('d. m. Y');
			}
		}
	}

  public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		$r = zusutils::columnInfoEnumTest ($columnId, $cfgItem, $form);
		return ($r !== NULL) ? $r : parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];
		$hdr ['icon'] = $this->tableIcon ($recData);

		if (!$recData)
		{
			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'title', 'value' => ' '];
			return $hdr;
		}

		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
		$tablePersons = $this->app()->table('e10.persons.persons');

		$student = $tablePersons->loadItem ($recData['student']);

		$hdr ['info'][] = [
			'class' => 'title',
			'value' => [
				['text' => $student['fullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $recData['student']],
				['icon' => 'icon-star', 'text' => $recData ['datumNarozeni'], 'class' => 'pull-right']
			]
		];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$recData ['svpOddeleni']}.nazev")],
				['text' => $rocniky [$recData['rocnik']]['nazev'], 'class' => 'pull-right']
			]
		];

		//$place = $tablePlaces->loadItem ($recData['misto']);
		$teacher = $tablePersons->loadItem ($recData['ucitel']);
		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				//['icon' => 'icon-map-marker', 'text' => $place['fullName']],
				['icon' => 'x-teacher', 'text' => $teacher['fullName']],
				['text' => $skolniRoky [$recData['skolniRok']]['nazev'], 'class' => 'pull-right', 'prefix' => ' ']
			]
		];

		$image = UtilsBase::getAttachmentDefaultImage ($this->app(), 'e10.persons.persons', $recData ['student']);
		$hdr ['image'] = $image ['smallImage'];

		return $hdr;
	}
}


/**
 * Základní pohled na Vysvědčení
 *
 */

class ViewVysvedceni extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'aktualni', 'title' => 'Aktuální');
		$mq [] = array ('id' => 'archiv', 'title' => 'Archív');
		$mq [] = array ('id' => 'vse', 'title' => 'Vše');
		$mq [] = array ('id' => 'kos', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		if ($this->app->hasRole('zusadm') || $this->app->hasRole('scrtr'))
			$this->setPanels (TableView::sptQuery);
	} // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$qv = $this->queryValues();

		$q [] = 'SELECT vysvedceni.*, persons.fullName as personFullName, studium.cisloStudia';

		array_push($q, ' FROM [e10pro_zus_vysvedceni] AS vysvedceni');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON vysvedceni.student = persons.ndx');
		array_push($q, ' LEFT JOIN [e10pro_zus_studium] AS [studium] ON vysvedceni.studium = studium.ndx');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($dotaz != '')
			array_push ($q, " AND vysvedceni.[jmeno] LIKE %s", '%'.$dotaz.'%');

		// -- jen sekretariát a admin vidí cizí vysvědčení
		if ((!$this->table->app()->hasRole ('scrtr')) && (!$this->table->app()->hasRole ('admin')))
			array_push ($q, " AND vysvedceni.[ucitel] = %i", intval($this->table->app()->user()->data ('id')));

		// -- aktuální
		if ($mainQuery == 'aktualni' || $mainQuery == '')
			array_push ($q, " AND vysvedceni.[skolniRok] = %i", \E10Pro\Zus\aktualniSkolniRok ());

		// -- archív
		if ($mainQuery == 'archiv')
			array_push ($q, " AND vysvedceni.[skolniRok] < %i", \E10Pro\Zus\aktualniSkolniRok ());

		// koš
		if ($mainQuery == 'kos')
			array_push ($q, " AND vysvedceni.[stavHlavni] = %i", 4);
		else
			array_push ($q, " AND vysvedceni.[stavHlavni] <> %i", 4);


		// odfiltrovat smazané | update e10pro_zus_vysvedceni set stavHlavni = 4, stav = 9800 where smazano = 1
		//	array_push ($q, " AND [smazano] <> %i", 1);

		// Panel query...
		if (isset($qv['ucitel']['']) && $qv['ucitel'][''] != 0)
			array_push ($q, " AND vysvedceni.[ucitel] = %i", $qv['ucitel']['']);
		if (isset($qv['typVysvedceni']['']) && $qv['typVysvedceni'][''] != 99)
			array_push ($q, " AND vysvedceni.[typVysvedceni] = %i", $qv['typVysvedceni']['']);
		if (isset($qv['skolniRok']['']) && $qv['skolniRok'][''] != '0')
			array_push ($q, " AND vysvedceni.[skolniRok] = %i", $qv['skolniRok']['']);
		if (isset($qv['predmet']['']) && $qv['predmet'][''] != 0)
			array_push ($q, " AND EXISTS (SELECT * FROM [e10pro_zus_znamky] as vysvpre WHERE (vysvpre.[vysvedceni] = vysvedceni.[ndx] AND vysvpre.[svpPredmet] = %i))", $qv ['predmet']['']);

		array_push ($q, ' ORDER BY vysvedceni.[stavHlavni], persons.lastName, vysvedceni.[skolniRok] DESC' . $this->sqlLimit ());
		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "tables/e10pro.zus.vysvedceni";
		$listItem ['t1'] = $item['personFullName'];

		$listItem ['t2'] = [['text' => $item ['datumNarozeni'], 'class' => '']];
		if ($item['cisloStudia'])
			$listItem ['t2'][] = ['text' => $item ['cisloStudia'], 'class' => 'label label-default', 'icon' => 'icon-asterisk'];

		$listItem ['i1'] = $skolniRoky [$item['skolniRok']]['nazev'];

		$typyVysvedceni = $this->table->columnInfoEnum ('typVysvedceni', 'cfgText');
		$listItem ['i2'] = $typyVysvedceni [$item ['typVysvedceni']];

		return $listItem;
	}

	public function createToolbar ()
	{
		// Nebude vidět tlačítko Přidat, ale Wizard na generování bude fungovat
		$t = parent::createToolbar();
		unset ($t[0]);

		// Hromadný tisk vysvědčení
		$t [] = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10pro.zus.vysvedceni','data-class' => 'e10pro.zus.SchoolReportsPrintWizard',
				'text' => 'Hromadný tisk', 'icon' => 'system/actionPrint'
		];

		return $t;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('switch', 'query.ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app)]);
		$paramsRows->addParam ('switch', 'query.typVysvedceni', ['title' => 'Typ vysvědčení', 'place' => 'panel', 'cfg' => 'zus.typyVysvedceni',
			'enableAll' => ['99' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.skolniRok', ['title' => 'Školní rok', 'switch' => zusutils::skolniRoky($this->app)]);
		$paramsRows->addParam ('switch', 'query.predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);

		$paramsRows->detectValues();

		$qry[] = array ('id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows, 'class' => 'switches');
		$panel->addContent(array ('type' => 'query', 'query' => $qry));
	}
} // class ViewVysvedceni


/**
 * Základní detail Vysvědčení
 *
 */

class ViewDetailVysvedceni extends TableViewDetail
{
	public function createDetailContent ()
	{
		// -- seznam předmětů na vysvědčení
		$predmety = [];
		$pv = $this->app()->db()->query ('SELECT * FROM [e10pro_zus_znamky] WHERE [vysvedceni] = %i ORDER BY ndx', $this->item['ndx'])->fetchAll();
		foreach ($pv as $r)
			$predmety[] = $r['svpPredmet'];

		// -- rekapitulace
		$studentInfo = new \e10pro\zus\StudentYearInfo($this->app());
		$studentInfo->setParams(['studentNdx' => $this->item['student'], 'studiumNdx' => $this->item['studium'], 'skolniRok' => $this->item['skolniRok'], 'predmety' => $predmety]);
		$studentInfo->run();
		$this->addContent([
			'type' => 'table', 'pane' => 'e10-pane e10-pane-table',
			'table' => $studentInfo->infoTable,
			'header' => $studentInfo->infoHeader,
			'params' => ['header' => $studentInfo->infoHeaderHorizontal, 'disableZeros' => 1],
		]);

		// -- vysvědčení
		$r = new VysvedceniReportDetail ($this->table, $this->item);
		$r->init ();
		$r->renderReport ();
		$this->addContent(array ('type' => 'text', 'subtype' => 'rawhtml', 'text' => $r->objectData ['mainCode']));
	}
}


/**
 * Class FormVysvedceni
 * @package E10Pro\Zus
 */
class FormVysvedceni extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();

		$ppco = 0;
		$ppco2 = 0;
    if (!isset($this->recData['student']) || ($this->recData['student'] == 0))
			$ppco = TableForm::coReadOnly;
 		if ($this->recData ['stav'] == 1200) {
			$ppco = TableForm::coReadOnly;
			$ppco2 = TableForm::coReadOnly;
    }

		$tabs ['tabs'][] = array ('text' => 'Základní', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
		$this->prehledDochazky();
		$this->addSeparator(TableForm::coH1);
				$this->layoutOpen (TableForm::ltHorizontal);

					$this->layoutOpen (TableForm::ltForm);
						$this->addStatic('1. pololetí', TableForm::coH1);
						$this->addColumnInput ("zamHodinyOml1p", $ppco);
						$this->addColumnInput ("zamHodinyNeo1p", $ppco);
						$this->addColumnInput ("hodnoceni1p", $ppco);
					$this->layoutClose ();
					$this->layoutOpen (TableForm::ltForm);
						$this->addStatic('2. pololetí', TableForm::coH1);
						$this->addColumnInput ("zamHodinyOml2p");
						$this->addColumnInput ("zamHodinyNeo2p");
						$this->addColumnInput ("hodnoceni2p");
					$this->layoutClose ();
				$this->layoutClose ();
				$this->addSeparator(TableForm::coH1);
				$this->addStatic('Známky', TableForm::coH1);
				$this->addList ('znamky');
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ("ucitel", $ppco);

				$this->addColumnInput ("studium", $ppco2);

				$this->addColumnInput ("poradoveCislo", $ppco);

				if ($this->recData['studium'] != 0)
					$covi = TableForm::coReadOnly;

				$this->addColumnInput ("student", $covi);
				$this->addColumnInput ("skolniRok", $covi);
				$this->addColumnInput ("rocnik", $covi);
				$this->addColumnInput ("stupen", $covi);
				$this->addColumnInput ("svp", $covi);
				$this->addColumnInput ("svpObor", $covi);
				$this->addColumnInput ("svpOddeleni", $covi);
				$this->addColumnInput ("urovenStudia", $covi);
				$this->addColumnInput ("typVysvedceni", $covi);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer ();
			$this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case 'svp': return "Studium podle";
      case 'svpOddeleni': return $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");
    }
    return parent::columnLabel ($colDef, $options);
  }

	function checkLoadedList ($list)
	{
		if (($list->listId == 'znamky') && (count($list->data) == 0) && isset ($this->recData['studium']) && $this->recData['studium'])
		{
			$q = 'SELECT * FROM [e10pro_zus_studiumpre] WHERE studium = %i ORDER BY ndx';
			$rows = $this->table->db ()->query ($q, $this->recData['studium']);
			forEach ($rows as $r)
				$list->data [] = array ('ndx' => 0, 'svpPredmet' => $r['svpPredmet']);
		}
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

	protected function renderMainSidebar_TMP ($allRecData, $recData)
	{
		$predmety = [];
		$pv = $this->app()->db()->query ('SELECT * FROM [e10pro_zus_znamky] WHERE [vysvedceni] = %i ORDER BY ndx', $recData['ndx'])->fetchAll();
		foreach ($pv as $r)
			$predmety[] = $r['svpPredmet'];

		// -- rekapitulace
		$studentInfo = new \e10pro\zus\StudentYearInfo($this->app());
		$studentInfo->setParams(['studentNdx' => $recData['student'], 'studiumNdx' => $this->recData['studium'], 'skolniRok' => $recData['skolniRok'], 'predmety' => $predmety]);
		$studentInfo->run(TRUE);
		$cc = \e10\renderTableFromArray($studentInfo->infoTable, $studentInfo->infoHeader, ['disableZeros' => 1, 'hideHeader' => 1]);

		$this->sidebar = $cc;
	}

	function prehledDochazky()
	{
		$predmety = [];
		$pv = $this->app()->db()->query ('SELECT * FROM [e10pro_zus_znamky] WHERE [vysvedceni] = %i ORDER BY ndx', $this->recData['ndx'])->fetchAll();
		foreach ($pv as $r)
			$predmety[] = $r['svpPredmet'];

		$studentInfo = new \e10pro\zus\StudentYearInfo($this->app());
		$studentInfo->setParams(['studentNdx' => $this->recData['student'], 'studiumNdx' => $this->recData['studium'], 'skolniRok' => $this->recData['skolniRok'], 'predmety' => $predmety]);
		$studentInfo->run();
		$content = [
			'type' => 'table', 'pane' => 'padd5',
			'table' => $studentInfo->infoTable,
			'header' => $studentInfo->infoHeader,
			'params' => ['header' => $studentInfo->infoHeaderHorizontal, 'disableZeros' => 1],
		];
		$this->addStatic($content);
	}
}


/**
 * Editační formulář Řádku vysvědčení
 *
 */

class FormRadekVysvedceni extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$ppco = 0;
		if ($ownerRecData ['stav'] == 1200)
			$ppco = TableForm::coReadOnly;

		$this->openForm (TableForm::ltHorizontal);
			$this->addColumnInput ("svpPredmet", $ppco);
			$this->addColumnInput ("znamka1p", $ppco);
			$this->addColumnInput ("znamka2p");
		$this->closeForm ();
	}
} // class FormRadekVysvedceni


/**
 * VysvedceniReportOpis
 *
 * Výstupní sestava Vysvědčení
 *
 *
 */

class VysvedceniReportOpis extends FormReport
{
	static $monthNames = ['ledna','února','března','dubna','května','června', 'července','srpna','září','října','listopadu','prosince'];

	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.opis';
		$this->reportTemplate = 'reports.modern.e10pro.zus.opis';
	}

	public function datum ($d)
	{
		$date = utils::createDateTime($d);
		$day = $date->format('d');
		$month = intval($date->format('m')) - 1;
		$year = $date->format('Y');

		return $day.'. '.self::$monthNames[$month].' '.$year;
	}

	public function loadData ()
	{
		$skolniRok = $this->app->cfgItem ('e10pro.zus.roky.'.$this->recData ['skolniRok']);

		$this->data ['svpOddeleni'] = $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");

    $this->data ['stat'] = "Česká republika";
    $this->data ['stObcan'] = "Česká republika";
    $this->data ['rokVystaveni'] = strval(intval($this->recData ['skolniRok']) + 1);
    $this->data ['datumVystaveni1pol'] = $this->datum($skolniRok['V1']);
    $this->data ['datumVystaveni2pol'] = $this->datum($skolniRok['V2']);
    $this->data ['pololeti'] = "první";


    // -- závěrečné vysvědčení
    if ($this->recData ['typVysvedceni'] == 1)
      $this->data ['typVysvedceni'] = "Výpis závěrečného vysvědčení";
    else
      $this->data ['typVysvedceni'] = "Výpis z vysvědčení";

    // -- znamky
		$tabulkaZnamky = new \E10Pro\Zus\TableZnamky ($this->app);
		$q = "SELECT * FROM [e10pro_zus_znamky] WHERE [vysvedceni] = %i ORDER BY ndx";
		$rows = $this->table->db()->query($q, $this->recData ['ndx']);

		forEach ($rows as $row)
		{
			$r = $row;
			$r ['print'] = $this->getPrintValues ($tabulkaZnamky, $r);
			$this->data ['rows'][] = $r;
		}

		// dorovnat počet řádků na 12 (formulář vysvědčení)
		while (count ($this->data ['rows'] ?? []) < 12)
		{
			$r = array ('predmet' => '---', 'znamka1p' => 0, 'znamka2p' => 0);
			$r ['print'] = $this->getPrintValues ($tabulkaZnamky, $r);
			$this->data ['rows'][] = $r;
		}

		// učitel
		$q = "SELECT * FROM [e10_persons_persons] WHERE [ndx] = %i";
		$this->data ['ucitel'] = $this->table->db()->query($q, $this->recData ['ucitel'])->fetch ();

		// student
		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data ['student'] = $this->table->loadItem ($this->recData ['student'], 'e10_persons_persons');
		$this->data ['student']['lists'] = $tablePersons->loadLists ($this->data ['student']);

    if ($this->data ['student']['lastName'] == 'Kieu') {
      $this->data ['stObcan'] = 'Viet Nam';
    }

		$bdate = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'birthdate');
		if ($bdate)
			//$this->data ['birthDate'] = str_replace (' ', '&thinsp;', $bdate ['value']->format ('j. n. Y'));
			$this->data ['birthDate'] = $bdate ['value']->format ('j') . '. ' . \E10Pro\Zus\monthName2 ($bdate ['value']->format ('n'))  . ' ' . $bdate ['value']->format ('Y');

		$rodneCislo = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'pid');
		if ($rodneCislo)
			$this->data ['rodneCislo'] = $rodneCislo ['value'];

		// vyřešit hodnocení s ohledem na pohlaví
		$hodnoceni = $this->app()->cfgItem ('zus.hodnoceni');
		$rcm = intval (substr($this->recData ['rodneCislo'], 2, 2));
		if ($rcm >= 50)
		{ // žena
			$this->data ['hodnoceni1p'] = $hodnoceni [$this->recData['hodnoceni1p']]['z'];
			$this->data ['hodnoceni2p'] = $hodnoceni [$this->recData['hodnoceni2p']]['z'];
		}
		else
		{ // muž
			$this->data ['hodnoceni1p'] = $hodnoceni [$this->recData['hodnoceni1p']]['m'];
			$this->data ['hodnoceni2p'] = $hodnoceni [$this->recData['hodnoceni2p']]['m'];
		}

		// -- obor - potvrzení
		$obory = $this->app()->cfgItem ('zus.obor');
		//$this->data ['oborPotvrzeni'] = $obory [$this->recData ['obor']]['potvrzeni'];
    $this->data ['oborPotvrzeni'] = $this->app()->cfgItem ("e10pro.zus.obory.{$this->recData ['svpObor']}.pojmenovani");
	}
} // class VysvedceniReportOpis


/**
 * VysvedceniReportDetail
 *
 * Výstupní sestava Vysvědčení - verze pro detail prohlížeče
 *
 *
 */

class VysvedceniReportDetail extends VysvedceniReportOpis
{
	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.detail';
		$this->reportTemplate = 'reports.modern.e10pro.zus.detail';
	}
}

/**
 * VysvedceniReportTisk
 *
 * Výstupní sestava Vysvědčení - verze pro detail prohlížeče
 *
 *
 */

class VysvedceniReportTisk extends VysvedceniReportOpis
{
	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.vysvedceni';
		if ($this->recData ['typVysvedceni'] == 2)
			$this->reportTemplate = 'reports.modern.e10pro.zus.potvrzeni';
		else
			$this->reportTemplate = 'reports.modern.e10pro.zus.vysvedceni';
		$this->srcFileExtension = 'fo';
	}
}

/**
 * VysvedceniBReportTisk
 *
 * Výstupní sestava Vysvědčení - verze pro detail prohlížeče
 *
 *
 */

class VysvedceniBReportTisk extends VysvedceniReportOpis
{
	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.vysvedceniB';
		if ($this->recData ['typVysvedceni'] == 2)
			$this->reportTemplate = 'reports.modern.e10pro.zus.potvrzeniB';
		else
			$this->reportTemplate = 'reports.modern.e10pro.zus.vysvedceniB';
		$this->srcFileExtension = 'fo';
	}

  public function loadData ()
	{
    parent::loadData ();

    if ($this->recData ['typVysvedceni'] == 1)
      $this->data ['typVysvedceni'] = "ZÁVĚREČNÉ VYSVĚDČENÍ";
    else
      $this->data ['typVysvedceni'] = "VYSVĚDČENÍ";
  }

}


/**
 * Widget s vysvědčeními studenta
 *
 */

class WidgetVysvedceniStudenta2 extends \E10\TableView
{
	public $posledniSkupina;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('student'))
			$this->addAddParam ('student', $this->queryParam ('student'));
		$this->setName ('Vysvědčení');
		$this->posledniSkupina = '';
		parent::init();
	}

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$itemPrint = utils::getPrintValues ($this->table, $item);

		$skupina = $itemPrint['obor'] . ' obor, oddělení ' . $itemPrint['oddeleni'];

		if ($this->posledniSkupina != $skupina)
		{
			$this->addGroupHeader ('Vysvědčení: ' . $skupina);
			$this->posledniSkupina = $skupina;
		}

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $skolniRoky [$item['skolniRok']]['nazev'];
		$listItem ['i1'] = $itemPrint ['rocnik'] .' ročník';
		$listItem ['i2'] = $itemPrint ['hodnoceni1p'] .' / ' . $itemPrint ['hodnoceni2p'];
		if ($this->docState)
		{
			$docStateName = $this->table->getDocumentStateInfo ($this->docState ['states'], $item, 'name');
			$docStateIcon = $this->table->getDocumentStateInfo ($this->docState ['states'], $item, 'styleIcon');
			if ($docStateName)
				$listItem ['t2'] = $docStateName;
			$listItem ['i'] = $docStateIcon;
		}
		return $listItem;
	}


	public function selectRows ()
	{
		$q = "SELECT * FROM [e10pro_zus_vysvedceni] WHERE [student] = %i AND [smazano] = 0 AND [stavHlavni] < 4 ORDER BY [obor], [oddeleni], [rocnik] DESC, [ndx] DESC" . $this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('student'));
	}
} // class WidgetVysvedceniStudenta


/**
 * Widget s vysvědčeními učitele
 *
 */

class WidgetVysvedceniUcitele extends \E10\TableView
{
	public $posledniSkupina;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('teacher'))
			$this->addAddParam ('ucitel', $this->queryParam ('teacher'));
		$this->setName ('Vysvědčení');
		$this->posledniSkupina = '';
		parent::init();
	}

  public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$itemPrint = utils::getPrintValues ($this->table, $item);

		$skupina = isset($skolniRoky [$item['skolniRok']]) ? $skolniRoky [$item['skolniRok']]['nazev'] : '!!!'.$item['skolniRok'];

		if ($this->posledniSkupina != $skupina)
		{
			$this->addGroupHeader ('Školní rok: ' . $skupina);
			$this->posledniSkupina = $skupina;
		}

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['jmeno'];
		$listItem ['i1'] = $itemPrint ['rocnik'] .' ročník';

		$typyVysvedceni = $this->table->columnInfoEnum ('typVysvedceni', 'cfgText');
		$listItem ['i2'] = $typyVysvedceni [$item ['typVysvedceni']];

		/*if ($this->documentStates)
		{
			$docStateName = $this->table->getDocumentStateInfo ($this->documentStates, $item, 'name');
			$docStateIcon = $this->table->getDocumentStateInfo ($this->documentStates, $item, 'styleIcon');
			if ($docStateName)
				$listItem ['t2'] = $docStateName;
			$listItem ['i'] = $docStateIcon;
		}*/
		return $listItem;
	}


	public function selectRows ()
	{
		$q = "SELECT vysvedceni.*, persons.fullName as personFullName FROM [e10pro_zus_vysvedceni] as vysvedceni LEFT JOIN e10_persons_persons AS persons ON vysvedceni.student = persons.ndx WHERE [ucitel] = %i ORDER BY [stavHlavni], [skolniRok] DESC, persons.lastName" . $this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('teacher'));
	}
} // class WidgetVysvedceniUcitele
