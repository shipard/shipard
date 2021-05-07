<?php

namespace integrations\ntf;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableChannels
 * @package integrations\ntf
 */
class TableChannels extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.ntf.channels', 'integrations_ntf_channels', 'Notifikační kanály', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'channelCfg')
		{
			$channelType = $this->app()->cfgItem('integration.ntf.channels.types.' . $recData['channelType'], NULL);

			if (!$channelType || !isset($channelType['id']))
				return FALSE;

			$cfgFileName = __APP_DIR__.'/e10-modules/integrations/ntf/config/notifyChannelsDefs/'.$channelType['id'].'.json';
			$cfg = utils::loadCfgFile($cfgFileName);
			if ($cfg)
				return $cfg['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * Class ViewChannels
 * @package integrations\ntf
 */
class ViewChannels extends TableView
{
	var $channelsTypes;

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		$this->channelsTypes = $this->app()->cfgItem('integration.ntf.channels.types');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$channelType = $this->channelsTypes[$item['channelType']];
		$listItem['t2'] = ['text' => $channelType['name'], 'icon' => $channelType['icon'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_ntf_channels]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormChannel
 * @package integrations\ntf
 */
class FormChannel extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Kanál', 'icon' => 'icon-bullhorn'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput('channelType');
					$this->addColumnInput('fullName');
				$this->closeTab();
				$this->openTab ();
					$this->addSubColumns('channelCfg');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailNotifyChannel
 * @package integrations\core
 */
class ViewDetailNotifyChannel extends TableViewDetail
{
}


