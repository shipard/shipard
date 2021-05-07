<?php

namespace mac\sw;
use E10\str;
use e10\utils;


/**
 * Class ModuleServices
 * @package mac\sw
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkUids();
	}

	function checkUids()
	{
		// -- sw
		$rows = $this->app->db()->query('SELECT ndx FROM [mac_sw_sw] WHERE [suid] = %s', '');
		foreach ($rows as $r)
		{
			$newUid = utils::createRecId($r->toArray(), '!06Z');
			$this->app->db()->query('UPDATE [mac_sw_sw] SET [suid] = %s', $newUid, ' WHERE [ndx] = %i', $r['ndx']);
		}

		// -- sw versions: suid
		$rows = $this->app->db()->query('SELECT ndx FROM [mac_sw_swVersions] WHERE [suid] = %s', '');
		foreach ($rows as $r)
		{
			$newUid = utils::createRecId($r->toArray(), '!10z');
			$this->app->db()->query('UPDATE [mac_sw_swVersions] SET [suid] = %s', $newUid, ' WHERE [ndx] = %i', $r['ndx']);
		}

		// -- sw versions: versionOrderId
		$rows = $this->app->db()->query('SELECT ndx, versionNumber FROM [mac_sw_swVersions] WHERE [versionOrderId] = %s', '');
		foreach ($rows as $r)
		{
			$newVOID = str::upToLen(preg_replace_callback ('/(\\d+)/', function($match){return (($match[0] + 100000));}, $r['versionNumber']), 100);
			$this->app->db()->query('UPDATE [mac_sw_swVersions] SET [versionOrderId] = %s', $newVOID, ' WHERE [ndx] = %i', $r['ndx']);
		}

		// -- publishers
		$rows = $this->app->db()->query('SELECT ndx FROM [mac_sw_publishers] WHERE [suid] = %s', '');
		foreach ($rows as $r)
		{
			$newUid = utils::createRecId($r->toArray(), '!05Z');
			$this->app->db()->query('UPDATE [mac_sw_publishers] SET [suid] = %s', $newUid, ' WHERE [ndx] = %i', $r['ndx']);
		}

		// -- categories
		$rows = $this->app->db()->query('SELECT ndx FROM [mac_sw_categories] WHERE [suid] = %s', '');
		foreach ($rows as $r)
		{
			$newUid = utils::createRecId($r->toArray(), '!05z');
			$this->app->db()->query('UPDATE [mac_sw_categories] SET [suid] = %s', $newUid, ' WHERE [ndx] = %i', $r['ndx']);
		}
	}
}
