<?php

namespace wkf\core;
use \e10\utils, e10\TableViewDetail, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableHeadlines
 * @package wkf\core
 */
class TableHeadlines extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.core.headlines', 'wkf_core_headlines', 'Novinky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * Class ViewHeadlines
 * @package wkf\core
 */
class ViewHeadlines extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;


	public function init ()
	{
		$this->linesWidth = 45;
		$this->type = 'form';
		$this->objectSubType = TableView::vsMain;
		$this->fullWidthToolbar = TRUE;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		parent::init();

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$c = '';
		$c .= "<div class='pageText padd5' style='border: 1px solid gray; margin: .5ex;'>";
		$c .= '<h3>'.utils::es($item['title']).'</h3>';

		$this->textRenderer->render ($item ['text']);
		$c .= $this->textRenderer->code;

		$c .= '</div>';

		$listItem ['code'] = $c;

		return $listItem;
	}



	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT headlines.*';
		array_push ($q, ' FROM [wkf_core_headlines] AS [headlines]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' headlines.[title] LIKE %s', '%'.$fts.'%',
				' OR headlines.[text] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[headlines].', ['[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormHeadline
 * @package wkf\core
 */
class FormHeadline extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Novinka', 'icon' => 'icon-filter'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('text');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('dateFrom');
					$this->addColumnInput ('dateTo');
					$this->addColumnInput ('onTop');
					//$this->addColumnInput ('order');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('image');
					$this->addColumnInput ('useImageAs');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('linkToUrl');
				$this->closeTab();

				$this->openTab();
					$this->addColumnInput ('order');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailHeadline
 * @package wkf\core
 */
class ViewDetailHeadline extends TableViewDetail
{
	public function createDetailContent ()
	{

	}
}
