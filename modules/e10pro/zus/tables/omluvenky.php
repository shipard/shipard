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

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

    if (isset($recData['student']) && $recData['student'])
    {
      $studentRecData = $this->app()->loadItem($recData['student'] ?? 0, 'e10.persons.persons');
      if ($studentRecData)
        $hdr ['info'][] = ['class' => 'title', 'value' => $studentRecData ['fullName']];
    }
    if ($recData['dlouhodoba'])
    {
      if (!Utils::dateIsBlank($recData['datumOd']) && !Utils::dateIsBlank($recData['datumDo']))
        $hdr ['info'][] = ['class' => 'info', 'value' => Utils::dateFromTo($recData['datumOd'], $recData['datumDo'], NULL)];
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
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item ['studentFullName'];

    $listItem ['t2'] = Utils::dateFromTo($item['datumOd'], $item['datumDo'], NULL);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

    $q = [];
    array_push($q, 'SELECT omluvenky.*,');
    array_push($q, ' students.fullName AS studentFullName');
    array_push($q, ' FROM [e10pro_zus_omluvenky] AS omluvenky');
    array_push($q, ' LEFT JOIN e10_persons_persons AS students ON omluvenky.student = students.ndx');
    array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (students.[fullName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, 'omluvenky.', ['[datumOd] DESC', '[ndx]']);
		$this->runQuery ($q);
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
      $this->addColumnInput ('student');
      $this->openRow();
        $this->addColumnInput ('datumOd');
        $this->addColumnInput ('dlouhodoba');
      $this->closeRow();
      if ($this->recData['dlouhodoba'])
        $this->addColumnInput ('datumDo');
      $this->addColumnInput ('text');
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case'datumOd': return ($this->recData['dlouhodoba']) ? 'Datum od' : 'Datum';
		}
		return parent::columnLabel ($colDef, $options);
	}
}


/**
 * class ViewDetailOmluvenka
 */
class ViewDetailOmluvenka extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

