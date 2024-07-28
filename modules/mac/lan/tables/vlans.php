<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableVlans
 * @package mac\lan
 */
class TableVlans extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.vlans', 'mac_lan_vlans', 'VLANy');
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docStateMain'] > 1)
		{
			$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
			$une->init();
			$une->update();
		}

		parent::checkAfterSave2($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['isGroup'])
			return 'user/folder';

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewVlans
 * @package mac\lan
 */
class ViewVlans extends TableView
{
	var $isGroup = FALSE;
	var $groupsInfo = [];
	var $addrRanges = [];

	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		//$listItem ['t2'] = $item['id'];

		if ($item['isGroup'])
			$listItem ['i1'] = ['text' => $item['id']];
		else
			$listItem ['i1'] = ['text' => '#'.$item['num'], 'prefix' => $item['id']];

		$listItem ['t1'] = $item['fullName'];
		if ($item['lanShortName'])
			$listItem ['i2'] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap'];
		else
			$listItem ['i2'] = ['text' => '!!!', 'icon' => 'system/iconSitemap'];

		if ($item['isPublic'])
			$listItem ['t2'] = [['text' => 'Veřejná', 'icon' => 'system/iconUser', 'class' => 'label label-warning']];

		if ($item['ipv6Enabled'])
			$listItem ['t2'] = [['text' => 'ipv6', 'icon' => 'system/iconCheck', 'class' => 'label label-success']];
		if ($item['ipv4Disabled'])
			$listItem ['t2'] = [['text' => 'ipv4', 'icon' => 'user/timesCircle', 'class' => 'label label-danger']];
		if ($item['internetDisabled'])
			$listItem ['t2'] = [['text' => 'Internet', 'icon' => 'user/timesCircle', 'class' => 'label label-danger']];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT vlans.*, lans.shortName as lanShortName';
		array_push ($q, ' FROM [mac_lan_vlans] AS vlans');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON vlans.lan = lans.ndx');
		array_push ($q, ' WHERE 1');

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [vlans].[lan] = %i', $lan);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q,' AND (');
			array_push($q,'[id] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR vlans.[fullName] LIKE %s', '%'.$fts.'%');

			if (strval(intval($fts)) === $fts)
				array_push($q,' OR vlans.[num] = %i',$fts);

			array_push($q,')');
		}

		if ($this->isGroup !== FALSE)
			array_push ($q, ' AND vlans.[isGroup] = %i', $this->isGroup);

		// -- aktuální
		$this->queryMain ($q, 'vlans.', ['[isGroup] DESC', '[id]', '[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- groups in vlan
		$q[] = 'SELECT docLinks.*, vlans.fullName AS vlanName, vlans.[num] AS vlanNum';
		array_push ($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON docLinks.dstRecId = vlans.ndx');
		array_push ($q, ' WHERE srcTableId = %s', 'mac.lan.vlans', 'AND dstTableId = %s', 'mac.lan.vlans');
		array_push ($q, ' AND docLinks.linkId = %s', 'mac-lan-vlans-groups', 'AND srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['vlanName'], 'icon' => 'iconUserVlan', 'class' => 'label label-info'];
			$this->groupsInfo[$r['srcRecId']][] = $l;
		}

		// -- vlans in group
		$q = [];
		$q[] = 'SELECT docLinks.*, vlans.fullName AS vlanName, vlans.[num] AS vlanNum';
		array_push ($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON docLinks.srcRecId = vlans.ndx');
		array_push ($q, ' WHERE srcTableId = %s', 'mac.lan.vlans', 'AND dstTableId = %s', 'mac.lan.vlans');
		array_push ($q, ' AND docLinks.linkId = %s', 'mac-lan-vlans-groups', 'AND dstRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['vlanName'], 'suffix' => $r['vlanNum'], 'icon' => 'tables/mac.lan.vlans', 'class' => 'label label-primary'];
			$this->groupsInfo[$r['dstRecId']][] = $l;
		}

		// -- incoming vlans
		$q = [];
		$q[] = 'SELECT docLinks.*, vlans.fullName AS vlanName, vlans.[num] AS vlanNum, vlans.[isGroup] AS vlanIsGroup';
		array_push ($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON docLinks.dstRecId = vlans.ndx');
		array_push ($q, ' WHERE srcTableId = %s', 'mac.lan.vlans', 'AND dstTableId = %s', 'mac.lan.vlans');
		array_push ($q, ' AND docLinks.linkId = %s', 'mac-lan-vlans-incoming', 'AND srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['vlanName'], 'icon' => ($r['vlanIsGroup']?'user/folder':'tables/mac.lan.vlans'), 'class' => 'label label-success'];
			if (!$r['vlanIsGroup'])
				$l['suffix'] = $r['vlanNum'];
			$this->groupsInfo[$r['srcRecId']][] = $l;
		}

		// -- address ranges
		$q = [];
		$q[] = 'SELECT addrRanges.*';
		array_push ($q, ' FROM [mac_lan_lansAddrRanges] AS addrRanges');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND addrRanges.vlan IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['shortName'], 'icon' => 'tables/mac.lan.lansAddrRanges', 'class' => 'label label-default'];
			$this->addrRanges[$r['vlan']][] = $l;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->groupsInfo [$item ['pk']]))
		{
			if (isset($item ['t2']))
				$item['t2'] = array_merge($item['t2'], $this->groupsInfo [$item ['pk']]);
			else
				$item['t2'] = $this->groupsInfo [$item ['pk']];
		}
		if (isset ($this->addrRanges [$item ['pk']]))
		{
			if (isset($item ['t2']))
				$item['t2'] = array_merge($item['t2'], $this->addrRanges [$item ['pk']]);
			else
				$item['t2'] = $this->addrRanges [$item ['pk']];
		}
	}
}


/**
 * Class ViewVlansComboVlans
 * @package mac\lan
 */
class ViewVlansComboVlans extends ViewVlans
{
	public function init ()
	{
		$this->isGroup = 0;
		parent::init();
	}
}


/**
 * Class ViewVlansComboGroups
 * @package mac\lan
 */
class ViewVlansComboGroups extends ViewVlans
{
	public function init ()
	{
		$this->isGroup = 1;
		parent::init();
	}
}


/**
 * Class ViewDetailVlan
 * @package mac\lan
 */
class ViewDetailVlan extends TableViewDetail
{
}


/**
 * Class FormVlan
 * @package mac\lan
 */
class FormVlan extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();

			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs,TRUE);
				$this->openTab ();
					$this->addColumnInput ('isGroup');
					if (!$this->recData['isGroup'])
						$this->addColumnInput ('num');
					$this->addColumnInput ('id');
					$this->addColumnInput ('fullName');
					if (!$this->recData['isGroup'])
					{
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('isPublic');
						$this->addColumnInput ('internetDisabled');
						$this->addColumnInput ('ipv6Enabled');
						$this->addColumnInput ('ipv4Disabled');
						$this->addSeparator(self::coH4);
					}
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('lan');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}

	public function docLinkEnabled ($docLink)
	{
		if ($docLink['linkid'] === 'mac-lan-vlans-incoming' && $this->recData['isPublic'])
			return FALSE;

		return parent::docLinkEnabled($docLink);
	}
}
