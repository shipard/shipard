<?php

namespace mac\admin\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils;


/**
 * Class LanUsersEngine
 */
class LanUsersEngine extends Utility
{
  var $userTypesCfgs;
  var $tokenizedUserTypes;

  var $lans = [];
  var $users = [];

  var $tokensValidityHours = 6;
  var $tokensCnt = 5;

  protected function loadLans()
  {
    if (count($this->lans))
      return;

    $this->userTypesCfgs = $this->app()->cfgItem('mac.admin.lanUsersTypes', []);
    foreach ($this->userTypesCfgs as $utId => $utCfg)
    {
      if (!($utCfg['tokenize'] ?? 0))
        continue;
      $this->tokenizedUserTypes[] = $utId;
    }

		$rows = $this->app()->db->query ('SELECT * FROM [mac_lan_lans] WHERE [docState] != 9800 ORDER BY [order], [fullName]');
		foreach ($rows as $r)
		{
      $this->lans[$r['ndx']] = $r->toArray();
		}
  }

  public function createNewUsers ()
  {
    foreach ($this->userTypesCfgs as $utId => $utCfg)
    {
      if (!($utCfg['tokenize'] ?? 0))
        continue;
      $tokensCnt = $utCfg['cnt'] ?? 5;
      $tokensValidityHours = $utCfg['hours'] ?? 6;
      foreach ($this->lans as $lanNdx => $lanCfg)
      {
        $cntValidUsers = isset($this->users[$lanNdx][$utId]) ? count($this->users[$lanNdx][$utId]) : 0;
        $cntUsers = $tokensCnt - $cntValidUsers;
        while ($cntUsers > 0)
        {
          $hoursExpire = ($tokensValidityHours * $tokensCnt) - ($cntUsers - 1) * $tokensValidityHours;
          $dateExpire = new \DateTime();

          $dateExpire->add (new \DateInterval('PT'.$hoursExpire.'H'));
          $newUser = [
            'name' => $utCfg['name'].' User',
            'userType' => $utId,
            'login' => $utId.'-'.Utils::createToken(5, FALSE, TRUE),
            'password' => Utils::createToken(mt_rand(16, 20), TRUE),
            'lan' => $lanNdx,
            'expireAfter' => $dateExpire,
            'docState' => 4000, 'docStateMain' => 2,
          ];
          $this->db()->query('INSERT INTO [mac_admin_lanUsers]', $newUser);

          $cntUsers--;
        }
      }
    }
  }

  protected function expireExpiredUsers()
  {
    $now = new \DateTime();
    $this->db()->query('UPDATE [mac_admin_lanUsers] SET [expired] = 1, [docState] = 9000, [docStateMain] = 5',
                        ' WHERE [expireAfter] < %t', $now,
                        ' AND userType IN %in', $this->tokenizedUserTypes,
                        ' AND [expired] = %i', 0);
  }

  protected function loadValidUsers()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [mac_admin_lanUsers]');
    array_push($q, ' WHERE [expired] = %i', 0);
    array_push($q, ' AND [userType] IN %in', $this->tokenizedUserTypes);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->users[$r['lan']][$r['userType']][] = $r['ndx'];
    }
  }

  public function checkMandatoryUsers ()
  {
    foreach ($this->userTypesCfgs as $utId => $utCfg)
    {
      if (!($utCfg['mandatory'] ?? 0))
        continue;
      foreach ($this->lans as $lanNdx => $lanCfg)
      {
        $exist = $this->db()->query('SELECT * FROM [mac_admin_lanUsers] WHERE [userType] = %s', $utId,
                    ' AND [lan] = %i', $lanNdx)->fetch();

        if (!$exist)
        {
          $newUser = [
            'name' => $utCfg['name'].' User',
            'userType' => $utId,
            'login' => $utId.'-'.Utils::createToken(5, FALSE, TRUE),
            'password' => Utils::createToken(mt_rand(16, 20), TRUE),
            'lan' => $lanNdx,
            'docState' => 4000, 'docStateMain' => 2,
          ];
          $this->db()->query('INSERT INTO [mac_admin_lanUsers]', $newUser);
        }
      }
    }
  }

  public function run()
  {
    $this->loadLans();
    $this->checkMandatoryUsers();
    $this->expireExpiredUsers();
    $this->loadValidUsers();
    $this->createNewUsers();
  }
}
