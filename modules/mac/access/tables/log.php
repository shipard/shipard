<?php

namespace mac\access;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewPanel, \e10\utils, \e10\str;


/**
 * Class TableLog
 * @package mac\access
 */
class TableLog extends DbTable
{
	CONST lsAccessGranted = 0, lsAccessDenied = 1, lsWarning =  2, lsError = 3, lsBadRequest = 4;
	static $icons = ['icon-check', 'icon-times', 'icon-exclamation', 'icon-exclamation-triangle', 'icon-flash'];

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
 * Class ViewLog
 * @package mac\access
 */
class ViewLog extends \e10\TableViewGrid
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
			'id' => 'id',
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

		$listItem ['id'] = $item['ndx'];
		$listItem ['created'] = utils::datef($item['created'], '%d, %T');
		if ($item['gateName'])
			$listItem['gate'] = $item['gateName'];

		if ($item['keyValue'] === $item['tagKeyValue'])
		{
			$listItem['key'] = ['text' => $item['keyValue'], 'class' => '', 'icon' => $this->tagTypes[$item['tagType']]['icon'].' fa-fw'];
		}
		else
		{
			$keys = [];
			if ($item['keyValue'] !== '')
				$keys[] = ['text' => $item['keyValue'], 'class' => 'e10-error', 'icon' => $this->tagTypes[$item['tagType']]['icon'].' fa-fw'];
			if ($item['tagKeyValue'] && $item['tagKeyValue'] !== '')
				$keys[] = ['text' => $item['tagKeyValue'], 'class' => 'e10-error block', 'icon' => $this->tagTypes[$item['tagType']]['icon'].' fa-fw'];

			$listItem['key'] = $keys;
		}

		if ($item['iotControlName'])
			$listItem['key'] = ['text' => $item['iotControlName'], 'icon' => 'icon-toggle-on fa-fw'];

		$listItem['info'] = [];
		if ($item['personName'])
			$listItem['info'][] = ['text' => $item['personName'], 'icon' => 'icon-user fa-fw'];
		elseif ($item['placeName'])
			$listItem['info'][] = ['text' => $item['placeName'], 'icon' => 'icon-map-marker fa-fw'];

		if ($item['msg'] && $item['msg'] !== '')
			$listItem ['info'][] = ['text' => $item['msg'], 'icon' => 'icon-exclamation-triangle fa-fw', 'class' => ''];


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
		array_push ($q, ' [iotControls].[fullName] AS [iotControlName]');
		array_push ($q, ' FROM [mac_access_log] AS [log]');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS [gates] ON [log].[gate] = [gates].ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS [tags] ON [log].[tag] = [tags].ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [log].[person] = [persons].ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS [places] ON [log].[place] = [places].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_controls] AS [iotControls] ON [log].[iotControl] = [iotControls].ndx');
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
				\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
