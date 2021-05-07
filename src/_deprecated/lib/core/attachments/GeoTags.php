<?php

namespace lib\core\attachments;
use E10\Utility;


/**
 * Class GeoTags
 * @package lib\core\attachments
 */
class GeoTags extends Utility
{
	var $attNdx = 0;
	var $attTableId = NULL;
	var $attRecId = 0;
	var $scanAllCount = 20;
	var $attRecData = NULL;

	public function setAttNdx ($attNdx)
	{
		$this->attNdx = $attNdx;
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE ndx = %i', $this->attNdx);

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
		{
			$this->attRecData = $exist->toArray();
		}
		else
		{
			//echo "!!! Attachment `{$attNdx}` no found!\n";
		}
	}

	public function setAttTableDocument ($attTableId, $attRecId)
	{
		$this->attTableId = $attTableId;
		$this->attRecId = $attRecId;
	}

	function scanOne()
	{
		$gte = new \lib\geoTags\GeoTagsEngine($this->app());
		$gte->setCoordinates($this->attRecData['lat'], $this->attRecData['lon']);
		$gte->setSourceRec(1013, $this->attRecData['ndx']);
		$gte->run();

		$geoTagsState = 2;
		if (count($gte->addresses))
			$geoTagsState = 1;
		$this->db()->query ('UPDATE [e10_attachments_files] SET [geoTagsState] = %i', $geoTagsState);
	}

	function scanAll()
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND geoTagsState = %i', 0);
		array_push($q, ' AND locState = %i', 1);
		array_push($q, ' ORDER BY ndx DESC');
		array_push($q, ' LIMIT 0, %i', $this->scanAllCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->attRecData = $r->toArray();
			$this->scanOne();
		}
	}

	function scanTableDocument()
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [tableid] = %s', $this->attTableId);
		array_push($q, ' AND [recid] = %i', $this->attRecId);
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->attRecData = $r->toArray();
			$this->scanOne();
		}
	}

	public function run()
	{
		if ($this->attNdx)
			$this->scanOne();
		elseif ($this->attTableId !== NULL)
			$this->scanTableDocument();
		else
			$this->scanAll();
	}
}

