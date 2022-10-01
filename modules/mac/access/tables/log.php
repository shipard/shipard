<?php

namespace mac\access;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewDetail, \Shipard\Utils\Utils, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;


/**
 * Class TableLog
 * @package mac\access
 */
class TableLog extends DbTable
{
	CONST lsAccessGranted = 0, lsAccessDenied = 1, lsWarning =  2, lsError = 3, lsBadRequest = 4;
	static $icons = ['system/iconCheck', 'system/iconStop', 'user/exclamation', 'system/iconWarning', 'user/exclamation'];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.log', 'mac_access_log', 'Log přístupů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
//		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return self::$icons[$recData['state']];
	}

}


/**
 * Class ViewLog2
 */
class ViewLog2 extends \e10\TableViewGrid
{
	static $stateClasses = ['e10-row-play', 'e10-row-info', 'e10-warning1', 'e10-warning2', 'e10-warning3'];
	var $tagTypes;

	public function init ()
	{
		parent::init();

		$this->tagTypes = $this->app()->cfgItem('mac.access.tagTypes');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->usePanelRight = 3;
		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;

		$g = [
			'id' => ' id',
			'created' => 'Datum',
			'gate' => 'Brána',
			'key' => 'Klíč',
			'info' => 'Info',
		];

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['id'] = '#'.$item['ndx'];
		$listItem ['created'] = Utils::datef($item['created'], '%d, %T');
		if ($item['iotSetupName'])
			$listItem['gate'] = $item['iotSetupName'];

		if (Str::strcasecmp($item['keyValue'], $item['tagKeyValue']) === 0)
		{
			$listItem['key'] = ['text' => $item['keyValue'], 'class' => '', 'icon' => $this->tagTypes[$item['tagType']]['icon']];
		}
		else
		{
			$keys = [];
			if ($item['keyValue'] !== '')
				$keys[] = ['text' => $item['keyValue'], 'class' => 'e10-error', 'icon' => $this->tagTypes[$item['tagType']]['icon']];
			if ($item['tagKeyValue'] && $item['tagKeyValue'] !== '')
				$keys[] = ['text' => $item['tagKeyValue'], 'class' => 'e10-error block', 'icon' => $this->tagTypes[$item['tagType']]['icon']];

			$listItem['key'] = $keys;
		}

		if ($item['iotControlName'])
			$listItem['key'] = ['text' => $item['iotControlName'], 'icon' => 'user/toggleOn'];

		$listItem['info'] = [];
		if ($item['personName'])
			$listItem['info'][] = ['text' => $item['personName'], 'icon' => 'system/iconUser', 'class' => ''];
		elseif ($item['placeName'])
			$listItem['info'][] = ['text' => $item['placeName'], 'icon' => 'tables/e10.base.places', 'class' => ''];

		if ($item['msg'] && $item['msg'] !== '')
			$listItem ['info'][] = ['text' => $item['msg'], 'icon' => 'system/iconFile', 'class' => 'e10-me'];


		if ($item['state'])
			$listItem ['class'] = self::$stateClasses[$item['state']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [log].*, ';
		array_push ($q, ' [gates].[fullName] AS [gateName],');
		array_push ($q, ' [tags].[keyValue] AS [tagKeyValue],');
		array_push ($q, ' [persons].[fullName] AS [personName], [persons].company, [persons].personType,');
		array_push ($q, ' [places].[fullName] AS [placeName],');
		array_push ($q, ' [iotControls].[fullName] AS [iotControlName],');
		array_push ($q, ' [iotSetups].[fullName] AS [iotSetupName]');
		array_push ($q, ' FROM [mac_access_log] AS [log]');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS [gates] ON [log].[gate] = [gates].ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS [tags] ON [log].[tag] = [tags].ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [log].[person] = [persons].ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS [places] ON [log].[place] = [places].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_controls] AS [iotControls] ON [log].[iotControl] = [iotControls].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS [iotSetups] ON [log].[gate] = [iotSetups].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' persons.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push($q,' ORDER BY [log].[ndx] DESC ');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}

/**
 * class ViewLog
 */
class ViewLog extends TableView
{
	static $stateClasses = ['e10-row-play', 'e10-row-info', 'e10-warning1', 'e10-warning2', 'e10-warning3'];
	var $tagTypes;

	public function init ()
	{
		parent::init();

		$this->tagTypes = $this->app()->cfgItem('mac.access.tagTypes');
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['id'] = '#'.$item['ndx'];
		$listItem ['i1'] = ['text' => Utils::datef($item['created'], '%S %T'), 'class' => 'id'];

		if ($item['personName'])
			$listItem['t1'] = ['text' => $item['personName'], 'icon' => 'system/iconUser', 'class' => ''];
		elseif ($item['placeName'])
			$listItem['t1'] = ['text' => $item['placeName'], 'icon' => 'system/iconHome', 'class' => ''];

		$listItem['t2'] = [];

		if (Str::strcasecmp($item['keyValue'], $item['tagKeyValue']) === 0)
		{
			$listItem['t2'][] = ['text' => $item['keyValue'], 'class' => '', 'icon' => $this->tagTypes[$item['tagType']]['icon']];
		}
		else
		{
			if ($item['keyValue'] !== '')
				$listItem['t2'][] = ['text' => $item['keyValue'], 'class' => 'e10-error', 'icon' => $this->tagTypes[$item['tagType']]['icon']];
			if ($item['tagKeyValue'] && $item['tagKeyValue'] !== '')
				$listItem['t2'][] = ['text' => $item['tagKeyValue'], 'class' => 'e10-error block', 'icon' => $this->tagTypes[$item['tagType']]['icon']];
		}

		if ($item['iotControlName'])
			$listItem['key'] = ['text' => $item['iotControlName'], 'icon' => 'user/toggleOn'];

		$listItem['t3'] = [];

		if ($item['msg'] && $item['msg'] !== '')
			$listItem ['t3'][] = ['text' => $item['msg'], 'icon' => 'system/iconFile', 'class' => 'e10-me'];



		if ($item['iotSetupName'])
			$listItem['t2'][] = ['text' => $item['iotSetupName'], 'class' => '', 'icon' => 'tables/e10.base.places'];

		if ($item['state'])
			$listItem ['class'] = self::$stateClasses[$item['state']];

		if ($item['state'] === 0)
			$listItem ['icon'] = $this->tagTypes[$item['tagType']]['icon'];
		else
			$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [log].*, ';
		array_push ($q, ' [gates].[fullName] AS [gateName],');
		array_push ($q, ' [tags].[keyValue] AS [tagKeyValue],');
		array_push ($q, ' [persons].[fullName] AS [personName], [persons].company, [persons].personType,');
		array_push ($q, ' [places].[fullName] AS [placeName],');
		array_push ($q, ' [iotControls].[fullName] AS [iotControlName],');
		array_push ($q, ' [iotSetups].[fullName] AS [iotSetupName]');
		array_push ($q, ' FROM [mac_access_log] AS [log]');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS [gates] ON [log].[gate] = [gates].ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS [tags] ON [log].[tag] = [tags].ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [log].[person] = [persons].ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS [places] ON [log].[place] = [places].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_controls] AS [iotControls] ON [log].[iotControl] = [iotControls].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS [iotSetups] ON [log].[gate] = [iotSetups].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' persons.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR log.[keyValue] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// special queries
		$qv = $this->queryValues ();

		if (isset ($qv['tagTypes']))
			array_push ($q, " AND [log].[tagType] IN %in", array_keys($qv['tagTypes']));
		if (isset ($qv['setups']))
			array_push ($q, " AND [log].[gate] IN %in", array_keys($qv['setups']));
		if (isset ($qv['iotControls']))
		{
			array_push ($q, " AND ([log].[mainKeyType] = %i", 2);
			array_push ($q, " AND [log].[iotControl] IN %in", array_keys($qv['iotControls']), ')');
		}
		if (isset ($qv['iotBoxes']))
		{
			array_push ($q, " AND ([log].[mainKeyType] = %i", 3, ' AND (');
			$first = 1;
			foreach ($qv['iotBoxes'] as $ibNdx => $ibId)
			{
				if (!$first)
					array_push($q, ' OR ');
				array_push($q, '[log].msg = %s', 'shp/iot-boxes/'.$ibNdx);
				$first = 0;
			}
			array_push($q, '))');
		}

		array_push($q,' ORDER BY [log].[ndx] DESC ');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tag types
		$chbxTagTypes = [];
		forEach ($this->tagTypes as $ttId => $tt)
			$chbxTagTypes[$ttId] = ['title' => $tt['name'], 'id' => $ttId];

		// -- iotControls
		$chbxIotControls = [];
		$iotControlsQry = 'SELECT * FROM [mac_iot_controls] WHERE docStateMain < 5 ORDER BY shortName, ndx';
		$iotControlsRows = $this->table->db()->query ($iotControlsQry);
		if (count($iotControlsRows) !== 0)
		{
			forEach ($iotControlsRows as $c)
				$chbxIotControls[$c['ndx']] = ['title' => $c['fullName'], 'id' => $c['ndx']];
		}

		// -- iotBoxes
		$chbxIotBoxes = [];
		$iotBoxesQry = [];
		array_push ($iotBoxesQry, 'SELECT ports.*, devices.fullName AS deviceName, devices.friendlyId AS deviceId ',
			' FROM [mac_iot_devicesIOPorts] AS [ports]',
			' RIGHT JOIN [mac_iot_devices] AS [devices] ON [ports].iotDevice = [devices].[ndx]',
			' WHERE [ports].portType = %s', 'input/binary',
			' AND [ports].valueStyle = %i', 1,
		 	' ORDER BY [ports].fullName, [ports].ndx'
		);
		$iotBoxesRows = $this->table->db()->query ($iotBoxesQry);
		if (count($iotBoxesRows) !== 0)
		{
			forEach ($iotBoxesRows as $c)
				$chbxIotBoxes[$c['deviceId']] = ['title' => $c['deviceName'], 'id' => $c['deviceId']];
		}

		$paramsTagTypes = new \Shipard\UI\Core\Params ($panel->table->app());
		$paramsTagTypes->addParam ('checkboxes', 'query.tagTypes', ['items' => $chbxTagTypes]);
		if (count($chbxIotControls))
			$paramsTagTypes->addParam ('checkboxes', 'query.iotControls', ['items' => $chbxIotControls]);
		if (count($chbxIotBoxes))
			$paramsTagTypes->addParam ('checkboxes', 'query.iotBoxes', ['items' => $chbxIotBoxes]);
		$qry[] = ['id' => 'openTypes', 'style' => 'params', 'title' => 'Způsob otevření', 'params' => $paramsTagTypes];

		// -- setups
		$setupsQry = 'SELECT * FROM [mac_iot_setups] WHERE docStateMain < 5 ORDER BY shortName, ndx';
		$setupsRows = $this->table->db()->query ($setupsQry);
		if (count($setupsRows) !== 0)
		{
			$chbxSetups = [];
			$chbxSetups['0'] = ['title' => '---', 'id' => 0];
			forEach ($setupsRows as $s)
				$chbxSetups[$s['ndx']] = ['title' => $s['shortName'], 'id' => $s['ndx']];

			$paramsSetups = new \Shipard\UI\Core\Params ($panel->table->app());
			$paramsSetups->addParam ('checkboxes', 'query.setups', ['items' => $chbxSetups]);
			$qry[] = ['id' => 'itemBrands', 'style' => 'params', 'title' => 'Vstupy', 'params' => $paramsSetups];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}

class ViewDetailLog extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.access.dc.LogItem');
	}
}


/**
 * Class FormLog
 * @package mac\access
 */
class FormLog extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Info', 'icon' => 'icon-info'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
