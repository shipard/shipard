<?php

namespace wkf\bboard;
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
		$this->setName ('wkf.bboard.msgs', 'wkf_bboard_msgs', 'Zprávy na nástěnce');
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

		if ($recData['docState'] === 4000)
		{
			if (Utils::dateIsBlank($recData['publishFrom']))
				$recData['publishFrom'] = new \DateTime();
		}

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
		$this->type = 'form';
		$this->objectSubType = TableView::vsMain;
		$this->fullWidthToolbar = TRUE;
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
		array_push ($q, ' FROM [wkf_bboard_msgs] AS [msgs]');
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
 * class FormBBoardMsg
 */
class FormBBoardMsg extends TableForm
{
	public function renderForm ()
	{
		$bboard = $this->app()->cfgItem('wkf.bboard.bboards.'.$this->recData['bboard'], []);
		$usePerex = $bboard['usePerex'] ?? 0;
		$useImage = $bboard['useImage'] ?? 0;
		$useLinkToUrl = $bboard['useLinkToUrl'] ?? 0;
		$usePersonsNotify = $bboard['usePersonsNotify'] ?? 0;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
		if ($usePerex)
			$tabs ['tabs'][] = ['text' => 'Perex', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('title');
			if ($usePersonsNotify)
				$this->addList ('doclinksPersons', '', self::loAddToFormLayout);
			$this->openTabs ($tabs);
				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
				$this->closeTab();
				if ($usePerex)
				{
					$this->openTab (self::ltNone);
						$this->addInputMemo('perex', NULL, TableForm::coFullSizeY);
					$this->closeTab();
				}
				$this->openTab();
					$this->addColumnInput ('pinned', self::coRightCheckbox);
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('publishFrom');
					$this->addColumnInput ('publishTo');
					if ($useImage)
					{
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('image');
						$this->addColumnInput ('useImageAs');
					}
					if ($useLinkToUrl)
					{
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('linkToUrl');
						$this->addSeparator(self::coH4);
					}
					$this->addColumnInput ('order');
					$this->addColumnInput ('bboard');
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
		$this->addDocumentCard('wkf.bboard.libs.dc.MsgCore');
	}
}
