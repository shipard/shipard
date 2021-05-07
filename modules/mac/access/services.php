<?php

namespace mac\access;

use E10\utils;

/**
 * Class ModuleServices
 * @package mac\access
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function accessTagsToHex()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [mac_access_tags] WHERE [tagType] = %i', 1);

		$rows = $this->app->db->query($q);
		foreach ($rows as $r)
		{
			$num = intval($r['keyValue']);
			if (!$num)
			{
				echo "ERROR: Invalid keyValue `{$r['keyValue']}` (#{$r['ndx']})\n";
				continue;
			}
			$hex = strtolower(base_convert($num, 10, 16));

			$this->app->db()->query('UPDATE [mac_access_tags] SET [keyValue] = %s', $hex, ' WHERE ndx = %i', $r['ndx']);

			echo $num." --> ".$hex."\n";
		}
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'access-tags-to-hex': return $this->accessTagsToHex();
		}

		parent::onCliAction($actionId);
	}
}


