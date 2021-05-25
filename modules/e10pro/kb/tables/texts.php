<?php

namespace e10pro\kb;
use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\FormReport, \E10\DbTable, \E10\utils;


/**
 * Class TableTexts
 * @package e10pro\kb
 */
class TableTexts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.kb.texts', 'e10pro_kb_texts', 'Texty', 1074);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['ndx']) && $recData['ndx'])
		{
			if (isset($recData['section']))
				unset($recData['section']);
			if (isset($recData['ownerText']))
				unset($recData['ownerText']);
		}
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset ($recData['pageType']) || $recData['pageType'] === '')
			$recData['pageType'] = 'kb-user-page';

		if (!isset($recData ['author']) || $recData ['author'] == 0)
			$recData ['author'] = $this->app()->userNdx();

		if (isset ($recData['addInto']))
		{
			$rows = $this->db()->query ("SELECT * from [e10pro_kb_texts] WHERE [treeId] LIKE %s ORDER BY [treeId]", $recData['addInto'].'%');
			$rowOwner = $rows->fetch();
			$rowFirstInside = $rows->fetch();

			$newOrder = 1000;
			if ($rowFirstInside && $rowFirstInside['ownerText'] === $rowOwner['ndx'])
			{
				if ($rowFirstInside['order'] !== 0)
					$newOrder = intval ($rowFirstInside['order'] / 2);
			}
			$recData ['order'] = $newOrder;
			unset ($recData['addInto']);
		}
		else
		if (isset ($recData['addAfter']))
		{
			$rowOwner = $this->loadItem($recData['ownerText']);
			$rows = $this->db()->query ("SELECT * from [e10pro_kb_texts] WHERE [treeId] LIKE %s AND [treeId] >= %s ORDER BY [treeId]",
																	$rowOwner['treeId'].'%', $recData['addAfter']);
			$rowThis = $rows->fetch();
			$rowFirstAfter = $rows->fetch();

			$newOrder = 1000;
			if ($rowThis && $rowThis['treeId'] === $recData['addAfter'])
			{
				if ($rowFirstAfter && $rowFirstAfter['ownerText'] === $rowOwner['ndx'] && $rowFirstAfter['order'] !== 0)
					$newOrder = $rowThis['order'] + intval (($rowFirstAfter['order'] - $rowThis['order']) / 2);
				else
					$newOrder = $rowThis['order'] + 1000;
			}
			$recData ['order'] = $newOrder;
			unset ($recData['addAfter']);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];
		return $hdr;
	}

	public function icon ($recData)
	{
		return 'x-content';
	}

	public function systemPageEngine($recData)
	{
		$systemPageTypeId = (isset($recData['pageType']) && $recData['pageType'] !== '') ? $recData['pageType'] : 'kb-user-page';
		$systemPageTypeCfg = $this->app()->cfgItem('e10pro.kb.wiki.pageTypes.'.$systemPageTypeId, NULL);
		if (!$systemPageTypeCfg)
			return NULL;

		$classId = $systemPageTypeCfg['classId'];
		/** @var \e10pro\kb\libs\SystemPageEngine $o */
		$o = $this->app()->createObject($classId);
		$o->init();
		return $o;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if ($recData['docState'] != 4000)
			return;

		$dstLanguage = $recData['srcLanguage'];

		$spe = $this->systemPageEngine($recData);
		$spe->init();
		$renderedText = $spe->renderPage($recData);

		$exist = $this->db()->query('SELECT ndx FROM [e10pro_kb_textsRendered] WHERE [wikiPage] = %i', $recData['ndx'],
			' AND [dstLanguage] = %i', $dstLanguage)->fetch();

		if ($exist)
		{
			$update = [
				'text' => $renderedText,
				'title' => $recData['title'],
				'subTitle' => $recData['subTitle'],
				'perex' => $recData['perex'],
			];

			$this->db()->query('UPDATE [e10pro_kb_textsRendered] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$newItem = [
				'wikiPage' => $recData['ndx'],
				'text' => $renderedText,
				'title' => $recData['title'],
				'subTitle' => $recData['subTitle'],
				'perex' => $recData['perex'],
				'dstLanguage' => $dstLanguage,
			];

			$this->db()->query('INSERT INTO [e10pro_kb_textsRendered] ', $newItem);
		}
	}
}


/**
 * Class ViewTexts
 * @package e10pro\kb
 */
class ViewTexts extends TableView
{
	/** @var \e10\DbTable */
	var $tableWikies;
	var $enabledWikies = [];
	var $enabledSections = [];

