<?php

namespace e10\ui;
use \e10\ui\TableUIs;

/**
 * class ModuleServices
 */
class ModuleServices extends \e10\cli\ModuleServices
{
  public function onAppUpgrade ()
	{
    $this->checkMainRoles();
  }

  protected function checkMainRoles()
  {
    $q = [];
    array_push($q, 'SELECT uis.*');
    array_push($q, ' FROM [e10_ui_uis] AS uis');
    array_push($q, ' WHERE 1');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $appCfg = NULL;

      if ($r['uiType'] === TableUIs::uitSystemApp)
      {
        $appCfg = $this->app()->cfgItem('apps.'.$r['appType'], NULL);
      }

      if (!$appCfg || !isset($appCfg['mainRoles']))
        continue;

      foreach ($appCfg['mainRoles'] as $mrId => $mrCfg)
      {
        $exist = $this->db()->query('SELECT * FROM [e10_users_roles] WHERE systemId = %s', $mrId)->fetch();
        if ($exist)
        {

        }
        else
        {
          $newRole = [
            'fullName' => $mrCfg['fn'],
            'systemRole' => 1,
            'systemId' => $mrId,
            'ui' => $r['ndx'],
            'docState' => 4000, 'docStateMain' => 2,
          ];

          $this->db()->query('INSERT INTO [e10_users_roles] ', $newRole);
        }
      }
    }
  }
}
