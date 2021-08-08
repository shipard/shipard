<?php

namespace swdev\icons\libs;

use \e10\Utility, \e10\json;


/**
 * Class IconsCfgGenerator
 * @package swdev\icons\libs
 */
class IconsCfgGenerator extends Utility
{
	var $appIconsGroups = [];
	var $iconsMapping = [];

	var $appIcons = [];
	var $text = '';

	function load()
	{
		$this->loadAppIconsGroups();
		$this->loadAppIcons();
		$this->loadMapping();
	}

	function loadAppIconsGroups()
	{
		$q = [];
		array_push($q, 'SELECT aig.*');

		array_push($q, ' FROM [swdev_icons_appIconsGroups] AS aig');
		//array_push($q, ' LEFT JOIN [swdev_icons_appIconsGroups] AS aiGroups ON appIcons.appIconGroup');
		array_push($q, ' WHERE 1');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = [
				'id' => $r['id'],
				//'icons' => [],
			];

			$this->appIconsGroups[$r['ndx']] = $newItem;
		}
	}

	function loadMapping()
	{
		$q[] = 'SELECT mapping.*, setIcons.id AS setIconId';
		array_push($q, ' FROM [swdev_icons_appIconsMapping] AS [mapping]');
		array_push($q, ' LEFT JOIN [swdev_icons_setsIcons] AS setIcons ON mapping.setIcon = setIcons.ndx');

		//array_push($q, ' WHERE [mapping].appIcon = %i', $this->recData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->iconsMapping[$r['appIcon']][$r['iconSet']] = [

				'iconId' => $r['setIconId'],
			];


		}


		/*
		{"id": "appIcon", "name": "Ikona aplikace", "type": "int", "reference": "swdev.icons.appIcons"},

		{"id": "iconSet", "name": "Sada ikon", "type": "int", "reference": "swdev.icons.sets"},
		{"id": "setIcon", "name": "Ikona sady", "type": "int", "reference": "swdev.icons.setsIcons", "comboViewer":  "combo"},

		 */
	}


	function loadAppIcons()
	{
		$q = [];
		array_push($q, 'SELECT appIcons.*');

		array_push($q, ' FROM [swdev_icons_appIcons] AS appIcons');
		array_push($q, ' LEFT JOIN [swdev_icons_appIconsGroups] AS aiGroups ON appIcons.appIconGroup');
		array_push($q, ' WHERE 1');
		array_push($q, ' ORDER BY aiGroups.id, appIcons.id');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = [
				'id' => $r['id'],
				'g' => $r['appIconGroup'],
			];

			$this->appIcons[$r['appIconGroup']][$r['ndx']] = $newItem;
		}
	}

	function createText()
	{
		$this->text = '#nazdar!'."\n\n";
		$this->text .= '#nazdar2!'."\n\n";

		$cnt = 0;

		$sets = [1, 2];

		$this->text .= 'var $ICONS = ['."\n\n";

		foreach ($sets as $setNdx)
		{
			$this->text .= "\t".$setNdx." => [\n";
			foreach ($this->appIcons as $groupNdx => $groupIcons)
			{
				foreach ($groupIcons as $iconNdx => $iconDef)
				{

					$iconMapping = $this->iconsMapping[$iconNdx];
					$iconCfg = $iconMapping[1];


					$this->text .= "\t\t'" . $iconDef['id'] . "' => '" . $iconCfg['iconId'] . "',\n";


					$cnt++;
				}
			}
			$this->text .= "\t],\n";
		}

		$this->text .= "];\n";

		$this->text .= "\n\n/* {$cnt} icons */\n\n";
	}

	public function run ()
	{
		$this->load();

		$this->createText();
	}
}