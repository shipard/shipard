<?php

namespace wkf\bboard\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class ViewerMsgsAll
 */
class ViewerMsgsAll extends TableView
{
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;

	var $bboardNdx = 0;

	/** @var  \wkf\bboard\TableBBoards */
	var $tableBBoards;
	var $usersBBoards = NULL;

	var $linkedPersons = [];
	var $classification = [];

	public function init ()
	{
    $this->setPaneMode();

    $this->tableBBoards = $this->app->table ('wkf.bboard.bboards');
		$this->usersBBoards = $this->tableBBoards->usersBBoards();
    $this->addAddParam ('bboard', key($this->usersBBoards));
    //$this->classes = ['e10-viewer-type-main'];

		$this->linesWidth = 45;
		$this->type = 'form';
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
    $this->enableFullTextSearch = TRUE;

    //$this->toolbarTitle = ['text' => 'Nástěnka', 'class' => 'h2'];

		$this->bboardNdx = intval($this->queryParam('bboard'));
		if ($this->bboardNdx)
		{
			$this->addAddParam ('bboard', $this->bboardNdx);
		}

    $this->mainQueries = [];
    $this->mainQueries[] = ['id' => 'active', 'title' => 'Aktivní', 'icon' => 'system/filterActive'];
    $this->mainQueries[] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
    if ($this->app()->hasRole('root'))
      $this->mainQueries[] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
    $this->mainQueries[] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];


		parent::init();

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
	}

  function renderPane (&$item)
	{
    $this->textRenderer->render ($item ['text']);

    $item ['pane'] = ['class' => '__padd5 e10-pane e10-pane-row', 'title' => [], 'body' => []];
    $item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];
		$title = [];

		if ($item['pinned'])
			$title[] = ['class' => 'id pull-right e10-success', 'text' => '', 'icon' => 'system/iconPinned'];

    $title[] = ['class' => 'df2-list-item-t1 h1 block', 'text' => $item['title'], 'icon' => /*$this->table->tableIcon($item)*/'system/iconFile'];

    if (!Utils::dateIsBlank($item['publishFrom']))
      $title[] = ['text' => utils::datef ($item['publishFrom'], '%D, %T'), '_icon' => 'system/iconUser', 'class' => 'label label-default'];

    if ($item['author'])
      $title[] = ['text' => $item['authorName'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];

		$titleClass = '';
		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
      'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.bboard.msgs'
		];

		$item ['pane']['body'][] = [
			'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'padd5 bt1 e10-fs1r',
			'text' => $this->textRenderer->code,
		];
  }

	public function selectRows ()
	{
    $userNdx = $this->app()->userNdx();
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
    $forceArchive = 0;

		$q [] = 'SELECT msgs.*, ';
    array_push ($q, ' [authors].[fullName] AS authorName');
		array_push ($q, ' FROM [wkf_bboard_msgs] AS [msgs]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [authors] ON [msgs].[author] = [authors].[ndx]');
		array_push ($q, ' WHERE 1');

		if (count($this->usersBBoards))
		{
			array_push ($q, ' AND [msgs].[bboard] IN %in', array_keys($this->usersBBoards));
		}
		else
		{
			array_push ($q, ' AND [msgs].[bboard] = %i', -1);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' msgs.[title] LIKE %s', '%'.$fts.'%',
				' OR msgs.[text] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
      $forceArchive = 1;
		}

		if ($mainQuery === 'active' || $mainQuery === '')
		{
      array_push($q, ' AND (');
      array_push($q, ' (msgs.[docStateMain] = %i', 0, ' AND [msgs].[author] = %i', $userNdx, ')');

      if ($forceArchive)
        array_push($q, ' OR msgs.[docStateMain] IN %in', [2, 5]);
			else
				array_push($q, ' OR msgs.[docStateMain] = %i', 2);

      array_push($q, ')');
		}

		// -- archive
		if ($mainQuery === 'archive')
			array_push ($q, " AND msgs.[docStateMain] = 5");

		// -- trash
		if ($mainQuery === 'trash')
			array_push ($q, " AND msgs.[docStateMain] = 4");

    array_push ($q, ' ORDER BY');
    array_push ($q, ' msgs.[docStateMain]');

    array_push ($q, ' , msgs.[pinned] DESC');
    array_push ($q, ' , msgs.[publishFrom] DESC');

    array_push ($q, $this->sqlLimit());

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

	public function createToolbarAddButton (&$toolbar)
	{
    if (!$this->usersBBoards || !count($this->usersBBoards))
      return;

    if (count($this->usersBBoards) === 1)
    {
      parent::createToolbarAddButton ($toolbar);
      return;
    }

		$addButton = [
			'icon' => 'system/actionAdd', 'action' => '',
			'text' => 'Přidat', 'type' => 'button',
			'class' => 'pull-right-absolute',
			'dropdownMenu' => []
		];

		foreach ($this->usersBBoards as $bboardNdx => $bb)
		{
			$addButton['dropdownMenu'][] = [
				'type' => 'action', 'action' => 'newform', 'text' => $bb['fn'],
				'icon' => ($bb['icon'] === '') ? : $bb['icon'],
				'data-addParams' => '__bboard='.$bboardNdx
			];
		}

    $toolbar[] = $addButton;
  }
}
