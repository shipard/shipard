<?php

namespace hosting\core\libs;
use \Shipard\Viewer\TableView;


/**
 * Class ViewerDashboardUsersDataSources
 * @package hosting\core\libs
 */
class ViewerDashboardUsersDataSources extends TableView
{
	var $thisUserId = 0;
	var $paneClass='';

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->fullWidthToolbar = TRUE;

		$this->onlineLimit = new \DateTime();
		$this->onlineLimit->sub (new \DateInterval('PT30M'));

		if (!$this->thisUserId)
			$this->thisUserId = $this->app()->userNdx();

		parent::init();
	}

	public function renderRow ($item)
	{
		if ($item['imageUrl'] !== '')
		{
			$listItem ['svgIcon'] = $item['imageUrl'];
		}
		elseif ($item['dsEmoji'] !== '')
		{
			$listItem ['emoji'] = $item['dsEmoji'];
		}
		elseif ($item['dsIcon'] !== '')
		{
			$listItem ['icon'] = $item['dsIcon'];
		}
		else
		{
			$listItem ['icon'] = 'system/iconDatabase';
		}
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['dsGidStr'], 'class' => 'id'];
		$listItem ['class'] = 'df2-action-trigger';
		if ($item['appWarning'] != 0)
			$listItem ['class'] .= ' e10-row-minus';

		$props = [];

		$props[] = [
			'text' => 'Nastavení', 'icon' => 'system/iconSettings',
			'docAction' => 'edit', 'pk' => $item['udsOptionsNdx'], 'table' => 'hosting.core.dsUsersOptions',
			'element' => 'button', 'actionClass' => 'btn btn-success', 'type' => 'button',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->queryParam('widgetId'),
		];

		$listItem['data-url-download'] = $this->dsUrl($item);


		$props3 = [];

		//$stateLabels = $this->table->dsStateLabels($item);
		//$props3 [] = $stateLabels['condition'];

		$listItem ['i2'] = [];

		if (count($props))
			$listItem ['t2'] = $props;
		if (count($props3))
			$listItem ['t3'] = $props3;

		if ($item['inProgress'])
			$listItem ['class'] = 'e10-row-this';

		return $listItem;
	}

	public function selectRows ()
	{
		$q = [];

		//array_push($q, '(');
		$this->qrySelectRows($q, NULL, 0);
		//array_push($q, ')');
		//array_push ($q, 'UNION');

		/*
		array_push($q, '(');
		$this->qrySelectRows($q, NULL, 1);
		array_push($q, ')');
		*/

		array_push($q, ' ORDER BY [name]');

		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT '.$selectPartNumber.' AS selectPart,';

		if ($selectPartNumber === 0)
			array_push($q, ' udsOptions.[toolbarOrder] AS dsOrder,');
		else
			array_push($q, ' 0 AS dsOrder,');

		array_push($q, ' servers.id AS serverId,');
		array_push($q, ' ds.dsId1, ds.name, ds.shortName, ds.imageUrl, ds.ndx AS dsNdx, ds.gid AS dsGidStr, ds.docState AS docState, ds.docStateMain AS docStateMain,');
		array_push($q, ' ds.dsEmoji AS dsEmoji, ds.dsIcon AS dsIcon, ds.appWarning, ds.inProgress,');
		array_push($q, ' udsOptions.ndx AS udsOptionsNdx');
		array_push($q, ' FROM [hosting_core_dsUsers] AS usersds');
		array_push($q, ' RIGHT JOIN [hosting_core_dataSources] AS ds ON usersds.dataSource = ds.ndx');
		array_push($q, ' RIGHT JOIN [hosting_core_servers] AS [servers] ON ds.server = servers.ndx');
		array_push($q, ' LEFT JOIN [hosting_core_dsUsersOptions] AS [udsOptions] ON usersds.dsUsersOptions = udsOptions.ndx');
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
			array_push ($q, ' ds.[name] LIKE %s OR ds.[gid] LIKE %s', '%'.$fts.'%', $fts.'%');
			if ($ascii)
			{
				array_push($q, ' OR ds.dsId1 LIKE %s', '%' . $fts . '%');
				array_push($q, ' OR ds.dsId2 LIKE %s', '%' . $fts . '%');
			}
			array_push ($q, ')');
		}
	}

	public function createToolbar()
	{
		$tlbr = [];

		if ($this->app()->hasRole('hstngdb'))
		{
			$addButton = [
				'text' => 'Nová databáze', 'action' => 'wizard', 'icon' => 'system/iconDatabase', 'data-class' => 'hosting.core.libs.WizardNewDatasource',
				'btnClass' => 'btn btn-primary',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
			];
			$tlbr[] = $addButton;
		}

		return $tlbr;
	}

	function dsUrl ($ds)
	{
		if ($ds['dsId1'] === '')
		{
			return 'https://'.$ds['serverId'].'.shipard.app/'.$ds['dsGidStr'].'/';
		}

		return 'https://'.$ds['dsId1'].'.shipard.app';
	}
}
