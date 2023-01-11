<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;

/**
 * class ViewPersonBA
 */
class ViewPersonBA extends TableView
{
	var $personNdx = 0;
	var $classification = [];

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

    $this->personNdx = intval($this->queryParam('personNdx'));
		$this->addAddParam('person', $this->personNdx);

		$this->toolbarTitle = ['text' => 'Bankovní spojení', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];
		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [ba].* ';
		array_push ($q, ' FROM [e10_persons_personsBA] AS [ba]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [ba].[person] = %i', $this->personNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [ba].bankAccount LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [ba].adrStreet LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [ba].adrSpecification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[ba].', ['[bankAccount]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['bankAccount'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar();

		$reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$companyId = $reg->loadPersonOid($this->personNdx);

		if ($companyId !== '')
		{
			$toolbar[] = [
				'text' => 'Účty', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'user/wifi',
				'title' => 'Načíst účty z registrů',
				'class' => 'pull-right',
				'element' => 'span',
				'btnClass' => 'pull-right',
				'data-class' => 'e10.persons.libs.register.AddBAWizard',
				'table' => 'e10.persons.persons',
				'data-addparams' => 'personId='.$companyId.'&personNdx='.$this->personNdx,
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
			];
		}

		return $toolbar;
	}
}
