<?php
namespace hosting\core\libs\api;

use \Shipard\Base\Utility;


/**
 * @class GetHelpdeskNotifications
 * https://dsid.shipard.app/api/objects/call/hosting-helpdesk-notifications
 */
class GetHelpdeskNotifications extends Utility
{
  var $result = ['success' => 0];

  public function getNotifications()
  {
    $dsId = $this->app()->testGetParam ('dsId');
    if ($dsId === '')
    {
      $this->result['error']['msg'][] = ['Missing `dsId` URL param'];
      return;
    }

    $existedDataSource = $this->db()->query('SELECT ndx FROM [hosting_core_dataSources] WHERE gid = %s', $dsId)->fetch();
    if (!$existedDataSource)
    {
      $this->result['error']['msg'][] = ['Invalid `dsId` value'];
      return;
    }
    $dsNdx = $existedDataSource['ndx'];


    $userLoginHash = $this->app()->testGetParam ('userLoginHash');
    if ($userLoginHash === '')
    {
      $this->result['error']['msg'][] = ['Missing `userLoginHash` URL param'];
      return;
    }

    $existedPerson = $this->db()->query('SELECT ndx FROM [e10_persons_persons] WHERE loginHash = %s', $userLoginHash)->fetch();
    if (!$existedPerson)
    {
      $this->result['error']['msg'][] = ['Invalid `userLoginHash` value'];
      return;
    }
    $userNdx = $existedPerson['ndx'];

    $q = [];
    $q[] = 'SELECT COUNT(*) AS [cnt] ';
    array_push($q, ' FROM e10_base_notifications AS ntf');
    array_push($q, ' LEFT JOIN helpdesk_core_tickets AS tickets ON ntf.recIdMain = tickets.ndx');
    array_push($q, ' WHERE tableId = %s', 'helpdesk.core.tickets');
    array_push($q, ' AND tickets.dataSource = %i', $dsNdx);
    array_push($q, ' AND [state] = 0 AND ntf.personDest = %s', $userNdx);
    $hdc = $this->db()->query($q)->fetch();

    $badges = [];

    $badges['ntf-badge-hhdsk-total'] = intval($hdc['cnt'] ?? 0);

    $this->result ['success'] = 1;
    $this->result ['badges'] = $badges;
  }

  public function run()
  {
    $this->getNotifications();
  }
}


