<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils;

/**
 * Class TableLansAddrRanges
 * @package mac\lan
 */
class TableLansAddrRanges extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.lansAddrRanges', 'mac_lan_lansAddrRanges', 'Rozsahy adres sítí');
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

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['range']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['note']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [mac_lan_lansAddrRanges] WHERE [docState] != 9800 ORDER BY [range]');

		foreach ($rows as $r)
		{
			$list [
				$r['ndx']] = ['ndx' => $r ['ndx'], 'id' => $r ['id'], 'sn' => $r ['shortName'], 'fn' => $r ['fullName'],
				'range' => $r ['range'], 'ap' => $r ['addressPrefix'], 'lan' => $r ['lan']
			];
		}

		// -- save to file
		$cfg ['mac']['lan']['addrRanges'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_mac.lan.addrRanges.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewLansAddrRanges
 * @package mac\lan
 */
class ViewLansAddrRanges extends TableView
{
	var $prevPools = [];

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
		$listItem ['t1'] = $item['id'];
		$listItem ['i1'] = ['text' => $item['range'], 'suffix' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['fullName'];

		if ($item['nextPool'])
			$listItem ['t3'] = [['text' => $item['npFullName'], 'suffix' => $item['npRange'], 'icon' => 'icon-arrow-right', 'class' => 'label label-info']];

		$listItem ['i2'] = [];

		if ($item['smFullName'])
			$listItem ['i2'][] = ['text' => $item['smFullName'], 'icon' => 'icon-arrows-alt', 'class' => 'label label-info'];

		if ($item['vlanId'])
			$listItem ['i2'][] = ['text' => $item['vlanId'], 'icon' => 'icon-road', 'class' => ''];

		if ($item['lanShortName'])
			$listItem ['i2'][] = ['text' => $item['lanShortName'], 'icon' => 'icon-sitemap', 'class' => ''];
		else
			$listItem ['i2'][] = ['text' => '!!!', 'icon' => 'icon-sitemap', 'class' => 'label label-danger'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ranges.*, lans.shortName as lanShortName, vlans.id as vlanId,';
		array_push ($q, ' nextPools.fullName AS npFullName, nextPools.range AS npRange,');
		array_push ($q, ' serversMonitoring.fullName AS smFullName, serversMonitoring.id AS smId');
		array_push ($q, ' FROM [mac_lan_lansAddrRanges] AS ranges');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON ranges.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON ranges.vlan = vlans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lansAddrRanges AS nextPools ON ranges.nextPool = nextPools.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS serversMonitoring ON ranges.serverMonitoring = serversMonitoring.ndx');
		array_push ($q, ' WHERE 1');

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [ranges].[lan] = %i', $lan);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
				'ranges.[fullName] LIKE %s', '%'.$fts.'%',
						'OR ranges.[shortName] LIKE %s', '%'.$fts.'%',
						'OR ranges.[range] LIKE %s', '%'.$fts.'%',
						'OR ranges.[note] LIKE %s', '%'.$fts.'%',
				')');
		}

		// -- aktuální
		$this->queryMain ($q, 'ranges.', ['[id]', '[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- groups in vlan
		$q [] = 'SELECT ranges.*';
		array_push ($q, ' FROM [mac_lan_lansAddrRanges] AS ranges');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ranges.nextPool IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['fullName'], 'suffix' => $r['range'], 'icon' => 'icon-arrow-left', 'class' => 'label label-default'];
			$this->prevPools[$r['nextPool']][] = $l;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->prevPools[$item ['pk']]))
		{
			if (isset($item ['t3']))
				$item ['t3'] = array_merge($this->prevPools[$item ['pk']], $item ['t3']);
			else
				$item ['t3'] = $this->prevPools[$item ['pk']];
		}
	}

}


/**
 * Class ViewDetailLansAddrRange
 * @package mac\lan
 */
class ViewDetailLansAddrRange extends TableViewDetail
{
}


/**
 * Class FormLanAddrRange
 * @package mac\lan
 */
class FormLanAddrRange extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

			$tabs ['tabs'][] = ['text' => 'Rozsah', 'icon' => 'icon-road'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('note');
					$this->addColumnInput ('range');
					$this->addColumnInput ('addressPrefix');
					$this->addColumnInput ('addressGateway');
					$this->addColumnInput ('lan');
					$this->addColumnInput ('vlan');
					$this->addColumnInput ('serverMonitoring');
					$this->addColumnInput ('dhcpServerId');
					$this->addColumnInput ('dhcpPoolBegin');
					$this->addColumnInput ('dhcpPoolEnd');
					$this->addColumnInput ('nextPool');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