	public function init ()
	{
		parent::init();
		$this->linesWidth = 30;

		// -- wikies
		$this->tableWikies = $this->app()->table('e10pro.kb.wikies');
		$usersWikies = $this->tableWikies->usersWikies ();
		$active = 1;
		foreach ($usersWikies as $w)
		{
			$bt [] = ['id' => $w['ndx'], 'title' => $w['sn'], 'active' => $active, 'addParams' => ['wiki' => $w['ndx']]];
			$this->enabledWikies[] = $w['ndx'];
			$active = 0;
		}
		$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];
		if (count($usersWikies) > 1)
			$this->setBottomTabs ($bt);

		// -- users sections
		$this->enabledSections = $this->tableWikies->userSections();

		// -- top tabs
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		//$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->icon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$listItem ['t2'] = [];
		if ($item['sectionTitle'])
			$listItem['t2'][] = ['text' => $item['sectionTitle'], 'suffix' => $item['wikiName'],'icon' => 'icon-list-alt', 'class' => 'label label-default'];
		else
			$listItem['t2'][] = ['text' => 'chybná sekce', 'icon' => 'icon-list-alt', 'class' => 'label label-warning'];

		$props = [];

		if ($item ['order'] != 0)
			$props [] = ['i' => 'sort', 'text' => utils::nf ($item ['order'], 0)];

		if (count($props))
			$listItem ['i2'] = $props;
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$wiki = intval($this->bottomTabId ());

		$q [] = 'SELECT texts.*, persons.fullName as authorFullName, sections.title as sectionTitle, wikies.fullName as wikiName';
		array_push ($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON texts.author = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_kb_sections AS sections ON texts.section = sections.ndx');
		array_push ($q, ' LEFT JOIN e10pro_kb_wikies AS wikies ON sections.wiki = wikies.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([texts].[title] LIKE %s OR [texts].[id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		// -- wikies
		if ($wiki)
			array_push ($q, ' AND sections.[wiki] = %i', $wiki);
		else
			array_push ($q, ' AND sections.[wiki] IN %in', $this->enabledWikies);

		// -- sections
		array_push ($q, ' AND texts.[section] IN %in', $this->enabledSections);

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, ' AND texts.[docStateMain] < 4');

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND texts.[docStateMain] = 4");

		array_push ($q, ' ORDER BY texts.[order], texts.[title], texts.[ndx] ');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class FormText
 * @package E10Pro\KB
 */
class FormText extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$this->addColumnInput ("title");

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Perex', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Odkazy', 'icon' => 'icon-external-link'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'x-wrench'];

		$this->openTabs ($tabs);
			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ('perex', NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addViewerWidget ('e10pro.kb.annots', 'default', ['docTableNdx' => $this->table->ndx, 'docRecNdx' => $this->recData['ndx']]);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->addColumnInput('order');

				//$this->addColumnInput('project');
				$this->addColumnInput('id');

				$this->addColumnInput('pageType');
				$this->addColumnInput('srcLanguage');
				$this->addColumnInput('subTitle');
				$this->addColumnInput('icon');
			$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailText
 * @package E10Pro\KB
 */
class ViewDetailText extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => $this->item['text']));
	}
}


/**
 * Class ViewDetailTextPreview
 * @package E10Pro\KB
 */
class ViewDetailTextPreview extends TableViewDetail
{
	public function createDetailContent ()
	{
		$sectionRecData = $this->app()->loadItem($this->item['section'], 'e10pro.kb.sections');
		if ($sectionRecData)
		{
			$url = $this->app()->dsRoot . '/app/wiki-' . $sectionRecData['wiki'] . '/' . $this->item['ndx'];
			$this->addContent(['type' => 'url', 'url' => $url, 'fullsize' => 1]);
		}
	}
}


/**
 * Class BookReport
 * @package E10Pro\KB
 */
class BookReport extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.kb.book.pdf';
		$this->reportTemplate = 'e10pro.kb.book.pdf';
	}

	public function loadData ()
	{
		$this->reportId = 'e10pro.kb.pdf';
		$this->reportTemplate = 'e10pro.kb.pdf';

		parent::loadData();

		$engine = new kbTextsEngine($this->app(), kbTextsEngine::pmBigText);
		$engine->init();
		$engine->bookNdx = $this->recData ['ndx'];
		$engine->setText($this->recData ['ndx']);
		$engine->createOneBigText();

		$texy = new \E10\Web\E10Texy($this->app, $this->page);
		$this->recData['bookPerex'] = $texy->process($this->recData['perex']);

		$this->recData['bookTextSource'] = $engine->oneBigText;
		$this->recData['bookContent'] = $engine->oneBigTextTOCHtml;
	}
}

