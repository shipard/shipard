<?php

namespace e10\calendar;
use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDays
 * @package e10\calendar
 */
class TableDays extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.calendar.days', 'e10_calendar_days', 'Kalendář');
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
		$dt = $this->app()->cfgItem('e10.calendar.daysTypes.'.$recData['dayType']);
		return $dt['icon'];
	}
}


/**
 * Class ViewDays
 * @package e10\calendar
 */
class ViewDays extends TableView
{
	var $daysTypes;
	var $countries;

	public function init ()
	{
		parent::init();

		$this->daysTypes = $this->app()->cfgItem('e10.calendar.daysTypes');
		$this->countries = $this->app()->cfgItem('world.data.countries');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['i1'] = ['text' => $this->table->dateText($item), 'class' => 'id'];

		$dt = $this->daysTypes[$item['dayType']];
		$country = $this->countries[$item['country']];
		$props = [];

		$props [] = ['text' => $dt['fn'], 'class' => ''];
		$listItem ['i2'] = $props;

		if ($item['country'])
			$listItem ['t2'] = ['text' => $country['f'].' '.$country['t'], 'class' => 'label label-info'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [days].* FROM [e10_calendar_days] AS [days]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [days].[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[days].', ['[yearFrom]', '[month]', '[day]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailDay
 * @package e10\calendar
 */
class ViewDetailDay extends TableViewDetail
{
}


/**
 * Class FormDay
 * @package e10\calendar
 */
class FormDay extends TableForm
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
					$this->addColumnInput ('dayType', TableForm::coColW4);
				$this->closeRow();
			$this->layoutClose();
			$this->addColumnInput ('title');
			$this->addColumnInput ('country');
		$this->closeForm ();
	}
}


