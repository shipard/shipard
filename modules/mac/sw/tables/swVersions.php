<?php

namespace mac\sw;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail,
		\e10\utils, \e10\str, \mac\swcore\libs\SWUtils;


/**
 * Class TableSWVersions
 * @package mac\sw
 */
class TableSWVersions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.swVersions', 'mac_sw_swVersions', 'Verze software');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['versionNumber']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);


		if (isset($recData['suid']) && $recData['suid'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['suid'] = utils::createRecId($recData, '!10z');
		}


		$recData['versionOrderId'] = str::upToLen(preg_replace_callback ('/(\\d+)/', function($match){return (($match[0] + 100000));}, $recData['versionNumber']), 100);
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['suid']) && $recData['suid'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['suid'] = utils::createRecId($recData, '!10z');
			$this->app()->db()->query ("UPDATE [mac_sw_swVersions] SET [suid] = %s WHERE [ndx] = %i", $recData['suid'], $recData['ndx']);
		}

		parent::checkAfterSave2 ($recData);
	}
}


/**
 * Class ViewSWVersions
 * @package mac\sw
 */
class ViewSWVersions extends TableViewGrid
{
	var $swNdx = 0;

	/** @var \mac\swcore\libs\SWUtils */
	var $swUtils;


	public function init ()
	{
		parent::init();

		$this->swUtils = new \mac\swcore\libs\SWUtils($this->app());

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'version' => 'Verze',
			'name' => 'Název',
			'lifeCycle' => 'Stav',
			'dateRelease' => 'Zveřejneno',
			'dateObsolete' => 'Zastaralé',
			'dateEnd' => 'Ukončeno',
		];
		$this->setGrid ($g);

		$this->swNdx = intval($this->queryParam('sw'));
		$this->addAddParam ('sw', $this->swNdx);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['version'] = $item['versionNumber'];

		$listItem ['name'] = [['text' => $item['versionName'], 'class' => 'block nowrap']];
		if ($item['versionNameShort'] !== '')
			$listItem ['name'][] = ['text' => $item['versionNameShort'], 'class' => 'e10-off'];

		$listItem ['note'] = [];

		$listItem ['lifeCycle'] = [];
		if ($item['lifeCycle'])
			$this->swUtils->lcLabel($item['lifeCycle'], $listItem ['lifeCycle']);

		if (!utils::dateIsBlank($item['dateRelease']))
			$listItem ['dateRelease']= utils::datef($item['dateRelease'], '%d');
		if (!utils::dateIsBlank($item['dateObsolete']))
			$listItem ['dateObsolete'] = utils::datef($item['dateObsolete'], '%d');
		if (!utils::dateIsBlank($item['dateEndSupport']))
			$listItem ['dateEnd'] = utils::datef($item['dateEndSupport'], '%d');

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [versions].*';
		array_push ($q, ' FROM [mac_sw_swVersions] AS [versions]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [versions].[sw] = %i', $this->swNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [versionNumber] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [versions].[versionOrderId] DESC, [versions].[versionNumber] DESC, [versions].[ndx] DESC');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailSWVersion
 * @package mac\sw
 */
class ViewDetailSWVersion extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormSWVersion
 * @package mac\sw
 */
class FormSWVersion extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Verze', 'icon' => 'system/iconHashtag'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('versionNumber');
					$this->addColumnInput ('versionName');
					$this->addColumnInput ('versionNameShort');
					$this->addColumnInput ('lifeCycle');
					$this->addColumnInput ('dateRelease');
					$this->addColumnInput ('dateEndSupport');
					$this->addColumnInput ('dateObsolete');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
