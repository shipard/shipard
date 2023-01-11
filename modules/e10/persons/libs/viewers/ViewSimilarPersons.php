<?php
namespace e10\persons\libs\viewers;

use \Shipard\Viewer\TableView;


/**
 * class ViewSimilarPersons
 */
class ViewSimilarPersons extends TableView
{
  public $properties = [];
	var $classification = [];
	public $addresses = [];

	public function init ()
	{
    parent::init();

    $this->enableDetailSearch = FALSE;
    $this->enableToolbar = FALSE;
    $this->enableFullTextSearch = FALSE;
		$this->objectSubType = TableView::vsMini;
	}

	public function selectRows ()
	{
    $firstName = $this->queryParam('firstName') ? $this->queryParam('firstName') : '';
    $lastName = $this->queryParam('lastName') ? $this->queryParam('lastName') : '';
    $idcn = $this->queryParam('idcn') ? $this->queryParam('idcn') : '';

		$q = [];
    array_push ($q, 'SELECT [persons].* ');
    array_push ($q, ' FROM [e10_persons_persons] AS [persons]');
    array_push ($q, ' WHERE 1');


    if ($firstName !== '' || $lastName !== '')
    {
      array_push ($q, ' AND (');

      if ($lastName !== '')
        array_push($q, '[persons].[lastName] LIKE %s', $lastName.'%');
      if ($firstName)
      {
        if ($lastName !== '')
          array_push ($q, ' AND ');
        array_push($q, '[persons].[firstName] LIKE %s', $firstName.'%');
      }

      array_push ($q, ')');
    }

    if ($idcn !== '')
    {
      if ($firstName !== '' || $lastName !== '')
        array_push ($q, ' OR');
      else
        array_push ($q, ' AND');

      array_push ($q, ' EXISTS (SELECT ndx FROM e10_base_properties WHERE persons.ndx = e10_base_properties.recid ',
        ' AND valueString LIKE %s', $idcn.'%',
        ' AND tableid = %s)', 'e10.persons.persons');
    }
		//array_push ($q, ' AND docStateMain <= 2');
		array_push ($q, ' ORDER BY [persons].[lastName]');

    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->properties = $this->table->loadProperties ($this->pks);
		//$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	function decorateRow (&$item)
	{
    $idcn = $this->queryParam('idcn') ? $this->queryParam('idcn') : '';

		if (isset ($this->properties [$item ['pk']]['ids']))
    {
      foreach ($this->properties [$item ['pk']]['ids'] as $tst)
      {
        $tst['class'] = 'label label-default';
        if ($tst['text'] === $idcn)
          $tst['class'] = 'label label-danger';
			  $item ['t2'][] = $tst;
      }
    }

    if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = array_merge ($item ['t2'], array_slice ($this->properties [$item ['pk']]['contacts'], 0, 2, TRUE));

		if (!count($item ['t2']))
			$item ['t2'] = ' ';
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['id'], 'class' => 'id'];
    $listItem ['t2'] = [];

		return $listItem;
	}
}
