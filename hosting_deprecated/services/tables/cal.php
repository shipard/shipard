<?php

namespace E10pro\Hosting\Services;


use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

/**
 * Class TableCal
 * @package E10pro\Hosting\Services
 */
class TableCal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.services.cal', 'e10pro_hosting_services_cal', 'Kalendář');
	}

	public function dateText ($recData)
	{
		$dateText = '';
		$dateText .= $recData['day'] ? $recData['day'] : '*';
		$dateText .= '.' . ($recData['month'] ? $recData['month'] : '*');
		if (!$recData['yearFrom'] && !$recData['yearTo'])
			$dateText .= '.*';
		else
			$dateText .= '.'.($recData['yearFrom'] ? $recData['yearFrom'] : '*').'-'.($recData['yearTo'] ? $recData['yearTo'] : '*');
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

	public function tableIcon ($recData, $options = NULL)
	{
		$eventType = $this->app()->cfgItem('hosting.services.calendar.eventTypes.'.$recData['type']);
		return $eventType['icon'];
	}
}


/**
 * Class ViewCal
 * @package E10pro\Hosting\Services
 */
class ViewCal extends TableView
{
	var $eventTypes;
	var $countries;

	public function init ()
	{
		parent::init();

		$this->eventTypes = $this->app()->cfgItem('hosting.services.calendar.eventTypes');
		$this->countries = $this->app()->cfgItem('e10.base.countries');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['i1'] = ['text' => $this->table->dateText($item), 'class' => 'id'];

		$props = [];
		if ($item['nonWorkingDay'])
			$props [] = ['icon' => 'icon-times', 'text' => '', 'class' => ''];
		$props [] = ['text' => $this->eventTypes[$item['type']]['name'], 'class' => ''];
		$listItem ['i2'] = $props;

		if ($item['country'] !== '--')
			$listItem ['t2'] = ['text' => $this->countries[$item['country']]['name'], 'class' => 'label label-info'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_hosting_services_cal]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[yearFrom]', '[month]', '[day]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailCal
 * @package E10pro\Hosting\Services
 */
class ViewDetailCal extends TableViewDetail
{
}


/**
 * Class FormCal
 * @package E10pro\Hosting\Services
 */
class FormCal extends TableForm
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
				$this->addColumnInput ('yearFrom', TableForm::coColW2);
				$this->addColumnInput ('yearTo', TableForm::coColW2);
				$this->addColumnInput ('type', TableForm::coColW4);
			$this->closeRow();
		$this->layoutClose();


		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
		$this->openTabs ($tabs);

		$this->openTab ();
			$this->addColumnInput ('title');
			$this->addColumnInput ('url');
			$this->addColumnInput ('country');
			$this->addColumnInput ('nonWorkingDay');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer();
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
}


