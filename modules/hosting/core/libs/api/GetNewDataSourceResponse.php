<?php

namespace hosting\core\libs\api;
use \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * @class GetNewDataSourceResponse
 */
class GetNewDataSourceResponse extends Utility
{
  var $result = ['success' => 0];

  var $incomingData;
  var $serverNdx = 0;
  var $serverRecData = NULL;
  var $infoNdx = 0;

  public function getDataSource()
  {
    $serverGID = $this->app()->testGetParam ('serverGID');
    if ($serverGID === '')
    {
      $this->result['error']['msg'][] = ['Missing `serverGID` URL param'];
      return;
    }

    $serverItem = NULL;
    $serverItem = $this->db()->query ('SELECT * FROM [hosting_core_servers] WHERE [docState] = 4000 AND [gid] = %s', $serverGID)->fetch ();

    if (!$serverItem)
    {
      $this->result['error']['msg'][] = ['Server `'.$serverGID.'` not found'];
      return;
    }

    if (!$serverItem['dsCreateDemo'] && !$serverItem['dsCreateProduction'])
    {
      $this->result['error']['msg'][] = ['Creating data sources for server `'.$serverGID.'` is not enabled'];
      return;
    }

    $data = [];

    $q[] = 'SELECT * FROM [hosting_core_dataSources]';
    array_push ($q, ' WHERE [docState] = %i', 1100);
    array_push ($q, ' AND [inProgress] = %i', 0);

    array_push ($q, ' AND (');
    if ($serverItem['dsCreateDemo'] > 0) // -- DEMO
    {
      array_push ($q, '(');
      array_push ($q, '[dsDemo] = %i', 1);
      if ($serverItem['dsCreateDemo'] == 1) // this server only
        array_push ($q, ' AND [server] = %i', $serverItem['ndx']);
      else // all servers
        array_push ($q, ' AND ([server] = %i', 0, ' OR [server] = %i)', $serverItem['ndx']);
      array_push ($q, ')');
    }
    if ($serverItem['dsCreateProduction'] > 0) // -- DEMO
    {
      if ($serverItem['dsCreateDemo'] > 0)
        array_push ($q, ' OR ');

      array_push ($q, '(');

      array_push ($q, '[dsDemo] = %i', 0);
      if ($serverItem['dsCreateProduction'] == 1) // this server only
        array_push ($q, ' AND [server] = %i', $serverItem['ndx']);
      else // all servers
        array_push ($q, ' AND ([server] = %i', 0, ' OR [server] = %i)', $serverItem['ndx']);
      array_push ($q, ')');
    }
    array_push ($q, ')');

    array_push ($q, ' ORDER BY [ndx] LIMIT 0, 1');

    $request = $this->db()->query ($q)->fetch ();

    if ($request)
    {
      $this->db()->query ('UPDATE [hosting_core_dataSources] SET [inProgress] = %i', $serverItem['ndx'], ' WHERE [ndx] = %i', $request['ndx']);
      $createRequest = json_decode($request['createRequest'], TRUE);
      if (!$createRequest)
      {
        $this->result['error']['msg'][] = ['Invalid request data for data source `'.$request['gid'].'`; please contact support'];
        return;
      }

      $data ['gid'] = $request['gid'];
      $data ['dsId1'] = $request['dsId1'];
      $data ['createRequest'] = $createRequest;

      $tablePersons = new \E10\Persons\TablePersons ($this->app());

      $adminDoc = $tablePersons->loadDocument ($request ['admin']);
      $data ['admin'] = [
        'fullName' => $adminDoc['recData']['fullName'],
        'complicatedName' => $adminDoc['recData']['complicatedName'],
        'beforeName' => $adminDoc['recData']['beforeName'],
        'firstName' => $adminDoc['recData']['firstName'],
        'middleName' => $adminDoc['recData']['middleName'],
        'lastName' => $adminDoc['recData']['lastName'],
        'fullName' => $adminDoc['recData']['fullName'],
        'afterName' => $adminDoc['recData']['afterName'],
        'login' => $adminDoc['recData']['login'],
      ];

      $this->result ['success'] = 1;
      $this->result ['request'] = $data;
      return;
    }
  }

  public function run()
  {
    $this->getDataSource();
  }
}
