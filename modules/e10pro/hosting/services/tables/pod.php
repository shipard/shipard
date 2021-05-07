<?php

namespace E10pro\Hosting\Services;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

/**
 * Class TablePod
 * @package E10pro\Hosting\Services
 */
class TablePod extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.services.pod', 'e10pro_hosting_services_pod', 'Obrázky dne');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		$recData['priority'] = 100;

		if ($recData['day']) $recData['priority']--;
		if ($recData['month']) $recData['priority']--;
		if ($recData['year']) $recData['priority']--;
	}

	public function dateText ($recData)
	{
		$dateText = '';
		$dateText .= $recData['day'] ? $recData['day'] : '*';
		$dateText .= '.'.($recData['month'] ? $recData['month'] : '*');
		$dateText .= '.'.($recData['year'] ? $recData['year'] : '*');
		return $dateText;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$topInfo = [['text' => $this->dateText($recData)]];
		$hdr ['info'][] = ['class' => 'info', 'value' => $topInfo];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * Class ViewPod
 * @package E10pro\Hosting\Services
 */
class ViewPod extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['i1'] = ['text' => $this->table->dateText($item), 'class' => 'id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_hosting_services_pod]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[year]', '[month]', '[day]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailPod
 * @package E10pro\Hosting\Services
 */
class ViewDetailPod extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentAttachments($this->item['ndx']);
	}
}


/**
 * Class FormPod
 * @package E10pro\Hosting\Services
 */
class FormPod extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltGrid);
			$this->openRow ('grid-form-tabs');
				$this->addColumnInput ('day', TableForm::coColW2);
				$this->addColumnInput ('month', TableForm::coColW2);
				$this->addColumnInput ('year', TableForm::coColW2);
			$this->closeRow();
		$this->layoutClose();


		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
		$this->openTabs ($tabs);

		$this->openTab ();
			$this->addColumnInput ('title');
			$this->addColumnInput ('url');
			$this->addColumnInput ('anniversaryDate');
			$this->addColumnInput ('picture');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			\E10\Base\addAttachmentsWidget ($this);
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
}


