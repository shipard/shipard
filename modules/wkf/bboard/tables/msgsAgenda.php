<?php

namespace wkf\bboard;
use \e10\utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;


/**
 * class TableMsgsAgenda
 */
class TableMsgsAgenda extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.bboard.msgsAgenda', 'wkf_bboard_msgsAgenda', 'Pořad události');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * class ViewMsgsAgenda
 */
class ViewMsgsAgenda extends TableView
{
	var $bboardNdx = 0;
	var $msgNdx = 0;
	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
		$this->linesWidth = 45;
		$this->type = 'form';
		$this->objectSubType = TableView::vsMain;
		$this->fullWidthToolbar = TRUE;
		$this->enableDetailSearch = TRUE;

		$this->msgNdx = intval($this->queryParam('msg'));
		if ($this->msgNdx)
		{
			$this->addAddParam ('msg', $this->msgNdx);
		}

		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['title'];

		$dates = [];
		if ($item['dateBegin'])
			$dates[] = ['text' => utils::datef($item['dateFrom'], '%D'), 'icon' => 'system/actionPlay', 'class' => ''];
		if ($item['dateEnd'])
			$dates[] = ['text' => utils::datef($item['dateTo'], '%D'), 'icon' => 'system/actionStop', 'class' => ''];
		if (count($dates))
			$listItem ['i2'] = $dates;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT agenda.*';
		array_push ($q, ' FROM [wkf_bboard_msgsAgenda] AS [agenda]');
		array_push ($q, ' WHERE 1');

    if ($this->msgNdx)
      array_push ($q, ' AND [agenda].[msg] = %i', $this->msgNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' msgs.[title] LIKE %s', '%'.$fts.'%',
				' OR msgs.[text] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[agenda].', ['[title]', '[ndx]']);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
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
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
	}
}


/**
 * class FormMsgAgenda
 */
class FormMsgAgenda extends TableForm
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
			$this->addList ('doclinksPersons', '', self::loAddToFormLayout);
			$this->openTabs ($tabs);
				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
				$this->closeTab();

				$this->openTab();
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->addSeparator(self::coH3);
				$this->addColumnInput ('dateBegin');
				$this->addColumnInput ('dateEnd');
				//$this->addColumnInput ('order');
				$this->addSeparator(self::coH3);
				$this->addColumnInput ('order');
					$this->addColumnInput ('msg');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailMsgAgenda
 */
class ViewDetailMsgAgenda extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('wkf.bboard.libs.dc.MsgCore');
	}
}
