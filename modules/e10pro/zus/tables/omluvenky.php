<?php

namespace e10pro\zus;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * class TableOmluvenky
 */
class TableOmluvenky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.omluvenky', 'e10pro_zus_omluvenky', 'Omluvenky');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset($recData['datumOd']))
			$recData['datumOd'] = Utils::today();
		if (!isset($recData['datumDo']))
			$recData['datumDo'] = Utils::today();

		if (!isset($recData['authorUser']))
			$recData['authorUser'] = $this->app()->uiUserNdx();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (!isset($recData['datumOd']) || Utils::dateIsBlank($recData['datumOd']))
			$recData['datumOd'] = Utils::today();
		if (!isset($recData['datumDo']) || Utils::dateIsBlank($recData['datumDo']) || $recData['datumOd'] > $recData['datumDo'])
			$recData['datumDo'] = Utils::createDateTime($recData['datumOd']);

		$recData['dlouhodoba'] = intval(Utils::createDateTime($recData['datumOd']) != Utils::createDateTime($recData['datumDo']));
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['docState']) && $recData['docState'] == 4000)
		{
			$ee = new \e10pro\zus\libs\ExcuseEngine($this->app());
			$ee->setExcuse($recData['ndx']);
			$ee->loadAffectedHours();
			$ee->saveAffectedHours();
		}

		parent::checkAfterSave2 ($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

    if (isset($recData['student']) && $recData['student'])
    {
      $studentRecData = $this->app()->loadItem($recData['student'] ?? 0, 'e10.persons.persons');
      if ($studentRecData)
        $hdr ['info'][] = ['class' => 'title', 'value' => $studentRecData ['fullName']];
    }
    if ($recData['dlouhodoba'] ?? 0)
    {
      if (!Utils::dateIsBlank($recData['datumOd']) && !Utils::dateIsBlank($recData['datumDo']))
        $hdr ['info'][] = ['class' => 'info', 'value' => Utils::dateFromTo(Utils::createDateTime($recData['datumOd']), Utils::createDateTime($recData['datumDo']), NULL)];
    }
    else
    {
      if (!Utils::dateIsBlank($recData['datumOd']))
        $hdr ['info'][] = ['class' => 'info', 'value' => Utils::datef($recData['datumOd'])];
    }
		return $hdr;
	}
}


/**
 * class ViewOmluvenky
 */
class ViewOmluvenky extends TableView
{
	var $duvodyOmluveni;
	var $teachers = [];

	public function init ()
	{
		$this->duvodyOmluveni = $this->app()->cfgItem('zus.duvodyOmluveni');

		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item ['studentFullName'];

		if (!Utils::dateIsBlank($item['datumOd']) && !Utils::dateIsBlank($item['datumDo']))
		{
			if ($item['datumOd'] === $item['datumDo'])
				$listItem ['t2'] = ['text' => Utils::datef($item['datumOd'], '%d'), 'class' => 'label label-default'];
			else
				$listItem ['t2'] = ['text' => Utils::datef($item['datumOd'], '%d').' - '.Utils::datef($item['datumDo'], '%d'), 'class' => 'label label-default'];
		}

		$listItem ['i2'] = [['text' => $this->duvodyOmluveni[$item['duvod']]['fn'], 'class' => 'label label-default']];

		//if ($item['userName'] && $item['userName'] !== '')
		//	$listItem ['i2'][] = ['text' => $item['userName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$teacherNdx = $this->app()->userNdx();

    $q = [];
    array_push($q, 'SELECT omluvenky.*,');
    array_push($q, ' students.fullName AS studentFullName, [users].fullName AS userName');
    array_push($q, ' FROM [e10pro_zus_omluvenky] AS omluvenky');
    array_push($q, ' LEFT JOIN e10_persons_persons AS students ON omluvenky.student = students.ndx');
		array_push($q, ' LEFT JOIN [e10_users_users] AS [users] ON omluvenky.authorUser = [users].ndx');
    array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (students.[fullName] LIKE %s)', '%'.$fts.'%');
		}

		if (!$this->app()->hasRole('zusadm'))
		{
			array_push($q, '  AND EXISTS (');
				array_push($q, ' SELECT rozvrh.ucitel FROM e10pro_zus_omluvenkyHodiny AS omluvenkyHodiny');
				array_push($q, ' LEFT JOIN [e10pro_zus_vyukyrozvrh] AS rozvrh ON omluvenkyHodiny.rozvrh = rozvrh.ndx');
				array_push($q, ' LEFT JOIN [e10_persons_persons] AS ucitele ON rozvrh.ucitel = ucitele.ndx');
				array_push($q, ' WHERE omluvenky.ndx = omluvenkyHodiny.omluvenka');
				array_push($q, ' AND rozvrh.ucitel = %i', $teacherNdx);
			array_push($q, ')');
		}

		$this->queryMain ($q, 'omluvenky.', ['[datumOd] DESC', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$q = [];
		array_push($q, 'SELECT ucitele.ndx, ucitele.fullName AS ucitelJmeno, omluvenkyHodiny.omluvenka');
		array_push($q, ' FROM [e10pro_zus_omluvenkyHodiny] AS omluvenkyHodiny');
		array_push($q, ' LEFT JOIN [e10pro_zus_vyukyrozvrh] AS rozvrh ON omluvenkyHodiny.rozvrh = rozvrh.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS ucitele ON rozvrh.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [omluvenka] IN %in', $this->pks);
		array_push($q, ' GROUP BY 1, 2, 3');
		array_push($q, ' ORDER BY ucitele.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$label = ['text' => $r['ucitelJmeno'], 'class' => 'label label-default'];
			$this->teachers[$r['omluvenka']][] = $label;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->teachers [$item ['pk']]))
		{
			$item ['t3'] = $this->teachers [$item ['pk']];
		}
	}

  public function createToolbar_TMP ()
	{
		return [];
	}
}


/**
 * Class FormOmluvenka
 */
class FormOmluvenka extends TableForm
{
	public function renderForm ()
	{
    $this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			if (!$this->recData['student'])
				$this->addColumnInput ('student');

			$this->addColumnInput ('datumOd');
			$this->addColumnInput ('datumDo');
			$this->addColumnInput ('duvod');

			$this->addColumnInput ('pouzitCasOdDo');
			if ($this->recData['pouzitCasOdDo'])
			{
				$this->addColumnInput ('casOd');
				$this->addColumnInput ('casDo');
			}
		$this->closeForm ();
	}
}


/**
 * class ViewDetailOmluvenka
 */
class ViewDetailOmluvenka extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.DCExcuse');
	}
}

