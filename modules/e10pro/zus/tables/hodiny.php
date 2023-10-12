<?php

namespace E10Pro\Zus;

use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableHodiny
 * @package E10Pro\Zus
 */
class TableHodiny extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.hodiny', 'e10pro_zus_hodiny', 'Hodiny');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$recData['sDomacimUkolem'] = strlen(trim($recData['domaciUkol'])) > 0;
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['ndx']) && $recData['ndx'] && $recData['vyuka'])
		{
			$vyuka = $this->loadItem($recData['vyuka'], 'e10pro_zus_vyuky');
			if ($vyuka && $vyuka['typ'] == 0 && $recData['hromadnaPritomnost'] != 0)
			{ // kolektivní
				$this->db()->query (
					'UPDATE [e10pro_zus_hodinydochazka] SET pritomnost = %i', $recData['hromadnaPritomnost'],
					' WHERE hodina = %i', $recData['ndx']);
				$recData['hromadnaPritomnost'] = 0;
			}
		}

		parent::checkAfterSave2($recData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (isset($recData['vyuka']) && isset($recData['datum']))
		{
			$vyuka = $this->app()->loadItem($recData['vyuka'], 'e10pro.zus.vyuky');
			if ($vyuka['typ'] === 1)
			{ // individualni
				$eex = $this->db()->query('SELECT * FROM [e10pro_zus_omluvenky] WHERE 1',
																	' AND [student] = %i', $vyuka['student'],
																	' AND [datumOd] <= %d', $recData['datum'],
																	' AND [datumDo] >= %d', $recData['datum'],
																	' AND [docState] = %i', 4000
						)->fetch();
				if ($eex)
					$recData['pritomnost'] = 2;
			}
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];

		$vyuka = $this->app()->loadItem($recData['vyuka'], 'e10pro.zus.vyuky');

		$tablePersons = $this->app()->table('e10.persons.persons');

		$hdr ['icon'] = ($vyuka['typ'] === 0) ? 'icon-group' : 'icon-user';

		if ($vyuka['typ'] === 1)
		{ // individuální
			$student = $tablePersons->loadItem ($vyuka['student']);
			$hdr ['info'][] = [
				'class' => 'title',
				'value' => [
					//['text' => $student['fullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $recData['student']],
					['text' => $student['fullName'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk'=> $recData['vyuka']],
					['text' => utils::datef($recData['datum']).', '.$recData['zacatek'], 'class' => 'pull-right']
				]
			];

			$hdr ['info'][] = [
				'class' => 'info',
				'value' => [
					['text' => $this->app()->cfgItem ("e10pro.zus.predmety.{$vyuka['svpPredmet']}.nazev")]
				]
			];
		}
		else
		{
			$hdr ['info'][] = [
				'class' => 'title', 'value' => [
					['text' => $vyuka['nazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk'=> $recData['vyuka']],
					['text' => utils::datef($recData['datum']).', '.$recData['zacatek'], 'class' => 'pull-right']
				]
			];
		}

		return $hdr;
	}

	public function novaHodina ($rozvrhNdx, $datum)
	{
		$rozvrhRecData = $this->app()->loadItem($rozvrhNdx, 'e10pro.zus.vyukyrozvrh');
		$vyukaRecData = $this->app()->loadItem($rozvrhRecData['vyuka'], 'e10pro.zus.vyuky');

		$hodina = [
			'vyuka' => $rozvrhRecData['vyuka'], 'rozvrh' => $rozvrhNdx, 'ucitel' => $vyukaRecData['ucitel'],
			'datum' => $datum, 'zacatek' => $rozvrhRecData['zacatek'], 'konec' => $rozvrhRecData['konec'],
			'pobocka' => $rozvrhRecData['pobocka'], 'ucebna' => $rozvrhRecData['ucebna'],
			'stav' => 1000, 'stavHlavni' => 2
		];

		$hodinaNdx = $this->dbInsertRec($hodina);

		if ($vyukaRecData['typ'] == 0)
		{ // kolektivní
			$q[] = 'SELECT studenti.*, studia.student as studentNdx FROM e10pro_zus_vyukystudenti AS studenti';
			array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON studenti.studium = studia.ndx');
			array_push($q, ' WHERE [vyuka] = %i', $rozvrhRecData['vyuka']);
			array_push($q, ' AND (');
			array_push($q, '(studia.datumUkonceniSkoly IS NULL OR studia.datumUkonceniSkoly >= %d', $datum, ')');
			array_push($q, ' AND (studia.datumNastupuDoSkoly IS NULL OR studia.datumNastupuDoSkoly <= %d', $datum, ')');
			array_push($q, ')');
			$studenti = $this->db()->query ($q);
			foreach ($studenti as $r)
			{
				$dochazka = ['hodina' => $hodinaNdx, 'student' => $r['studentNdx'], 'studium' => $r['studium'], 'pritomnost' => 0];
				$this->db()->query('INSERT INTO [e10pro_zus_hodinydochazka]', $dochazka);
			}
		}
		else
		{ // individualní
			$dochazka = ['hodina' => $hodinaNdx, 'student' => $vyukaRecData['student'], 'studium' => $vyukaRecData['studium'], 'pritomnost' => 0];
			$this->db()->query ('INSERT INTO [e10pro_zus_hodinydochazka]', $dochazka);
		}
	}

	public function zahajitHodinu ($hodinaNdx)
	{
		$this->db()->query ('UPDATE [e10pro_zus_hodiny] SET stav = 1200, stavHlavni = 1 WHERE ndx = %i', $hodinaNdx);
		$this->db()->query ('UPDATE [e10pro_zus_hodinydochazka] SET pritomnost = 1 WHERE hodina = %i', $hodinaNdx);
		$this->docsLog($hodinaNdx);
	}

	public function ukoncitHodinu ($hodinaNdx)
	{
		$this->db()->query ('UPDATE [e10pro_zus_hodiny] SET stav = 4000, stavHlavni = 3 WHERE ndx = %i', $hodinaNdx);
		$this->docsLog($hodinaNdx);
	}

	public function prepnoutPritomnost ($dochazkaNdx)
	{
		$dochazka = $this->app()->loadItem($dochazkaNdx, 'e10pro.zus.hodinydochazka');
		if (!$dochazka)
			return;

		if ($dochazka['pritomnost'] == 1) $d = 2;
		elseif ($dochazka['pritomnost'] == 2) $d = 3;
		elseif ($dochazka['pritomnost'] == 3) $d = 1;

		$this->db()->query (
			'UPDATE [e10pro_zus_hodinydochazka] SET pritomnost = %i', $d,
			' WHERE ndx = %i', $dochazkaNdx);
	}
}


/**
 * Class ViewHodiny
 * @package E10Pro\Zus
 */
class ViewHodiny extends TableView
{
}


/**
 * Class ViewHodinyCombo
 * @package E10Pro\Zus
 */
class ViewHodinyCombo extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = utils::datef($item ['datum']);
		$listItem ['txt'] = $item['probiranaLatka'];
		$listItem ['i1'] = $item['zacatek'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['data-cc']['probiranaLatka'] = $item['probiranaLatka'];

		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT * FROM [e10pro_zus_hodiny]';
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND stavHlavni = %i', 3);

		if ($this->queryParam ('vyuka'))
			array_push ($q, ' AND vyuka = %i', $this->queryParam ('vyuka'));

		array_push ($q, ' ORDER BY [datum] DESC, [zacatek] DESC', $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailHodina
 * @package E10Pro\Zus
 */
class ViewDetailHodina extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormHodina
 * @package E10Pro\Zus
 */
class FormHodina extends TableForm
{
	var $vyuka = NULL;

	public function renderForm ()
	{
		$vyuka = $this->vyuka();

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

		$tabs ['tabs'][] = ['text' => 'Látka', 'icon' => 'system/formHeader'];

		//$tabs ['tabs'][] = ['text' => 'Známky', 'icon' => 'icon-star'];
		if ($vyuka['typ'] === 0)
			$tabs ['tabs'][] = ['text' => 'Docházka', 'icon' => 'system/iconUser'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		if ($this->app()->hasRole('root'))
			$tabs ['tabs'][] = ['text' => 'Historie', 'icon' => 'system/formHistory'];

		$this->openTabs ($tabs, TRUE);

		$this->openTab (TableForm::ltNone);
			$this->layoutOpen(TableForm::ltForm);
				$this->addColumnInput('probiranaLatka'/*, TableForm::coFullSizeY*/);
				$this->addColumnInput('domaciUkol'/*, TableForm::coFullSizeY*/);
			$this->layoutClose();

			if ($vyuka['typ'] === 1)
			{
				$this->layoutOpen(TableForm::ltForm);
					$this->addSeparator(TableForm::coH2);
					$this->openRow();
						$this->addColumnInput('klasifikaceZnamka');
						$this->addColumnInput('klasifikacePoznamka');
						$this->addColumnInput('pritomnost');
					$this->closeRow();
				$this->layoutClose();
			}
		$this->closeTab ();

		/*
		$this->openTab (TableForm::ltNone);
			$this->addList ('hodnoceni');
		$this->closeTab ();
		*/

		if ($vyuka['typ'] === 0)
		{
			$this->openTab(TableForm::ltNone);
				if (!$this->readOnly)
				{
					$this->layoutOpen(self::ltForm);
						$this->addColumnInput('hromadnaPritomnost');
					$this->layoutClose('pull-right');
					$this->addSeparator(self::coH2);
				}
				$this->addList('dochazka');
			$this->closeTab();
		}

		$this->openTab ();
			$this->addColumnInput('ucitel');
			$this->addColumnInput('datum');
			$this->addColumnInput('zacatek');
			$this->addColumnInput('konec');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer();
		$this->closeTab ();

		if ($this->app()->hasRole('root'))
		{
			$this->openTab(self::ltNone);
				$params = ['tableid' => $this->tableId(),'recid' => $this->recData['ndx']];
				$this->addViewerWidget('e10.base.docslog', 'e10.base.libs.ViewDocsLogDocHistory', $params);
			$this->closeTab();
		}

		$this->closeTabs ();

		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10pro.zus.hodiny')
		{
			if ($srcColumnId === 'probiranaLatka')
			{
				$cp = [
						'vyuka' => strval($allRecData ['recData']['vyuka']),
				];
				return $cp;
			}
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	function vyuka ()
	{
		if (!$this->vyuka)
			$this->vyuka = $this->table->loadItem($this->recData['vyuka'], 'e10pro_zus_vyuky');
		return $this->vyuka;
	}

	function checkLoadedList ($list)
	{
		if ((!isset($this->recData['stavHlavni']) || $this->recData['stavHlavni'] < 3) && ($list->listId === 'dochazka') && (count($list->data) === 0))
		{
			$vyuka = $this->vyuka();
			if ($vyuka['typ'] === 0)
			{
				$q[] = 'SELECT studenti.*, studia.student as studentNdx FROM e10pro_zus_vyukystudenti AS studenti';
				array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON studenti.studium = studia.ndx');
				array_push($q, ' WHERE [vyuka] = %i', $this->recData['vyuka']);
				array_push($q, ' AND (');
				array_push($q, '(studia.datumUkonceniSkoly IS NULL OR studia.datumUkonceniSkoly >= %d', $this->recData['datum'], ')');
				array_push($q, ' AND (studia.datumNastupuDoSkoly IS NULL OR studia.datumNastupuDoSkoly <= %d', $this->recData['datum'], ')');
				array_push($q, ')');

				array_push($q, ' AND (');
				array_push($q, '(studenti.platnostDo IS NULL OR studenti.platnostDo >= %d', $this->recData['datum'], ')');
				array_push($q, ' AND (studenti.platnostOd IS NULL OR studenti.platnostOd <= %d', $this->recData['datum'], ')');
				array_push($q, ')');

				$studenti = $this->table->db()->query ($q);
				foreach ($studenti as $r)
				{
					$np = 1;

					$eex = $this->table->db()->query('SELECT * FROM [e10pro_zus_omluvenky] WHERE 1',
																					' AND [student] = %i', $r['studentNdx'],
																					' AND [datumOd] <= %d', $this->recData['datum'],
																					' AND [datumDo] >= %d', $this->recData['datum'],
																					' AND [docState] = %i', 4000
																		)->fetch();
					if ($eex)
						$np = 2;
					$list->data [] = ['student' => $r['studentNdx'], 'studium' => $r['studium'], 'pritomnost' => $np];
				}
			}
		}

		parent::checkLoadedList($list);
	}
}

