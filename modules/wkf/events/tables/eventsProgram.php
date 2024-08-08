<?php

namespace wkf\events;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;


/**
 * class TableEventsProgram
 */
class TableEventsProgram extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.events.eventsProgram', 'wkf_events_eventsProgram', 'Program událostí');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * class ViewEventsProgram
 */
class ViewEventsProgram extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $calendarNdx = 0;
	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
    /*
		$this->calendarNdx = intval($this->queryParam('calendar'));
		if ($this->calendarNdx)
		{
			$this->addAddParam ('calendar', $this->calendarNdx);
		}
    */

		if ($this->queryParam ('eventNdx'))
			$this->addAddParam ('event', $this->queryParam ('eventNdx'));


    $this->objectSubType = TableView::vsDetail;
    $this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		parent::init();

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $listItem ['t1'] = "Program akce";
    if ($item ['order'])
      $listItem ['i1'] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'id'];

    $c = '';

    $this->textRenderer->render ($item ['program']);
		$c .= $this->textRenderer->code;

    if ($item ['peoples'] && $item ['peoples'] !== '')
    {
      $c .= "<h4>Účinkující</h4>\n";
      $c .= $item ['peoples'];
      //$this->textRenderer->render ($item ['peoples']);
      //$c .= $this->textRenderer->code;
    }

    if ($item ['none'] !== '')
    {
      $c .= "<h4>Poznámky</h4>\n";
      $c .= $item ['note'];
      //$this->textRenderer->render ($item ['note']);
      //$c .= $this->textRenderer->code;
    }

    $listItem ['txt'] = $c;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT eventsProgram.*');
		array_push ($q, ' FROM [wkf_events_eventsProgram] AS [eventsProgram]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [event] = %i', $this->queryParam ('eventNdx'));

		// -- fulltext

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' eventsProgram.[note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR eventsProgram.[peoples] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR eventsProgram.[program] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[eventsProgram].', ['[order]', '[ndx]']);
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
 * class FormEventProgram
 */
class FormEventProgram extends TableForm
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
			$this->openTabs ($tabs);
				$this->openTab ();
          $this->addColumnInput('program');
          $this->addColumnInput('peoples');
          $this->addColumnInput('note');
          $this->addSeparator(self::coH4);
          $this->addColumnInput('order');
				$this->closeTab();
				$this->openTab();
					//$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					//$this->addSeparator(self::coH4);
					$this->addColumnInput ('event');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailEventProgram
 */
class ViewDetailEventProgram extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('wkf.bboard.libs.dc.MsgCore');
	}
}
