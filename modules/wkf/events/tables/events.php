<?php

namespace wkf\events;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;


/**
 * class TableEvenets
 */
class TableEvents extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.events.events', 'wkf_events_events', 'Události');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) || $recData ['author'] == 0)
			$recData ['author'] = $this->app()->userNdx();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (Utils::dateIsBlank($recData['dateBegin']))
			$recData['dateBegin'] = Utils::today();
		if (!isset($recData['timeBegin']) || $recData['timeBegin'] === '')
			$recData['timeBegin'] = '00:00';
		if (!isset($recData['timeEnd']) || $recData['timeEnd'] === '')
			$recData['timeEnd'] = '00:00';

		$dateTimeBegin = Utils::createDateTimeFromTime ($recData['dateBegin'], $recData['timeBegin']);
		$recData['dateTimeBegin'] = $dateTimeBegin;

		if ($recData['multiDays'])
		{
			if (!isset($recData['dateEnd']))
				$recData['dateEnd'] = Utils::createDateTime($recData['dateBegin']);
			$dateTimeEnd = Utils::createDateTimeFromTime ($recData['dateEnd'], $recData['timeEnd']);
			$recData['dateTimeEnd'] = $dateTimeEnd;
		}
		else
			$recData['dateEnd'] = Utils::createDateTime($recData['dateBegin']);
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'calendar')
		{
			/** @var \wkf\events\TableCals */
			$tableCals = $this->app()->table('wkf.events.cals');
			$uc = $tableCals->usersCals();
			if (isset($uc[$cfgKey]) || $form->readOnly)
				return TRUE;
			return FALSE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function checkAccessToDocument ($recData)
	{
		/** @var \wkf\events\TableCals */
		$tableCals = $this->app()->table('wkf.events.cals');
		$uc = $tableCals->usersCals();

		if (!isset($recData['ndx']) || !$recData['ndx'])
			return 2;

		if (isset($uc[$recData['calendar']]))
			return $uc[$recData['calendar']];

		return 1;
	}
}


/**
 * class ViewEvents
 */
class ViewEvents extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $calendarNdx = 0;
	var $linkedPersons = [];
	var $classification = [];
	var $calendars;

	public function init ()
	{
		$this->calendars =  $this->app()->cfgItem('wkf.events.cals', NULL);
		$this->linesWidth = 45;

		$this->calendarNdx = intval($this->queryParam('calendar'));
		if ($this->calendarNdx)
		{
			$this->addAddParam ('calendar', $this->calendarNdx);
		}

		$this->setMainQueries ();

		parent::init();

		//$this->textRenderer = new \lib\core\texts\Renderer($this->app());
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['title'];

		$dates = [];

		if ($item['multiDays'])
		{
			$dates[] = ['text' => Utils::datef($item['dateBegin']), 'suffix' => $item['timeEnd'], 'class' => ''];
			$dates[] = ['text' => Utils::datef($item['dateEnd']), 'suffix' => $item['timeEnd'], 'class' => ''];
		}
		else
		{
			$dates[] = ['text' => Utils::datef($item['dateBegin']), 'suffix' => $item['timeBegin'].' - '.$item['timeEnd'], 'class' => ''];
		}

		$listItem['t2'] = $dates;
		$listItem['i2'] = [];
		$listItem['i2'][] = ['text' => $item['placeDesc'], 'class' => ''];

		$cal = $this->calendars[$item['calendar']];
		$calCss = '';
		if (isset($cal['colorbg']))
			$calCss .= 'background-color: '.$cal['colorbg'].';';
		$calLabel = ['text' => $cal['sn'], 'class' => 'label', 'css' => $calCss];

		$listItem['i2'][] = $calLabel;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT events.*';
		array_push ($q, ' FROM [wkf_events_events] AS [events]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' events.[title] LIKE %s', '%'.$fts.'%',
				' OR events.[text] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[events].', ['[dateBegin] DESC', '[timeBegin] DESC', '[ndx]']);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
		/*
		$ndx = $item ['pk'];
		if (isset ($this->linkedPersons [$ndx]))
		{
			$item ['t2'] ??= [];
			$item ['t2'] = $this->linkedPersons [$ndx];
		}

		if (isset ($this->classification [$ndx]))
		{
			$item ['t2'] ??= [];
			forEach ($this->classification [$ndx] as $clsfGroup)
				$item ['t2'] = array_merge($item ['t2'], $clsfGroup);
		}
		*/
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		//$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
		//$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
	}
}


/**
 * class FormEvent
 */
class FormEvent extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('title');
			$this->addColumnInput ('calendar');
			$this->openRow();
				$this->addColumnInput ('dateBegin');
				$this->addColumnInput ('timeBegin');

				if ($this->recData['multiDays'])
					$this->addColumnInput ('dateEnd');
				$this->addColumnInput ('timeEnd');

				$this->addColumnInput ('multiDays');
			$this->closeRow();

			$this->addColumnInput ('placeDesc');

			$this->openTabs ($tabs);
				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab();
					//$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					//$this->addSeparator(self::coH4);
					if (!$this->app()->ngg)
						$this->addColumnInput ('author');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailEvent
 */
class ViewDetailEvent extends TableViewDetail
{
	function createDetailContent ()
	{
		$calendar = $this->app()->cfgItem('wkf.events.cals.'.$this->item['calendar'], NULL);
		if (!$calendar || !($calendar['useProgram'] ?? 0))
			return;
		$this->addContent ([
			'type' => 'viewer', 'table' => 'wkf.events.eventsProgram', 'viewer' => 'default',
			'params' => ['eventNdx' => $this->item ['ndx']]
		]);
	}
}
