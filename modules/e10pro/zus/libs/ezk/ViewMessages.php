<?php

namespace e10pro\zus\libs\ezk;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;


class ViewMessages extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->classes = ['viewerWithCards'];
		$this->enableToolbar = FALSE;

//		$this->setMainQueries ();

		parent::init();

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
		$this->uiSubTemplate = 'modules/e10pro/zus/libs/ezk/subtemplates/messageRow';
	}

	public function renderRow ($item)
	{
		$listItem['class'] = 'card';

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		// -----
		$listItem['authorName'] = $item['authorName'];
		$listItem['title'] = $item['title'];

		$this->textRenderer->renderAsArticle ($item ['text'], $this->table, $item['ndx']);
		$listItem ['bodyText'] = $this->textRenderer->code;
		// ----

		return $listItem;
	}

	function renderCard ($item, &$listItem)
	{
		$ndx = $item['ndx'];

    $listItem ['pane'] = ['class' => 'card w-100', 'title' => [], 'body' => []];
    $listItem ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];
		$title = [];

		if (isset ($this->classification [$ndx]))
		{
			forEach ($this->classification [$ndx] as $clsfGroup)
				$title[] = $clsfGroup;
		}

    $title[] = ['class' => 'df2-list-item-t1 h1 block', 'text' => $item['title'], 'icon' => /*$this->table->tableIcon($item)*/'system/iconFile'];

    if ($item['author'])
      $title[] = ['text' => $item['authorName'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];

		if (isset ($this->linkedPersons [$ndx]))
		{
			$title[] = $this->linkedPersons [$ndx];
		}

		$titleClass = 'card-body';
		$listItem ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
      'pk' => $ndx, 'docAction' => 'edit', 'data-table' => 'wkf.bboard.msgs'
		];

		// -- render text
		$this->textRenderer->renderAsArticle ($item ['text'], $this->table, $item['ndx']);
		$listItem ['pane']['body'][] = [
			'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'padd5 bt1 e10-fs1r pageText',
			'text' => $this->textRenderer->code,
		];

		// -- download
		/*
		$files = \E10\Base\loadAttachments ($this->table->app(), [$ndx], $this->table->tableId());
		if (isset($files[$ndx]) && $files[$ndx]['hasDownload'])
		{
			$item ['pane']['body'][] = [
				'type' => 'attachments', 'attachments' => $files[$ndx], 'downloadOnly' => 1,
				'downloadTitle' => 'Soubory ke stažení:', 'downloadClass' => 'mt1 padd5',
			];
		}
		*/
  }

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT msgs.*,');
		array_push ($q, ' [authors].[fullName] AS authorName');
		array_push ($q, ' FROM [wkf_msgs_msgs] AS [msgs]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [authors] ON [msgs].[author] = [authors].[ndx]');
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

