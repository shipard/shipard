<?php

namespace wkf\msgs;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;


/**
 * class TableMsgs
 */
class TableMsgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.msgs.msgs', 'wkf_msgs_msgs', 'Zprávy');
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
	}

  public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if ($recData['docState'] === 4000)
		{
			$this->createRecipients($recData);
		}
	}

	function createRecipients ($recData)
	{
		$tableBulkPosts = $this->app()->table ('wkf.msgs.msgsRecipients');

		// -- delete old
		$this->db()->query ('DELETE FROM [wkf_msgs_msgsRecipients] WHERE [sent] = 0 AND [msg] = %i', $recData['ndx']);

		// -- add new
		$q[] = 'SELECT * FROM [wkf_msgs_msgsVGR]';
		array_push ($q, ' WHERE [msg] = %i', $recData['ndx']);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$r['virtualGroup'], NULL);
			if (!$virtualGroup)
				continue;

			$vgObject = $this->app()->createObject($virtualGroup['classId']);
			if (!$vgObject)
				continue;

			$vgObject->addPosts($tableBulkPosts, 'msg', $recData['ndx'], $r);

			unset ($vgObject);
		}

		$dateReadyToSend = new \DateTime('+ 15 minutes');
		//$update = ['dateReadyToSend' => $dateReadyToSend, 'sendingState' => 2];

		//$this->db()->query ('UPDATE [e10pro_wkf_bulkEmails] SET ', $update, ' WHERE [ndx] = %i', $recData['ndx']);
		//$recData['sendingState'] = 2;
		//$recData['dateReadyToSend'] = $dateReadyToSend;
	}

}


/**
 * class ViewMsgs
 */
class ViewMsgs extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $bboardNdx = 0;
	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
		$this->linesWidth = 45;
		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;

		$this->bboardNdx = intval($this->queryParam('bboard'));
		if ($this->bboardNdx)
		{
			$this->addAddParam ('bboard', $this->bboardNdx);
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
		if ($item['onTop'])
			$dates[] = ['text' => '', 'icon' => 'system/iconPinned', 'class' => ''];
		if ($item['dateFrom'])
			$dates[] = ['text' => Utils::datef($item['dateFrom'], '%D'), 'icon' => 'system/actionPlay', 'class' => ''];
		if ($item['dateTo'])
			$dates[] = ['text' => Utils::datef($item['dateTo'], '%D'), 'icon' => 'system/actionStop', 'class' => ''];
		if (count($dates))
			$listItem ['i2'] = $dates;

		$c = '';
		$c .= "<div class='pageText padd5' style='border: 1px solid gray; margin: .5ex;'>";
		$c .= '<h3>'.Utils::es($item['title']).'</h3>';

		//$this->textRenderer->render ($item ['text']);
		//$c .= $this->textRenderer->code;

		$c .= '</div>';

		//$listItem ['code'] = $c;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT msgs.*';
		array_push ($q, ' FROM [wkf_msgs_msgs] AS [msgs]');
		array_push ($q, ' WHERE 1');

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

		$this->queryMain ($q, '[msgs].', ['[title]', '[ndx]']);
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
 * class FormMsg
 */
class FormMsg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Práva', 'icon' => 'formText'];
    $tabs ['tabs'][] = ['text' => 'Příjemci', 'icon' => 'formRecipients'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('title');
			//if ($usePersonsNotify)
				$this->addList ('doclinksPersons', '', self::loAddToFormLayout);
			$this->openTabs ($tabs);
				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab ();
					$this->addList ('vgrs');
				$this->closeTab ();
				$this->openTab();
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
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
 * Class ViewDetailMsg
 */
class ViewDetailMsg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('wkf.msgs.libs.dc.MsgCore');
	}
}


class ViewDetailMsgRecipients extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent (
			[
				'type' => 'viewer', 'table' => 'wkf.msgs.msgsRecipients', 'viewer' => 'wkf.msgs.ViewMsgsRecipients',
				'params' => ['msgNdx' => $this->item ['ndx']]
			]);
	}
}
