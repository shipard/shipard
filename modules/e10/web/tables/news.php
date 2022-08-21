<?php

namespace e10\web;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableNews
 * @package E10\Web
 */
class TableNews extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.news', 'e10_web_news', 'Novinky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$props = [];

		$fromTo = '';
		if ($recData['date_from'])
			$fromTo = "od " . Utils::datef ($recData['date_from']);
		if ($recData['date_to'])
			$fromTo .= " do " . Utils::datef ($recData['date_to']);
		if ($fromTo !== '')
			$props[] = ['text' => $fromTo, 'icon' => 'system/iconCalendar'];

		if ($recData['order'] != 0)
			$props[] = ['text' => Utils::nf ($recData['order']), 'icon' => 'system/iconOrder', 'class' => 'pull-right'];
		if ($recData['to_paper_docs'])
			$props[] = ['text' => '', 'icon' => 'system/actionPrint', 'class' => 'pull-right'];
		if ($recData['to_top'])
			$props[] = ['text' => '', 'icon' => 'system/iconPinned', 'class' => 'pull-right'];

		$hdr ['info'][] = ['class' => 'info', 'value' => $props];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * class ViewNews
 */
class ViewNews extends TableView
{
	public function init ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní', 'side' => 'left'];
		$mq [] = ['id' => 'past', 'title' => 'Proběhlé', 'side' => 'left'];

		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['title'];

		$t2 = '';
		if ($item['date_from'])
			$t2 = "od " . Utils::datef ($item['date_from']);
		if ($item['date_to'])
			$t2 .= " do " . Utils::datef ($item['date_to']);
		$listItem ['t2'] = $t2;

		$props = [];
		if ($item['to_top'])
			$props[] = ['text' => '', 'icon' => 'system/iconPinned'];
		if ($item['to_paper_docs'])
			$props[] = ['text' => '', 'icon' => 'system/actionPrint'];
		if ($item['order'] != 0)
			$props[] = ['text' => Utils::nf ($item['order']), 'icon' => 'system/iconOrder'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q[] = 'SELECT * FROM [e10_web_news] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([title] LIKE %s OR [text] LIKE %s OR [perex] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q,
				'AND (',
					'([date_from] IS NULL OR [date_from] <= DATE(NOW()) ) AND ([date_to] IS NULL OR [date_to] >= DATE(NOW()) )',
				')',
				' AND [docStateMain] < 4');

		// -- past
		if ($mainQuery === 'past')
			array_push ($q, 'AND [date_to] < DATE(NOW())', ' AND [docStateMain] < 4');

		// -- archive
		if ($mainQuery === 'archive')
			array_push ($q, ' AND [docStateMain] = 5');

		// -- trash
		if ($mainQuery === 'trash')
			array_push ($q, ' AND [docStateMain] = 4');

		array_push($q, ' ORDER BY [to_top] DESC, [order], [ndx] DESC');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailNews
 */
class ViewDetailNews extends TableViewDetail
{
	public function createDetailContent ()
	{
		$page = $this->item;
		//renderPage ($this->app (), $page, FALSE);

		// -- main text
		if ($this->item['text'] !== '')
		{
			$mt = ['info' => [], 'class' => 'e10-pane pageText'];
			$mt['info'][] = ['value' => [['code' => $page['html']]]];

			$this->addContent([
				'type' => 'tiles', 'tiles' => [$mt], 'class' => 'panes',
				'paneTitle' => ['text' => 'Hlavní text', 'icon' => 'x-content', 'class' => 'h1']
			]);
		}

		// -- perex
		$perex = ['info' => [], 'class' => 'e10-pane pageText'];
		if ($this->item['perexIllustration'])
		{
			$att = $this->app()->loadItem ($this->item['perexIllustration'], 'e10.base.attachments');
			$perex['image'] = \E10\Base\getAttachmentUrl ($this->app(), $att, 192, 320);
		}
		$perex['info'][] = ['value' => [['code' => $page['htmlPerex']]]];

		$this->addContent([
			'type' => 'tiles', 'tiles' => [$perex], 'class' => 'panes',
			'paneTitle' => ['text' => 'Upoutávka', 'icon' => 'x-bubble', 'class' => 'h1']
		]);

		$this->addContentAttachments($this->item['ndx']);
	}
}


/**
 * class FormNews
 */
class FormNews extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);

			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('title');
				$this->addColumnInput ('date_from');
				$this->addColumnInput ('date_to');
			$this->layoutClose ();

			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('to_top');
				$this->addColumnInput ('to_paper_docs');
				$this->addColumnInput ('order');
			$this->layoutClose ();

		$this->layoutClose ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Upoutávka', 'icon' => 'formBillboard'];
		$tabs ['tabs'][] = ['text' => 'Tisknout', 'icon' => 'formPrint'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs);
			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ("text", NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('perex');
				$this->addColumnInput ('perexIllustration');
				$this->addColumnInput ('url');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ("text_paper_doc", NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}
}

