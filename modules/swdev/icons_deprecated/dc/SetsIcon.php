<?php

namespace swdev\icons\dc;

use e10\utils, e10\json;


/**
 * Class SetsIcon
 * @package swdev\icons\dc
 */
class SetsIcon extends \e10\DocumentCard
{
	/** @var \swdev\icons\TableSets */
	var $tableSets;
	/** @var \swdev\icons\TableSetsVariants */
	var $tableSetsVariants;

	var $iconSetRecData = NULL;
	var $setsVariants = [];
	var $svgFiles = [];

	function load()
	{
		$this->tableSets = $this->app()->table('swdev.icons.sets');
		$this->tableSetsVariants = $this->app()->table('swdev.icons.setsVariants');

		$this->iconSetRecData = $this->tableSets->loadItem($this->recData['iconsSet']);

		// -- load variants
		$rows = $this->db()->query('SELECT * FROM [swdev_icons_setsVariants] WHERE [iconsSet] = %i', $this->recData['iconsSet'],
			' AND [docState] = 4000');
		foreach ($rows as $r)
		{
			$this->setsVariants[$r['ndx']] = $r->toArray();

			$this->svgFiles[$r['ndx']] = $this->iconSetRecData['pathSvgs'].$r['id'].'/'.$this->recData['id'].'.svg';
		}
	}

	public function createContentBody ()
	{
		$code = '';

		foreach ($this->setsVariants as $variantNdx => $variant)
		{
			$fullImgPath = $this->app()->dsRoot.'/'.'sc/'.$this->svgFiles[$variantNdx];
			//$code .= $fullImgPath.'<br>';
			$code .= "<div style='float: left; margin: 1ex; width: 20%; display: inline-block; border: 1px solid rgba(0,0,0,.15); background-color: #fafafa; box-shadow: 1px 1px 2px rgba(0,0,0,.2);'>";
			$code .= "<img style='width: 100%; padding: 1ex;' src='$fullImgPath'/>";
			$code .= "<div style='text-align: center;'>".utils::es($variant['id']).'</div>';
			$code .= '</div>';
		}

		$this->addContent('body', ['type' => 'line', 'line' => ['code' => $code]]);
	}

	public function createContent ()
	{
		$this->load();
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
