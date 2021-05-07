<?php

namespace lib\geoTags;

use \e10\Utility;


/**
 * Class GeoTagsEngine
 * @package lib\geoTags
 */
class GeoTagsLabels extends Utility
{
	/** @var \e10\DbTable */
	var $srcTable;
	var $srcPks = NULL;

	var $labels = [];
	var $dstTables = [];

	function load()
	{
		if (!$this->srcPks || !count($this->srcPks))
			return;

		$q[] = 'SELECT * FROM [e10_base_geoTags]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [srcTable] = %i', $this->srcTable->ndx, ' AND [srcRec] IN %in', $this->srcPks);
		$rows = $this->db()->query($q);
		error_log (\dibi::$sql);
		foreach ($rows as $r)
		{
			$dstTableNdx = $r['dstTable'];
			$dstRecNdx = $r['dstRec'];
			$srcRecNdx = $r['srcRec'];

			if (!isset($this->dstTables[$dstTableNdx]))
				$this->dstTables[$dstTableNdx] = $this->app()->tableByNdx($dstTableNdx);

			$dstTable = $this->dstTables[$dstTableNdx];
			if (!$dstTable)
				continue;

			$dstRecData = $dstTable->loadItem ($dstRecNdx);
			$recInfo = $dstTable->getRecordInfo ($dstRecData);

			$lbl = [
				'text' => $recInfo['docID'], 'icon' => $dstTable->tableIcon ($dstRecData), 'class' => 'label label-default',
				'docAction' => 'edit', 'table' => $dstTable->tableId(), 'pk' => $dstRecNdx
			];
			if (isset($recInfo['title']))
				$lbl['title'] = $recInfo['title'];

			$this->labels[$srcRecNdx][] = $lbl;
		}
	}

	public function recLabels($srcPk)
	{
		if (isset($this->labels[$srcPk]))
			return $this->labels[$srcPk];

		return NULL;
	}

	public function setSrcRecs($table, $pks)
	{
		$this->srcTable = $table;
		$this->srcPks = $pks;
	}


	public function run()
	{
		$this->load();
	}
}