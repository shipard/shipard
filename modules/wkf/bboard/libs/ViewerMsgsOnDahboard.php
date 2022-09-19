<?php

namespace wkf\bboard\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class ViewerMsgsOnDahboard
 */
class ViewerMsgsOnDahboard extends TableView
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
		$this->objectSubType = TableView::vsDetail;
		//$this->fullWidthToolbar = TRUE;
		$this->enableDetailSearch = TRUE;
    $this->enableFullTextSearch = TRUE;

    //$this->toolbarTitle = ['text' => 'Nástěnka', 'class' => 'h2'];

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
		if ($item['pinned'])
			$dates[] = ['text' => '', 'icon' => 'system/iconPinned', 'class' => ''];
		if ($item['publishFrom'])
			$dates[] = ['text' => Utils::datef($item['publishFrom'], '%D'), 'icon' => 'system/actionPlay', 'class' => ''];
		if ($item['publishTo'])
			$dates[] = ['text' => Utils::datef($item['publishTo'], '%D'), 'icon' => 'system/actionStop', 'class' => ''];
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
