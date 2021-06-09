<?php

namespace e10pro\hosting\client\libs;

use \e10\TableView, \e10\utils;


/**
 * Class DataSourcesDashboardViewer
 * @package e10pro\hosting\client\libs
 */
class DataSourcesDashboardViewer extends TableView
{
	var $onlineLimit;
	var $thisUserId = 0;

	var $topDataSources = [];
	var $paneClass= '';

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->rowsPageSize = 100;

		$this->onlineLimit = new \DateTime();
		$this->onlineLimit->sub (new \DateInterval('PT30M'));

		$this->htmlRowsElementClass = 'e10-dsb-panes';
		$this->htmlRowElementClass = 'post';
		$this->paneClass = 'e10-pane e10-pane-mini e10-pane-hover';

		$this->setPaneMode(0, 15);

		if (!$this->thisUserId)
			$this->thisUserId = intval($this->table->app()->user()->data ('id'));

		parent::init();
	}

	function loadTopDataSources()
	{
		$maxTopDSCnt = 8;

		// -- favorites
		$topPks = [];
		$favoritesPks = [];
		$qf[] = 'SELECT usersds.datasource';
		array_push($qf, ' FROM [e10pro_hosting_server_udsOptions] AS udsOptions');
		array_push($qf, ' INNER JOIN [e10pro_hosting_server_usersds] AS usersds ON udsOptions.uds = usersds.ndx');
		array_push($qf, ' WHERE 1');
		array_push($qf, ' AND usersds.[user] = %i', $this->thisUserId);
		array_push($qf, ' AND usersds.[docStateMain] = 2');
		array_push($qf, ' AND udsOptions.[favorite] = %i', 1);
		array_push($qf, ' LIMIT 0, %i', $maxTopDSCnt);
		$rows = $this->db()->query($qf);
		foreach ($rows as $r)
		{
			$favoritesPks[] = $r['datasource'];
			$topPks[] = $r['datasource'];
		}
		$favoritesCnt = count($favoritesPks);

		// -- last login
		$lastLoginLimit = $maxTopDSCnt - 	$favoritesCnt;
		if (!$favoritesCnt && $lastLoginLimit > 0)
		{
			$ql [] = 'SELECT usersds.datasource';
			array_push($ql, ' FROM [e10pro_hosting_server_usersds] AS usersds');
			array_push($ql, ' LEFT JOIN [e10pro_hosting_server_datasources] AS ds ON usersds.datasource = ds.ndx');
			array_push($ql, ' WHERE 1');
			array_push($ql, ' AND usersds.[user] = %i', $this->thisUserId);
			array_push($ql, ' AND usersds.[docStateMain] = 2');
			array_push($ql, ' AND ds.[docStateMain] = 2');
			array_push($ql, ' ORDER BY usersds.lastLogin DESC');
			array_push($ql, ' LIMIT 0, %i', $lastLoginLimit);
			$rows = $this->db()->query($ql);
			foreach ($rows as $r)
			{
				$topPks[] = $r['datasource'];
			}
		}


		$q [] = 'SELECT servers.id AS serverId,';
		array_push($q, ' ds.dsId1, ds.name, ds.shortName, ds.imageUrl, ds.ndx AS dsNdx, ds.gidstr AS dsGidStr,');
		array_push($q, ' udsOptions.ndx AS udsOptionsNdx, udsOptions.favorite AS favorite');
		array_push($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_datasources] AS ds ON usersds.datasource = ds.ndx');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_servers] AS [servers] ON ds.server = servers.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_udsOptions] AS [udsOptions] ON usersds.udsOptions = udsOptions.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ds.[ndx] IN %in', $topPks);
		array_push($q, ' AND usersds.[user] = %i', $this->thisUserId);
		array_push($q, ' AND usersds.[docStateMain] = 2');
		array_push($q, ' AND ds.[docStateMain] = 2');
		array_push($q, ' ORDER BY udsOptions.dsOrder, ds.name');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'title' => $r['shortName'] === '' ? $r['name'] : $r['shortName'],
				'dsGidStr' => $r['dsGidStr'],
				'serverId' => $r['serverId'],
				'dsId1' => $r['dsId1'], 'imageUrl' => $r['imageUrl'],
				'udsOptionsNdx' => $r['udsOptionsNdx'], 'favorite' => $r['favorite']
			];

			$this->topDataSources[] = $item;
		}
	}

	public function selectRows ()
	{
		$q = [];

		$this->qrySelectRows($q, NULL, 0);

		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';

		$q [] = 'SELECT servers.id AS serverId,';
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

		array_push($q, ' ORDER BY ds.[name], ds.[ndx]');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
	}

	function renderPane (&$item)
	{
		$ndx = $item ['dsNdx'];

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass.' df2-action-trigger '];

		$optionsIcon = $item['favorite'] ? 'system/iconStar' : 'system/iconStar';
		$optionsClass = $item['favorite'] ? 'e10-success' : 'e10-off';

		$title = [];
		$title[] = [
			'text' => '', 'icon' => $optionsIcon, 'class' => $optionsClass, 'css' => 'color: #fafafaaa; text-shadow: none;' ,
			'docAction' => 'edit', 'pk' => $item['udsOptionsNdx'], 'table' => 'e10pro.hosting.server.udsOptions',
			'element' => 'span', 'actionClass' => '', 'type' => 'span',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->queryParam('widgetId'),
		];

		$dsTitle = ['class' => '', 'text' => ($item['shortName'] !== '') ? $item['shortName'] : $item['name']];
		$title[] = $dsTitle;

		$item ['pane']['title'][] = [
			'value' => $title
		];

		$css = '';
		$css .= "background-color: #00508ac0; min-height: 3.4em; color: white; text-shadow: 2px 2px 3px rgba(0,0,0,.4);";
		if ($item['imageUrl'] !== '')
		{
			$css .= " background-image:url('{$item['imageUrl']}'); background-size: auto 100%; background-position: right top; background-repeat: no-repeat; xx_padding-right: 4em;";
		}

		$item ['pane']['css'] = $css;
		$item['pane']['data'] =
			[
				'action' => 'open-link',
				'url-download' => $this->dsUrl($item),
			];
	}

	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar-fixed');
	}

	function createCoreSearchCode($fixed = FALSE)
	{
		$this->loadTopDataSources();

		$bgColor = !$fixed ? 'rgba(0,0,0,.1)' : 'transparent';

		$placeholder = 'hledat...';

		$c = '';
		$c .= "<div class='e10-sv-search e10-sv-search-toolbar' style='background-color: $bgColor; border: none; padding: 0;' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%; width:100%;' id='{$this->vid}Search'>";

		$c .= $this->createCodeTopToolbar();
		$c .= $this->createCodeTopDataSources();

		$c .=	"<table style='width: 100%'><tr>";
		$c .= "<td class='fulltext' style='width:100%; padding: 0 1ex;'>";
		$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es($placeholder)."' value='' style='padding: 6px 2em;'/>";
		$c .=	"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0' style='margin-left: -3em; XXXpadding: 6px 2ex 3px 1ex; position:relative; width: 2.5em; text-align: center;'><icon class='fa fa-times' style='width: 1.1em;'></i></span>";
		$c .= '</td>';

		$c .= '</tr></table>';
		$c .= '</div>';

		return $c;
	}

	function createCodeTopToolbar()
	{
		$c = '';

		$c .= "<div class='e10-fx-row padd5' style='background-color: #00508a; color: white; height: 2.5rem;'>";

		$c .= "<div class='e10-fx-col e10-fx-6 e10-fx-align-start'>";
		$c .= "<div class='e10-fx-row' style='margin-top:auto;'>";
		$c .= "<span id='e10-mm-button' style='cursor: pointer; padding-left: 1rem; padding-top:.25rem;'><i class='fa fa-th'></i></span>";
		$c .= "<img alt='Logo Shipard' src='/att/2017/09/26/e10pro.wkf.documents/shipard-logo-header-web-t9n9ug.svg' style='height: 1.6rem; padding-left: 1rem;'>";
		$c .= '</div>';
		$c .= '</div>';

		$c .= "<div class='e10-fx-col e10-fx-6 e10-fx-align-end'>";
		$c .= "<a href='/user/logout-check' title='Odhlásit' style='margin-top: auto;'><i class='fa fa-power-off' style='padding-bottom: .5rem;color: white!important;'></i></a>";
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	function createCodeTopDataSources()
	{
		$c = '';
		$c .= "<div class='e10-fx-row e10-fx-wrap padd5' style='align-items: center; justify-content: center;background-color: #F5F5F5;'>";

		foreach ($this->topDataSources as $ds)
		{
			$styleImg = '';
			if ($ds['imageUrl'] !== '')
				$styleImg .= " background-image:url(\"{$ds['imageUrl']}\"); background-size: cover; background-repeat: no-repeat;";

			$params = '';
			$params .= utils::dataAttrs(['data' => ['action' => 'open-link', 'url-download' => $this->dsUrl($ds)]]);

			$c .= "<div class='e10-pane-hover df2-action-trigger e10-fx-col e10-fx-1' style='height: 7em; margin: 1ex; align-items: center; border: 1px solid rgba(0,0,0,.65); border-radius: 4px; background-color: white; min-width: 7em;white-space: nowrap;overflow: hidden;text-overflow: ellipsis; $styleImg' $params>";

			$optionsIcon = $ds['favorite'] ? 'system/iconStar' : 'system/iconStar';
			$optionsClass = $ds['favorite'] ? 'e10-success' : 'e10-off';

			$btn = [
				'text' => '', 'icon' => $optionsIcon, 'class' => $optionsClass.'', 'css' => 'color: #fafafaaa; text-shadow: none;margin-left: auto;' ,
				'docAction' => 'edit', 'pk' => $ds['udsOptionsNdx'], 'table' => 'e10pro.hosting.server.udsOptions',
				'element' => 'span', 'actionClass' => '', 'type' => 'span',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->queryParam('widgetId'),
			];

			$c .= $this->app()->ui()->renderTextLine($btn);


			$c .= "<div style='margin-top: auto; text-align: center; color: white; background-color: rgba(0,0,0,.75); padding: 2px; width:100%; white-space: nowrap;overflow: hidden;text-overflow: ellipsis;'>".utils::es ($ds['title']).'</div>';
			$c .= '</div>';
		}

		$c .= '</div>';

		return $c;
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
}
