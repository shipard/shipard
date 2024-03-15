<?php

namespace wkf\core\viewers;


use \e10\TableView, \e10\utils, \e10\uiutils, \e10\json;
use \e10\base\libs\UtilsBase;


/**
 * Class CommentsSidebar
 * @package wkf\core\viewers
 */
class CommentsSidebar extends TableView
{
	var $localPks = [];
	protected $linkedPersons;
	protected $properties;
	protected $classification;
	protected $atts;
	var $thisUserId = 0;
	var $notifyPks = [];

	var $ownerIssue = 0;
	var $issueAccessLevel = 0;

	var $textRenderer;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableToolbar = TRUE;
		$this->setPaneMode();

		parent::init();

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
		$this->thisUserId = $this->app()->userNdx();

		$this->ownerIssue = intval($this->queryParam ('issue'));
		$this->issueAccessLevel = intval($this->queryParam ('issueAccessLevel'));

//		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['shipardEmailId'] !== '')
			$props [] = ['icon' => 'icon-at', 'text' => $item ['shipardEmailId'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT comments.*, authors.fullName AS authorFullName ';
		array_push ($q, ' FROM [wkf_core_comments] AS comments');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS authors ON comments.author = authors.ndx');
		array_push ($q, ' WHERE 1');


		if ($this->ownerIssue)
			array_push ($q, ' AND comments.[issue] = %i', $this->ownerIssue);

		array_push ($q, ' AND (comments.[docStateMain] = %i', 2, ' OR [author] = %i', $this->thisUserId, ')');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' comments.[text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		//$this->queryMain ($q, 'comments.', ['[ndx]']);
		array_push ($q, ' ORDER BY [docStateMain], [ndx]');
		array_push ($q, $this->sqlLimit ());
		$this->runQuery ($q);
	}

	function renderPane (&$item)
	{
		$table = $this->table;

		$paneClass = 'e10-pane-vitem';

		$item['pk'] = $item['ndx'];
		$title = [];

		$icon = $table->tableIcon ($item, 1);

		if ($item['docState'] !== 4000)
			$paneClass .= ' e10-ds '.$item ['docStateClass'];

		$title[] = ['class' => 'e10-bold', 'text' => $item ['authorFullName'], 'icon' => $icon];
		$title[] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => 'icon-keyboard-o', 'class' => 'e10-off'];
		$title[] = ['class' => 'id pull-right', 'text' => utils::nf($item['ndx']), 'icon' => 'system/iconHashtag'];

		if ($item['author'] === $this->thisUserId || $this->issueAccessLevel === 2)
		{
			$title [] = [
				'class' => 'label label-default pull-right', 'icon' => 'system/docStateEdit',
				'text' => '', 'title' => 'Opravit', 'type' => 'span',
				'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.core.comments',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
			];
		}

		// -- add comment button
		$title [] = [
			'class' => 'label label-default', 'icon' => 'system/issueComment',
			'text' => 'Odpovědět', 'title' => 'Odpovědět', 'element' => 'span', 'btnClass' => 'test',
			'action' => 'new', 'data-table' => 'wkf.core.comments',
			'data-addParams' => '__issue='.$item['issue'].'&__inReplyToComment='.$item['ndx'],
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
		];

		if (in_array($item['ndx'], $this->notifyPks))
			$paneClass .= ' e10-block-notification';

		$item ['pane'] = ['class' => $paneClass];
		$item ['pane']['title'][] = ['value' => $title];

		// -- text
		if ($item['commentType'] == 0)
		{
			$this->textRenderer->render($item ['text']);
			$item ['pane']['body'][] = ['class' => 'pageText', 'code' => $this->textRenderer->code];
		}
		else
		{
			$alertData = json_decode($item ['text'], TRUE);

			if (isset($alertData['title']))
				$item ['pane']['body'][] = ['class' => 'pageText', 'content' => [['pane' => 'e10-pane-core e10-pane-top pa1 h2 '.$alertData['title']['class'], 'type' => 'line', 'line' => $alertData['title']]]];

			if (isset($alertData['content']))
				$item ['pane']['body'][] = ['class' => 'pageText', 'content' => $alertData['content']];

			if (isset($alertData['payload']))
			{
				$pl = json::lint($alertData['payload']);
				$pc = [
					'type' => 'text', 'subtype' => 'code', 'text' => $pl,
					'detailsTitle' => [['text' => 'Data', 'class' => ''], ['text' => utils::memf(strlen($pl)), 'class' => 'pull-right e10-small']],
					'details' => 'e10-pane e10-pane-table'
				];
				$item ['pane']['body'][] = ['class' => 'pageText', 'content' => [$pc]];
			}
		}

		// -- attachments
		if (isset($this->atts[$item ['ndx']]))
		{
			$item ['pane']['body'][] = ['class' => 'attBoxSmall', 'attachments' => $this->atts[$item ['ndx']], 'fullSizeTreshold' => 2];
		}
	}

	public function createToolbar ()
	{
		return [];
	} // createToolbar

	public function createTopMenuSearchCode ()
	{
		$issueParam = $this->queryParam ('issue');
		$disableAddButtonsParam = intval($this->queryParam ('disableAddButtons'));

		// -- add buttons
		$enableButtons = 1;
		$title = [];

		if ($disableAddButtonsParam)
			$enableButtons = 0;

		if ($enableButtons)
		{
			$addParams = '__issue='.$issueParam;

			$icon = 'icon-comment';
			$txtTitle = 'Nový prázdný komentář';
			$txtText = 'Nový komentář';
			$addButton = [
				'action' => 'new', 'data-table' => 'wkf.core.comments', 'icon' => $icon,
				'text' => $txtText, 'title' => $txtTitle, 'type' => 'button', 'actionClass' => 'btn btn-sm',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
				'data-addParams' => $addParams,
			];

			$addButton['data-srcobjecttype'] = 'viewer';
			$addButton['data-srcobjectid'] = $this->vid;

			$title[] = $addButton;

			// -- add reply-to button
			$addParams .= '&__inReplyToIssue='.$issueParam;
			$icon = 'icon-commenting';
			$txtTitle = 'Nový komentář - zahrnout text zprávy';
			$txtText = 'Citovat zprávu';
			$addButton = [
				'action' => 'new', 'data-table' => 'wkf.core.comments', 'icon' => $icon,
				'text' => $txtText, 'title' => $txtTitle, 'type' => 'button', 'actionClass' => 'btn btn-sm',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
				'data-addParams' => $addParams,
			];

			$addButton['data-srcobjecttype'] = 'viewer';
			$addButton['data-srcobjectid'] = $this->vid;

			$title[] = $addButton;
		}

		$c = '';
		$c .= "<div class='e10-sv-search e10-sv-search-toolbar' style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		foreach ($title as $btn)
			$c .= $this->app()->ui()->actionCode($btn).'&nbsp;';
		$c .= '</div>';

		return $c;
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->loadNotifications();
		$this->atts = UtilsBase::loadAttachments($this->app(), $this->pks, 'wkf.core.comments');
	}

	protected function loadNotifications ()
	{
		$q[] = 'SELECT * FROM e10_base_notifications WHERE state = 0  ';
		array_push ($q, 'AND personDest = %i', $this->thisUserId);
		array_push ($q, 'AND tableId = %s', 'wkf.core.issues');
		array_push ($q, 'AND recIdMain = %i', $this->ownerIssue);
		array_push ($q, 'AND recId IN %in', $this->pks);
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
			$this->notifyPks[] = $r['recId'];
	}
}
