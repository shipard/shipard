<?php

namespace e10pro\hosting\client\libs;

use \e10\utils, \e10\Content;


/**
 * Class UsersDataSourcesReview
 * @package e10pro\hosting\client\libs
 */
class UsersDataSourcesReview extends Content
{
	var $dsNdx = 0;
	var $dsHeader = NULL;
	var $userNdx = 0;

	function loadDataSources_Query($selectPartNumber, &$q)
	{
		$q [] = 'SELECT '.$selectPartNumber.' AS selectPart,';

		if ($selectPartNumber === 0)
			array_push($q, ' udsOptions.[dsOrder] AS dsOrder,');
		else
			array_push($q, ' 0 AS dsOrder,');

		array_push($q, ' servers.id AS serverId,');
		array_push($q, ' ds.dsId1, ds.name, ds.shortName, ds.imageUrl, ds.ndx AS dsNdx, ds.gidstr AS dsGidStr,');
		array_push($q, ' udsOptions.ndx AS udsOptionsNdx, udsOptions.favorite AS favorite,');
		array_push($q, ' udsSummary.[cnt] AS sumCnt, udsSummary.[data] AS sumData');
		array_push($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_datasources] AS ds ON usersds.datasource = ds.ndx');
		array_push($q, ' RIGHT JOIN [e10pro_hosting_server_servers] AS [servers] ON ds.server = servers.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_udsOptions] AS [udsOptions] ON usersds.udsOptions = udsOptions.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_udsSummary] AS [udsSummary] ON usersds.datasource = udsSummary.dataSource AND udsSummary.[user] = %i', $this->userNdx);
		array_push($q, ' WHERE 1');
		array_push($q, ' AND usersds.[user] = %i', $this->userNdx);
		array_push($q, ' AND usersds.[docStateMain] = 2');
		array_push($q, ' AND ds.[docStateMain] = 2');

		if ($this->dsNdx)
			array_push($q, ' AND usersds.[datasource] = %i', $this->dsNdx);

		array_push($q, ' AND udsSummary.[cnt] != %i', 0);
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
	}

