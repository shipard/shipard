<?php

namespace integrations\hooks\in\services;

use \e10\Utility, e10\utils, e10\json;


/**
 * Class RunHooks
 * @package integrations\hooks\in\services
 */
class RunHooks extends Utility
{
	function doOneHook ($h)
	{
		$hookRecData = $this->app()->loadItem($h['hook'], 'integrations.hooks.in.hooks');
		$hookTypeCfg = $this->app()->cfgItem('integration.hooks.in.types.'.$hookRecData['hookType'], NULL);
		if (!$hookTypeCfg)
		{
			error_log ("Invalid hook type `{$hookRecData['hookType']}`");
			return;
		}

		if (!isset($hookTypeCfg['classId']))
		{
			error_log ("Undefined hook classId in `{$hookRecData['hookType']}`");
			return;
		}

		if ($hookRecData['runAsUser'])
		{
			$this->app()->authenticator->runAsUser($hookRecData['runAsUser']);
		}

		$classId = $hookTypeCfg['classId'];

		$o = $this->app()->createObject($classId);
		if (!$o)
			return;

		$o->setRecData($h);
		$o->run();
	}

	function runAllHooks($hookDataNdx = 0)
	{
		$q[] = 'SELECT * FROM [integrations_hooks_in_data]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [hookState] = %i', 0);

		if ($hookDataNdx)
			array_push ($q, ' AND [ndx] = %i', $hookDataNdx);

		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doOneHook($r->toArray());
		}
	}

	public function run($hookDataNdx = 0)
	{
		$this->runAllHooks($hookDataNdx);
	}
}