<?php

namespace hosting\core\libs\api;
use \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * @class ConfirmNewDataSourceResponse
 */
class ConfirmNewDataSourceResponse extends Utility
{
  var $result = ['success' => 0];

  public function confirmDataSource()
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

    $dsGID = $this->app()->testGetParam ('dsGID');
    if ($dsGID === '')
    {
      $this->result['error']['msg'][] = ['Missing `dsGID` URL param'];
      return;
    }
		$dsItem = $this->db()->query ('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsGID)->fetch ();
    if (!$dsItem)
    {
      $this->result['error']['msg'][] = ['Data source `'.$dsGID.'` not found'];
      return;
    }

    // -- DO IT
    $urlApp = 'https://' . $serverItem['fqdn'].'/'.$dsItem['gid'].'/';

    // -- set data source state and server
    $this->db()->query ('UPDATE [hosting_core_dataSources] SET [inProgress] = 0, [docState] = 4000, [docStateMain] = 2, [condition] = 1, [server] = %i, ', $serverItem['ndx'],
            '[urlApp] = %s ', $urlApp, ' WHERE [ndx] = %i', $dsItem ['ndx']);

    // -- check admin connect
    $userdsRecData = $this->db()->query ('SELECT * FROM [hosting_core_dsUsers] ', 
      'WHERE [user] = %i ', $dsItem['admin'], 'AND [dataSource] = %i', $dsItem['ndx'])->fetch();
    if (!$userdsRecData)
    {
      $newLinkedDataSource = [
        'user' => $dsItem['admin'], 'dataSource' => $dsItem['ndx'],
        'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2
      ];
      $this->db()->query ('INSERT INTO [hosting_core_dsUsers]', $newLinkedDataSource);
    }

    // -- docsLog
    /** @var \hosting\core\TableDataSources $tableDataSources */
    $tableDataSources = $this->app()->table('hosting.core.dataSources');
    $tableDataSources->docsLog($dsItem['ndx']);

    // -- done
    $this->result ['success'] = 1;
  }

  public function run()
  {
    $this->confirmDataSource();
  }
}