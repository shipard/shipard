<?php

namespace services\sw\libs;


use \E10\TableView, \e10\utils, \e10\json;


/**
 * Class ViewSW
 * @package services\sw\libs
 */
class ViewSW extends TableView
{
	var $swClass;
	var $osFamily;
	var $osEdition;
	var $lifeCycle;

	var $categories = [];

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->swClass = $this->app()->cfgItem ('mac.swcore.swClass');
		$this->osFamily = $this->app()->cfgItem ('mac.swcore.osFamily');
		$this->osEdition = $this->app()->cfgItem ('mac.swcore.osEdition');
		$this->lifeCycle = $this->app()->cfgItem ('mac.swcore.lifeCycle');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['suid'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];

		$swc = $this->swClass[$item['swClass']];
		$props[] = ['text' => $swc['fn'], 'class' => 'label label-default'];

		if ($item['swClass'] === 1)
		{
			$osf = $this->osFamily[$item['osFamily']];
			$props[] = ['text' => $osf['sn'], 'icon' => $osf['icon'], 'class' => 'label label-default'];

			$ose = $this->osEdition[$item['osEdition']];
			$props[] = ['text' => $ose['sn'], 'x-icon' => '', 'class' => 'label label-default'];

		}

		$listItem['t2'] = $props;


		if ($item['lifeCycle'] !== 1)
		{
			$lc = $this->lifeCycle[$item['lifeCycle']];
			$listItem['i2'] = ['text' => $lc['sn'], 'icon' => $lc['icon'], 'class' => 'label label-warning'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [sw].*';
		array_push ($q, ' FROM [mac_sw_sw] AS [sw]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [sw].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [sw].[suid] LIKE %s', '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swIds] WHERE [sw].[ndx] = [mac_sw_swIds].[sw] AND [id] LIKE %s)", '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swNames] WHERE [sw].[ndx] = [mac_sw_swNames].[sw] AND [name] LIKE %s)", '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swVersions] WHERE [sw].[ndx] = [mac_sw_swVersions].[sw] AND [versionNumber] LIKE %s)", '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'sw.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		// -- sections
		$q[] = 'SELECT docLinks.*, [cats].shortName, [cats].icon';
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [mac_sw_categories] AS [cats] ON docLinks.dstRecId = [cats].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories');
		array_push($q, ' AND docLinks.linkId = %s', 'mac-sw-swCats', 'AND srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = [
				'text' => $r['shortName'],
				'icon' => $r['icon'] === '' ? 'icon-folder' : $r['icon'],
				'class' => 'label label-default'
			];
			$this->categories[$r['srcRecId']][] = $l;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->categories [$item ['pk']]))
		{
			$item['t2'] = array_merge($item['t2'], $this->categories [$item ['pk']]);
		}
	}
}
