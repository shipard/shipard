<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \Shipard\Base\Utility;

/**
 * @class DSCreator
 */
class DSCreator extends Utility
{
  var int $debug = 0;
  var string $forceUrl = '';

  var $cfgServer = NULL;
  var $hostingDomain = '';
  var $newDataSourceRequest = NULL;


  function logMsg ($msg)
  {
    $now = new \DateTime();
    $lfn = '/var/lib/shipard/tmp/shpd-hosting-create-ds-'.$now->format('Y-m-d').'.log';
    file_put_contents($lfn, $msg."\n", FILE_APPEND);
    if ($this->debug)
      echo $msg."\n";
  }

  function machineDeviceId ()
  {
    $deviceId = file_get_contents('/etc/shipard/device-id.json');
    return $deviceId;
  }

  function init()
  {
    $cfgServerString = file_get_contents ('/etc/shipard/server.json');
    $this->cfgServer = json_decode ($cfgServerString, true);
    if (!$this->cfgServer)
    {
      $this->logMsg("ERROR: invalid server configuration - file not found or syntax error");
      return false;
    }
  
    if (!isset($this->cfgServer['useHosting']))
    {
      $this->logMsg("ERROR: invalid server configuration - missing `useHosting`");
      return false;
    }
  
    if (!isset($this->cfgServer['hostingDomain']))
    {
      $this->logMsg("ERROR: invalid server configuration - missing `useHosting`");
      return false;
    }
  
    if ($this->cfgServer['hostingDomain'] === '')
      return false;

      
    $this->hostingDomain = $this->cfgServer ['hostingDomain'];
  
    return true;  
  }

  function apiCall($url)
  {
    $hostingApiKey = $this->cfgServer ['hostingApiKey'];
    $opts = array(
      'http' => [
        'timeout' => 10,
        'method' => "GET",
        'header' =>
          "e10-api-key: " . $hostingApiKey . "\r\n".
          "e10-device-id: " . $this->machineDeviceId (). "\r\n".
          "Connection: close\r\n"
      ]
    );
    $context = stream_context_create($opts);

    $this->logMsg('--- get data from ' . $url);
    $resultCode = file_get_contents($url, false, $context);
    return $resultCode;
  }

  function getRequestFromHosting()
  {
    $serverGID = $this->cfgServer ['serverGID'];
  
    $hostingApiKey = $this->cfgServer ['hostingApiKey'];
    $opts = array(
      'http' => [
        'timeout' => 10,
        'method' => "GET",
        'header' =>
          "e10-api-key: " . $hostingApiKey . "\r\n".
          "e10-device-id: " . $this->machineDeviceId (). "\r\n".
          "Connection: close\r\n"
      ]
    );
    $context = stream_context_create($opts);

    $url = 'https://'.$this->hostingDomain.'/api/objects/call/get-new-data-source-request?serverGID=' . $serverGID;
    $this->logMsg('--- get data from ' . $url);
    $resultCode = file_get_contents($url, false, $context);
  
    $resultData = json_decode($resultCode, true);
    if ($resultData === false)
    {
      $this->logMsg('* ERROR: syntax error in data:');
      $this->logMsg($resultCode);
      return false;
    }
    $this->logMsg(Json::lint($resultData));
  
    if (!isset($resultData ['request']))
    {
      $this->logMsg('--- DONE: no request found');
      return false;
    }

    $this->newDataSourceRequest = $resultData;
    
    $this->logMsg('--- REQUEST RECEIVED:');
    //print_r($resultData);
  
    return true;
  }

  protected function createDataSource()
  {
    $documentRoot = $this->cfgServer ['dsRoot'];
    $request = $this->newDataSourceRequest ['request'];

    $this->logMsg('--- START: createNewDataSource at `'.$documentRoot.'` ----');
    $dsid = $request['gid'];
    
    $module = $request['createRequest']['installModule'];
    
  
    $this->logMsg('* chdir: ' . $documentRoot);
    chdir($documentRoot);
    $cmd = "shpd-server app-create --name=\"$dsid\" --module=$module";
  
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);
  
    if (!is_dir($documentRoot . '/' . $dsid))
      return false;
  
    $this->logMsg('* chdir: ' . $documentRoot . '/' . $dsid);
    chdir($documentRoot . '/' . $dsid);
    file_put_contents('config/createApp.json', Json::lint($request));
  
    $dsInfo = ['dsid' => strval ($dsid)];
    file_put_contents('config/dataSourceInfo.json', json_encode($dsInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $cmd = 'shpd-server app-init';
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);
  
    $cmd = 'shpd-server app-getdsinfo';
    $this->logMsg('* exec: '.$cmd);
    if (!$this->debug)
      passthru($cmd);
  
    $cmd = 'shpd-server app-fullupgrade';
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);


    if ($request['createRequest']['dsDemo'])
    {
      $this->restoreDemo($request);
    }

  
    // -- confirm
    $this->logMsg('* confirm new data source to hosting server ');
    $url = 'https://'.$this->hostingDomain.'/api/objects/call/confirm-new-data-source-request?serverGID='.$this->cfgServer ['serverGID'].'&dsGID='.$dsid;
    $resultCode2 = $this->apiCall($url);
    $resultData2 = json_decode($resultCode2, true);
  
    $cmd = 'shpd-server app-publish';
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);

    $this->logMsg('--- DONE: createNewDataSource ----');
  }

  public function restoreDemo($request)
  {
    $today = Utils::today('Y-m-d');
    $dbBackupFileName = '/var/lib/shipard/tmp/demo/'.$request['createRequest']['dsCreateDemoType'].'-'.$today.'.sql';
    if (!is_readable($dbBackupFileName))
    {
      $this->logMsg("ERROR: file `$dbBackupFileName` not found\n");
      return;
    }

    $cmd = 'shpd-server db-restore --file='.$dbBackupFileName;
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);
    
    $cmd = 'shpd-app initRestoredDemo';
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);

    $cmd = 'shpd-server app-fullupgrade';
    $this->logMsg('* exec: '.$cmd);
    passthru($cmd);
  }

  public function run()
  {
    if (!$this->init())
      return;

    if (!$this->getRequestFromHosting())
      return;

    $this->createDataSource();
  }
}
