<?php

namespace mac\lan;


use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Str;


/**
 * class TableLansAddrRanges6
 */
class TableLansAddrRanges6 extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.lansAddrRanges6', 'mac_lan_lansAddrRanges6', 'Rozsahy adres ipv6');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$recData['fullAddress'] = Str::expandIPv6($recData['prefix']);
  }

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => ['text' => $recData ['prefix'], 'suffix' => '/'.$recData['prefixLen'], 'class' => '']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['note']];

		return $hdr;
	}
}


/**
 * class ViewLansAddrRanges6
 */
class ViewLansAddrRanges6 extends TableView
{
	var $activeLan = 0;

	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		$this->activeLan = intval($this->queryParam ('lan'));

		parent::init();
		$this->setMainQueries ();

		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this, $this->activeLan);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		if ($item['parentRange'])
			$listItem ['level'] = 1;

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = ['text' => $item['prefix'], 'suffix' => '/'.$item['prefixLen']];

		$listItem ['t2'] = [];
		if ($item['rangeType'] === 0)
		{
			if ($item['vlanId'])
				$listItem ['t2'][] = ['text' => $item['vlanId'], 'icon' => 'tables/mac.lan.vlans', 'class' => ''];
			else
				$listItem ['t2'][] = ['text' => '-- bez vlan ---', 'icon' => 'tables/mac.lan.vlans', 'class' => ''];
		}
		elseif ($item['rangeType'] === 1)
		{
			$listItem ['t2'][] = ['text' => $item['defaultGW'], 'prefix' => 'gw', 'class' => 'label label-default'];
			$listItem ['t2'][] = ['text' => $item['wanIP'], 'prefix' => 'ip', 'class' => 'label label-default'];
		}

		$listItem ['i2'] = [];
		if ($item['lanShortName'])
			$listItem ['i2'][] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap', 'class' => ''];
		else
			$listItem ['i2'][] = ['text' => '!!!', 'icon' => 'system/iconSitemap', 'class' => 'label label-danger'];

		if ($item['note'] !== '')
			$listItem['i1'] = ['text' => $item['note'], 'class' => 'id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ranges.*, lans.shortName as lanShortName, vlans.id as vlanId';
		array_push ($q, ' FROM [mac_lan_lansAddrRanges6] AS ranges');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON ranges.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON ranges.vlan = vlans.ndx');
		array_push ($q, ' WHERE 1');

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [ranges].[lan] = %i', $lan);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
					'ranges.[prefix] LIKE %s', '%'.$fts.'%',
					'OR ranges.[note] LIKE %s', '%'.$fts.'%',
					'OR vlans.[fullName] LIKE %s', '%'.$fts.'%',
			')');
		}

		// -- aktuální
		$this->queryMain ($q, 'ranges.', ['[fullAddress]', '[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailLansAddrRange6
 */
class ViewDetailLansAddrRange6 extends TableViewDetail
{
}


/**
 * class FormLanAddrRange6
 */
class FormLanAddrRange6 extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('rangeType');
					$this->addSeparator(self::coH4);
					$this->openRow();
					$this->addColumnInput ('prefix');
					$this->addColumnInput ('prefixLen');
					$this->closeRow();

					if ($this->recData['rangeType'] == 1)
					{
						$this->addColumnInput ('wanIP');
						$this->addColumnInput ('defaultGW');
						$this->addColumnInput ('network');
					}

					$this->addColumnInput ('note');

					$this->addColumnInput ('lan');
					if ($this->recData['rangeType'] == 0)
					{
						$this->addColumnInput ('vlan');
					}
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('parentRange');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
