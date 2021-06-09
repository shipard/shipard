<?php

namespace e10pro\hosting\client\libs;

use \e10\TableView, \e10\utils, E10\TableViewPanel;


/**
 * Class DataSourcesDashboardViewer
 * @package e10pro\hosting\client\libs
 */
class DataSourcesDashboardViewer2 extends TableView
{
	var $thisUserId = 0;

	var $topDataSources = [];
	var $paneClass= '';

	public function init ()
	{
		$this->rowsPageSize = 100;
		$this->linesWidth = 30;

		if (!$this->thisUserId)
			$this->thisUserId = intval($this->table->app()->user()->data ('id'));

		$this->setPanels (TableView::sptReview);

		parent::init();

		if ($this->viewerDefinition === NULL)
		{
			$this->viewerDefinition['title'] = '';
		}
	}

	public function selectRows ()
	{
		$q = [];

		array_push($q, '(');
		$this->qrySelectRows($q, NULL, 0);
		array_push($q, ')');
		array_push ($q, 'UNION');

		array_push($q, '(');
		$this->qrySelectRows($q, NULL, 1);
		array_push($q, ')');

		array_push($q, ' ORDER BY selectPart, dsOrder, [name]');

		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT '.$selectPartNumber.' AS selectPart,';

		if ($selectPartNumber === 0)
			array_push($q, ' udsOptions.[dsOrder] AS dsOrder,');
		else
			array_push($q, ' 0 AS dsOrder,');

		array_push($q, ' servers.id AS serverId,');
		array_push($q, ' ds.dsId1, ds.name, ds.shortName, ds.imageUrl, ds.ndx AS dsNdx, ds.gidstr AS dsGidStr,');
		array_push($q, ' udsOptions.ndx AS udsOptionsNdx, udsOptions.favorite AS favorite');
		array_push($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_datasources] AS ds ON usersds.datasource = ds.ndx');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_servers] AS [servers] ON ds.server = servers.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_udsOptions] AS [udsOptions] ON usersds.udsOptions = udsOptions.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND usersds.[user] = %i', $this->thisUserId);
		array_push($q, ' AND usersds.[docStateMain] = 2');
		array_push($q, ' AND ds.[docStateMain] = 2');

		// -- fulltext
		if ($fts != '')
		{
			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;

			array_push ($q, ' AND (');
			array_push ($q, ' ds.[name] LIKE %s OR ds.[gidstr] LIKE %s', '%'.$fts.'%', $fts.'%');
			if ($ascii)
			{
				array_push($q, ' OR ds.dsId1 LIKE %s', '%' . $fts . '%');
				array_push($q, ' OR ds.dsId2 LIKE %s', '%' . $fts . '%');
			}
			array_push ($q, ')');
		}

		if ($selectPartNumber === 0)
		{ // favorites
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10pro_hosting_server_udsOptions ',
				'WHERE usersds.ndx = e10pro_hosting_server_udsOptions.uds',
				' AND favorite = %i)', 1
			);
		}
		else
		{ // normal
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10pro_hosting_server_udsOptions ',
				'WHERE usersds.ndx = e10pro_hosting_server_udsOptions.uds',
				' AND favorite = %i)', 1
			);

		}

		//array_push($q, ' ORDER BY ds.[name], ds.[ndx]');
	}

	function renderRow ($item)
	{
		$ndx = $item ['dsNdx'];

		$listItem ['pk'] = $item ['dsNdx'];
		$listItem['content'] = [];

		$listItem ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass.' df2-action-trigger-no-shift '];

		$optionsIcon = $item['favorite'] ? 'system/iconStar' : 'system/iconStar';
		$optionsClass = $item['favorite'] ? 'e10-success' : 'e10-off';

		$title = [];
		$title[] = [
			'text' => '', 'icon' => $optionsIcon, 'class' => $optionsClass.' h2', 'css' => 'color: #777; text-shadow: none;' ,
			'docAction' => 'edit', 'pk' => $item['udsOptionsNdx'], 'table' => 'e10pro.hosting.server.udsOptions',
			'element' => 'span', 'actionClass' => '', 'type' => 'span',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->queryParam('widgetId'),
		];

		$dsTitle = ['class' => 'h2', 'text' => (($item['shortName'] !== '') ? $item['shortName'] : $item['name'])];
		$title[] = $dsTitle;

		$ntfBadge = "<sup class='e10-ntf-badge' id='ntf-badge-unread-ds-".utils::es($item['dsGidStr'])."' style='display: none;'></sup>";
		$title[] = ['code' => $ntfBadge];

		$ntfBadge = " <sup class='e10-ntf-badge e10-ntf-badge-todo' id='ntf-badge-todo-ds-".utils::es($item['dsGidStr'])."' style='display: none;'></sup>";
		$title[] = ['code' => $ntfBadge];

		$listItem ['pane']['title'][] = [
			'value' => $title
		];

		$css = '';
		//$css .= "background-color: #00508ac0; min-height: 3.4em; color: white; text-shadow: 2px 2px 3px rgba(0,0,0,.4);";
		$css .= "user-select: none; padding: 1ex; min-height: 3.4em;";
		if ($item['imageUrl'] !== '')
		{
			$css .= " background-image:url('{$item['imageUrl']}'); background-size: auto 80%; background-position: right center; background-repeat: no-repeat;";
		}

		$listItem ['pane']['css'] = $css;
		$listItem['pane']['data'] =
			[
				'action' => 'open-link', 'popup-id' => 'NEW-TAB',
				'url-download' => $this->dsUrl($item),
			];

		return $listItem;
	}

	function dsUrl ($ds)
	{
		if ($ds['dsId1'] === '')
		{
			return 'https://'.$ds['serverId'].'.shipard.app/'.$ds['dsGidStr'].'/';
		}

		return 'https://'.$ds['dsId1'].'.shipard.app';
	}

	public function endMark ($blank)
	{
		return ['code' => ''];
	}

	public function createPanelContentReview (TableViewPanel $panel)
	{
		$panel->setContent('e10pro.hosting.client.libs.UsersDataSourcesReview');
	}
}
