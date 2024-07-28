<?php

namespace mac\admin\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils;


/**
 * Class TokensEngine
 */
class TokensEngine extends Utility
{
  var $lans = [];
  var $tokens = [];

  var $tokensValidityHours = 6;
  var $tokensCnt = 5;

  protected function loadLans()
  {
    if (count($this->lans))
      return;

		$rows = $this->app()->db->query ('SELECT * FROM [mac_lan_lans] WHERE [docState] != 9800 ORDER BY [order], [fullName]');
		foreach ($rows as $r)
		{
      $this->lans[$r['ndx']] = $r->toArray();
		}
  }

  public function createNewTokens ()
  {
    foreach ($this->lans as $lanNdx => $lanCfg)
    {
      $cntValidTokens = isset($this->tokens[$lanNdx]) ? count($this->tokens[$lanNdx]) : 0;
      $cntTokens = $this->tokensCnt - $cntValidTokens;
      while ($cntTokens > 0)
      {
        $hoursExpire = ($this->tokensValidityHours * $this->tokensCnt) - ($cntTokens - 1) * $this->tokensValidityHours;
        $dateExpire = new \DateTime();

        $dateExpire->add (new \DateInterval('PT'.$hoursExpire.'H'));
        $newToken = [
          'lan' => $lanNdx,
          'token' => Utils::createToken(48),
          'expireAfter' => $dateExpire,
        ];
        $this->db()->query('INSERT INTO [mac_admin_tokens]', $newToken);

        $cntTokens--;
      }
    }
  }

  protected function expireExpiredTokens()
  {
    $now = new \DateTime();
    $this->db()->query('UPDATE [mac_admin_tokens] SET [expired] = 1 WHERE [expireAfter] < %t', $now,
                        ' AND [expired] = %i', 0);
  }

  protected function loadValidTokens()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [mac_admin_tokens]');
    array_push($q, ' WHERE [expired] = %i', 0);
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->tokens[$r['lan']][] = $r['token'];
    }
  }

  public function loadLANValidTokens($lanNdx)
  {
    $tokens = [];

    $q = [];
    array_push($q, 'SELECT * FROM [mac_admin_tokens]');
    array_push($q, ' WHERE [expired] = %i', 0);
    array_push($q, ' AND [lan] = %i', $lanNdx);
    array_push($q, ' ORDER BY expireAfter DESC');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $tokens[] = $r['token'];
    }

    return $tokens;
  }

  public function run()
  {
    $this->loadLans();
    $this->expireExpiredTokens();
    $this->loadValidTokens();
    $this->createNewTokens();
  }
}