	function loadDataSources()
	{
		$q = [];

		array_push($q, '(');
		$this->loadDataSources_Query(0, $q);
		array_push($q, ')');
		array_push ($q, 'UNION');

		array_push($q, '(');
		$this->loadDataSources_Query(1, $q);
		array_push($q, ')');

		array_push($q, ' ORDER BY selectPart, dsOrder, [name]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dsInfoItem = [
				'name' => $r['name'], 'dsUrl' => $this->dsUrl($r),
				'data' => json_decode($r['sumData'], TRUE),
			];

			$this->createContentOne($dsInfoItem);
		}
	}

	function createContentOne($dsInfoItem)
	{
		$ntfBadge = '';
		if ($dsInfoItem['data']['cntUnread'])
			$ntfBadge .= "<sup class='e10-ntf-badge'>".$dsInfoItem['data']['cntUnread']."</sup>";
		if ($dsInfoItem['data']['cntTodo'])
			$ntfBadge .= " <sup class='e10-ntf-badge e10-ntf-badge-todo'>".$dsInfoItem['data']['cntTodo']."</sup>";

		$dsTitle = [
			[
				'class' => 'pb05',
				'value' => [
					['text' => $dsInfoItem['name'], 'class' => 'h1 e10-bold'],
					['code' => $ntfBadge],
					[
						'text' => '', 'icon' => 'icon-external-link', 'title' => 'Otevřít',
						'action' => 'open-link', 'element' => 'span',
						'data-url-download' => $dsInfoItem['dsUrl'],
						'class' => 'pull-right df2-action-trigger h3', 'btnClass' => '',
					],
				]
			]
		];

		$rows = [];

		$sections = [];
		foreach ($dsInfoItem['data']['sections'] as $dsSectionNdx => $dsSectionInfo)
		{
			$label = ['text' => $dsSectionInfo['title'], 'icon' => $dsSectionInfo['icon'], 'class' => 'label label-default'];
			if (isset($dsSectionInfo['psTitle']))
				$label['suffix'] = $dsSectionInfo['psTitle'];

			$sections[] = $label;

			if ($dsSectionInfo['cntUnread'] || $dsSectionInfo['cntTodo'])
			{
				$ntfBadge = '';
				if ($dsSectionInfo['cntUnread'])
					$ntfBadge .= "<sup class='e10-ntf-badge'>" . $dsSectionInfo['cntUnread'] . "</sup>";
				if ($dsSectionInfo['cntTodo'])
					$ntfBadge .= " <sup class='e10-ntf-badge e10-ntf-badge-todo'>" . $dsSectionInfo['cntTodo'] . "</sup> &nbsp; ";
				$ntfBadge .= ' &nbsp; ';
				$sections[] = ['code' => $ntfBadge];
			}
			else
				$sections[] = ['code' => ' &nbsp; '];

			if (count($dsSectionInfo['issues']['unread']) || $dsSectionInfo['issues']['todo'])
			{
				$sectionTitle = ['text' => $dsSectionInfo['title'], 'icon' => $dsSectionInfo['icon'], 'class' => ''];
				if (isset($dsSectionInfo['psTitle']))
					$sectionTitle['suffix'] = $dsSectionInfo['psTitle'];
				$rowItem = ['title' => $sectionTitle, 'class' => 'e10-bold tl-header e10-ds e10-docstyle-archive'];

				$rows[] = $rowItem;
			}

			foreach ($dsSectionInfo['issues']['unread'] as $issueNdx)
			{
				$this->createContentOne_AddIssue($rows, $dsInfoItem, $issueNdx, ' e10-block-notification');
			}
			foreach ($dsSectionInfo['issues']['todo'] as $issueNdx)
			{
				$this->createContentOne_AddIssue($rows, $dsInfoItem, $issueNdx, '');
			}
		}

		if (count($sections))
			$dsTitle[] = ['value' => $sections, 'class' => 'lh16'];

		if ($this->dsNdx)
		{
			$list = ['rows' => $rows];
			$this->dsHeader = $dsTitle;
		}
		else
			$list = ['rows' => $rows, 'class' => 'e10-error', 'title' => $dsTitle];

		$this->addContent(['pane' => 'e10-pane-core mb2 e10-bg-t6', 'type' => 'list', 'list' => $list]);
	}

	function createContentOne_AddIssue(&$rows, $dsInfoItem, $issueNdx, $titleClass)
	{
		$issue = $dsInfoItem['data']['issues'][$issueNdx];
		$rowItem = ['title' => [], 'class' => 'e10-ds '.$issue['dsc'].$titleClass];

		if ($this->app()->hasRole('hstngcd'))
		{
			$url = $dsInfoItem['dsUrl'];
			$url .= '/app/!/e10-document-trigger/wkf.core.issues/edit/'.$issue['ndx'].'?e10window=app-iframe';
			$rowItem['title'][] = [
				'text' => $issue['subject'], 'icon' => $issue['icon'],
				'element' => 'span', 'btnClass' => '', 'actionClass' => '',
				'type' => 'action', 'action' => 'open-iframe-app',
				'data-popup-url' =>  $url,
			];
		}
		else
			$rowItem['title'][] = ['text' => $issue['subject'], 'icon' => $issue['icon']];

		$rows[] = $rowItem;
	}

	function dsUrl ($ds)
	{
		if ($ds['dsId1'] === '')
		{
			return 'https://'.$ds['serverId'].'.shipard.app/'.$ds['dsGidStr'].'/';
		}

		return 'https://'.$ds['dsId1'].'.shipard.app';
	}

	function checkUnlinkedDataSources()
	{
		$q = [];
		array_push($q, 'SELECT [summary].*, dataSources.name AS dsFullName');
		array_push($q, ' FROM [e10pro_hosting_server_udsSummary] AS [summary]');
		array_push($q, ' LEFT JOIN e10pro_hosting_server_datasources AS dataSources ON [summary].[dataSource] = [dataSources].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [summary].[cnt] != %i', 0);
		array_push($q, ' AND [summary].[user] = %i', $this->userNdx);

		array_push ($q, ' AND NOT EXISTS (',
			'SELECT ndx FROM e10pro_hosting_server_usersds ',
			'WHERE e10pro_hosting_server_usersds.datasource = summary.dataSource',
			' AND e10pro_hosting_server_usersds.user = %i', $this->userNdx,
			' AND e10pro_hosting_server_usersds.docState = %i', 4000,
			')'
		);

		$unlinkedDataSources = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{


			$unlinkedDataSources[] = ['text' => $r['dsFullName'], 'suffix' => '#'.$r['dataSource'], 'class' => 'block padd5 bt1', 'icon' => 'system/iconDatabase'];
		}

		if (count($unlinkedDataSources))
		{
			$paneTitle = [
				['text' => 'Máte zprávy z databází, které nevidíte', 'class' => 'h2 block', 'icon' => 'system/iconWarning'],
				['text' => 'Kontaktujte prosím technickou podporu, abychom to napravili', 'class' => 'block mb1'],
			];
			$this->addContent(['pane' => 'e10-pane-core padd5 mb2 e10-bg-t3', 'type' => 'line', 'line' => $unlinkedDataSources, 'paneTitle' => $paneTitle]);
		}
	}

	function create()
	{
		$this->userNdx = $this->app()->userNdx();
		$this->loadDataSources();
		$this->checkUnlinkedDataSources();
	}
}
