<?php
namespace helpdesk\core\libs;
use \Shipard\Base\BaseObject;


/**
 * class AllowAccesToDashboards
 */
class AllowAccesToDashboards extends BaseObject
{
	public function allowAccess (array $item)
	{
		$tableSections = $this->app->table ('helpdesk.core.sections');
    $usersSections = $tableSections->usersSections ();

    if (count($usersSections))
      return TRUE;

    return FALSE;
	}
}
