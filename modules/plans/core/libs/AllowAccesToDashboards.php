<?php
namespace plans\core\libs;
use \Shipard\Base\BaseObject;


/**
 * class AllowAccesToDashboards
 */
class AllowAccesToDashboards extends BaseObject
{
	public function allowAccess (array $item)
	{
    $dashboards = ['plans_main' => 'addToDashboardHome'];
    $enabledCfgItem = $dashboards[$item['subclass'] ?? '--'] ?? '';
		$tablePlans = $this->app->table ('plans.core.plans');
		$usersPlans = $tablePlans->usersPlans ($enabledCfgItem);
    if (count($usersPlans))
      return TRUE;

    return FALSE;
	}
}
