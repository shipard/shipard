<?php

namespace swdev\icons\dc;

use e10\utils, e10\json;


/**
 * Class SetsIcon
 * @package swdev\icons\dc
 */
class AppIcon extends \e10\DocumentCard
{
	/** @var \swdev\icons\TableSets */
	var $tableSets;
	/** @var \swdev\icons\TableSetsVariants */
	var $tableSetsVariants;
	/** @var \swdev\icons\TableSetsIcons */
	var $tableSetsIcons;
	/** @var \swdev\icons\TableAppIconsMapping */
	var $tableAppIconsMapping;

	var $iconSetRecData = NULL;

	var $sets = [];
	var $requiredSets = [];

	var $mappingRows = NULL;

	var $svgFiles = [];

	function loadCore()
	{
		$this->tableSets = $this->app()->table('swdev.icons.sets');
		$this->tableSetsVariants = $this->app()->table('swdev.icons.setsVariants');
		$this->tableSetsIcons = $this->app()->table('swdev.icons.setsIcons');
		$this->tableAppIconsMapping = $this->app()->table('swdev.icons.appIconsMapping');

		// -- load sets
		$rows = $this->db()->query('SELECT * FROM [swdev_icons_sets] WHERE 1', ' AND [docState] = 4000');
		foreach ($rows as $r)
		{
			$this->sets[$r['ndx']] = $r->toArray();
			$this->sets[$r['ndx']]['variants'] = [];

			if ($r['useForAppIcons'] == 1)
				$this->requiredSets[] = $r['ndx'];
		}

		// -- load variants
		$rows = $this->db()->query('SELECT * FROM [swdev_icons_setsVariants] WHERE 1', ' AND [docState] = 4000');
		foreach ($rows as $r)
		{
			$this->sets[$r['iconsSet']]['variants'][$r['ndx']] = $r->toArray();

			//$this->svgFiles[$r['ndx']] = $this->iconSetRecData['pathSvgs'].$r['id'].'/'.$this->recData['id'].'.svg';
		}
	}

	function loadMapping()
	{
		$this->mappingRows = [];
		$usedSets = [];

		$q[] = 'SELECT mapping.*';
		array_push($q, ' FROM [swdev_icons_appIconsMapping] AS [mapping]');
		array_push($q, ' WHERE [mapping].appIcon = %i', $this->recData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->mappingRows[$r['ndx']] = $r->toArray();

			if ($r['setIcon'])
				$this->mappingRows[$r['ndx']]['setIconRecData'] = $this->tableSetsIcons->loadItem($r['setIcon']);

			$usedSets[] = $r['iconSet'];
		}

		$cntNew = 0;
		foreach ($this->requiredSets as $rsNdx)
		{
			if (in_array($rsNdx, $usedSets))
				continue;

			$newItem = [
				'appIcon' => $this->recData['ndx'],
				'iconSet' => $rsNdx, 'setIcon' => 0,
				'docState' => 1000, 'docStateMain' => 0
			];

			$newNdx = $this->tableAppIconsMapping->dbInsertRec($newItem);
			$this->tableAppIconsMapping->docsLog($newNdx);
			$cntNew++;
		}

		return ($cntNew === 0);
	}

	function load()
	{
		$this->loadCore();
		if (!$this->loadMapping())
			$this->loadMapping();

	}

	function iconImages ($setIconRecData)
	{
		$code = '';
		foreach ($this->sets[$setIconRecData['iconsSet']]['variants'] as $variantNdx => $variant)
		{
			$fullImgPath = $this->app()->dsRoot.'/'.'sc/'.
				$this->sets[$setIconRecData['iconsSet']]['pathSvgs'].
				$variant['id'].'/'.$setIconRecData['id'].'.svg';//$this->svgFiles[$variantNdx];

			$code .= "<div style='float: left; margin: 1ex; width: 20%; display: inline-block; border: 1px solid rgba(0,0,0,.15); background-color: #f0f0f0;'>";
			$code .= "<img style='width: 100%; padding: 1ex;' src='$fullImgPath'/>";
			$code .= "<div style='text-align: center;'>".utils::es($variant['id']).'</div>';
			$code .= '</div>';
		}

		return $code;
	}

	public function createContentBody ()
	{
		$t = [];
		$h =['#' => '#', 'set' => '_Sada', 'icon' => 'Ikona'];

		foreach ($this->mappingRows as $rowNdx => $r)
		{
			$item = [
				'set' => ['text' => $this->sets[$r['iconSet']]['shortName'], 'docAction' => 'edit', 'pk' => $rowNdx, 'table' => 'swdev.icons.appIconsMapping']
			];

			if (isset($r['setIconRecData']) && $r['setIconRecData'])
			{
				$item['icon'] = [];
				$item['icon'][] = [
					'text' => $r['setIconRecData']['id'],
					'suffix' => $r['setIconRecData']['name'],
					'class' => 'block'
				];

				$imagesCode = $this->iconImages($r['setIconRecData']);
				$item['icon'][] = ['code' => $imagesCode];
			}

			$docState = $this->tableAppIconsMapping->getDocumentState ($r);
			if ($docState)
			{
				$docStateClass = $this->tableAppIconsMapping->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');
				if ($docStateClass)
					$item['_options']['class'] = 'e10-ds-block '.$docStateClass;
			}

			$t[] = $item;
		}

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'table' => $t, 'header' => $h]);
	}

	public function createContent ()
	{
		$this->load();
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
