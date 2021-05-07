<?php

namespace swdev\icons\libs;

use \e10\Utility, \e10\json;


/**
 * Class ImportCore
 * @package swdev\icons\libs
 */
class ImportCore extends Utility
{
	var $iconsMetadataFileName = '';
	var $iconsMetadata = NULL;

	var $thisIconsSetId = '';
	var $setNdx = 0;
	var $iconsSets = [];

	/** @var \swdev\icons\TableSets */
	var $tableSets = NULL;
	/** @var \swdev\icons\TableSetsIcons */
	var $tableSetsIcons = NULL;
	/** @var \e10\base\TableClsfItems */
	var $tableClsfItems = NULL;

	var $iconNdx = 0;

	public function init ()
	{
		$this->tableSets = $this->app()->table('swdev.icons.sets');
		$this->tableSetsIcons = $this->app()->table('swdev.icons.setsIcons');
		$this->tableClsfItems = $this->app()->table('e10.base.clsfitems');
	}

	function loadIcons()
	{
		if ($this->iconsMetadataFileName === '')
			return;

		$this->iconsMetadata = $this->loadCfgFile($this->iconsMetadataFileName);
	}

	function loadSets()
	{
		$rows = $this->db()->query('SELECT * FROM [swdev_icons_sets] WHERE docState != %i', 9800);
		foreach ($rows as $r)
		{
			$this->iconsSets[$r['id']] = $r->toArray();
		}

		if (!isset($this->iconsSets[$this->thisIconsSetId]))
		{
			echo "ERROR: unknown icons set `$this->thisIconsSetId`\n";
			return;
		}

		$this->setNdx = $this->iconsSets[$this->thisIconsSetId]['ndx'];
	}

	public function import()
	{
		$this->init();
		$this->loadIcons();
		$this->loadSets();

		if (!$this->iconsMetadata)
			return;

		foreach ($this->icons() as $iconId => $icon)
		{
			if (!isset($icon['id']))
				$icon['id'] = $iconId;

			$this->importOne($icon);
		}
	}

	function icons()
	{
		return [];
	}

	function importOne($icon)
	{
	}

	function saveIcon($icon)
	{
		$exist = $this->db()->query('SELECT * FROM swdev_icons_setsIcons WHERE [iconsSet] = %i', $this->setNdx,
			' AND [id] = %s', $icon['id'])->fetch();

		if (!$exist)
		{
			$item = [
				'iconsSet' => $this->setNdx, 'id' => $icon['id'], 'name' => $icon['name'],
				'docState' => 4000, 'docStateMain' => 2
			];
			$this->iconNdx = $this->tableSetsIcons->dbInsertRec($item);
			if ($this->iconNdx < 10000)
			{
				$this->db()->query('UPDATE [swdev_icons_setsIcons] SET ndx = %i', 10000+$this->iconNdx, ' WHERE ndx = %i', $this->iconNdx);
				$this->iconNdx += 10000;
			}

			$this->tableSetsIcons->docsLog($this->iconNdx);
		}
		else
		{
			$this->iconNdx = $exist['ndx'];
			$update = [];
			if ($exist['name'] !== $icon['name'])
				$update['name'] = $icon['name'];

			if (count($update))
			{
				$update['ndx'] = $this->iconNdx;
				$this->tableSetsIcons->dbUpdateRec($update);
			}
		}

		if (isset($icon['keywords']) && count($icon['keywords']))
			$this->saveLabels($icon['keywords']);
	}

	function saveLabels($keywords)
	{
		foreach ($keywords as $keyword)
		{
			$kwNdx = $this->label($keyword);

			$exist = $this->db()->query('SELECT * FROM [e10_base_clsf] WHERE ',
				' [clsfItem] = %i', $kwNdx, ' AND [group] = %s', 'swDevSetsIcons',
				' AND [tableid] = %s', 'swdev.icons.setsIcons',
				' AND [recid] = %i', $this->iconNdx)->fetch();
			if ($exist)
				continue;

			$newItem = [
				'clsfItem' => $kwNdx, 'group' => 'swDevSetsIcons',
				'tableid' => 'swdev.icons.setsIcons', 'recid' => $this->iconNdx,
			];
			$this->db()->query('INSERT INTO [e10_base_clsf] ', $newItem);
		}
	}

	function label($keyword)
	{
		$exist = $this->db()->query('SELECT * FROM [e10_base_clsfitems] WHERE [group] = %s', 'swDevSetsIcons',
			' AND [id] = %s', $keyword)->fetch();

		if ($exist)
			return $exist['ndx'];

		$newLabel = [
			'fullName' => $keyword, 'id' => $keyword, 'group' => 'swDevSetsIcons',
			'colorbg' => '#E3E4FA', 'colorfg' => '#000',
			'docState' => 4000, 'docStateMain' => 2,
		];

		$labelNdx = $this->tableClsfItems->dbInsertRec($newLabel);
		$this->tableClsfItems->docsLog($labelNdx);

		return $labelNdx;
	}
}
