<?php

namespace e10\persons\libs\viewers;
use \e10\TableView, e10\utils;


/**
 * Class ContactsForm
 * @package e10\persons\libs\viewers
 */
class ContactsForm extends TableView
{
	var $dstRecNdx = 0;
	var $dstTableNdx = 0;

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

		$this->dstTableNdx = intval($this->queryParam('dstTableNdx'));
		$this->dstRecNdx = intval($this->queryParam('dstRecNdx'));

		$this->addAddParam('tableNdx', $this->dstTableNdx);
		$this->addAddParam('recNdx', $this->dstRecNdx);

		$this->toolbarTitle = ['text' => 'Kontakty', 'class' => 'h2 e10-bold'/*, 'icon' => 'icon-map-marker'*/];

		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_contacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[tableNdx] = %s', $this->dstTableNdx);
		array_push ($q, ' AND [contacts].[recNdx] = %i', $this->dstRecNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [contacts].name LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].[role] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[contacts].', ['[name]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['tt'] = $item['name'];

		$listItem ['t2'] = [];

		if ($item['role'] !== '')
			$listItem ['t2'][] = ['text' => $item['role'], 'class' => 'label label-default'];

		if ($item['email'] !== '')
			$listItem ['t2'][] = ['text' => $item['email'], 'class' => 'nowrap', 'icon' => 'icon-envelope'];
		if ($item['phone'] !== '')
			$listItem ['t2'][] = ['text' => $item['phone'], 'class' => 'nowrap', 'icon' => 'icon-phone'];

		return $listItem;
	}
}
