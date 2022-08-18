<?php

namespace hosting\core\libs;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * @class ServerInfoCreator
 */
class ServerInfoCreator extends Utility
{
  var $infoData = [];
  var ?array $cfgServer = NULL;

  var $showOnly = 0;

  protected function init()
  {
    $this->infoData['type'] = 'core';
    $this->infoData['created'] = Utils::now('Y-m-d H:i:s');
    $this->infoData['shpGen'] = 'ng';

		$cfgServer = $this->loadCfgFile(__SHPD_ETC_DIR__.'/server.json');
		if (!$cfgServer)
      return;
    $this->cfgServer = $cfgServer;

    $this->infoData['serverId'] = $this->cfgServer['serverId'];
  }

  protected function detectShipardVersions()
  {
    $this->infoData['shipardServerDefaultChannel'] = $this->cfgServer['defaultChannel'];
    foreach ($this->cfgServer['channels'] as $channelId => $channel)
    {
      $channelVerInfo = $this->loadCfgFile($channel['path'].'version.json');

      $commitFN = __SHPD_VAR_DIR__.'tmp/__commit__shp-branch__'.$channelId.'.txt';
      $cmd = 'cd '.$channel['path']." && git log --pretty=format:'%h' -n 1 > ".$commitFN;
      system($cmd);
      $channelCommit = file_get_contents($commitFN);

      $channelInfo = [
        'version' => $channelVerInfo['version'].'.'.$channelCommit,
        'coreVersion' => $channelVerInfo['version'],
        'commit' => $channelCommit,
      ];

      $this->infoData['shipardServerChannels'][$channelId] = $channelInfo;
    }
  }

  protected function detectOSVersion()
  {
    if (is_readable('/etc/os-release'))
    {
      $osSrcInfo = parse_ini_file('/etc/os-release');
      if ($osSrcInfo)
      {
        $osInfo = [
          'id' => $osSrcInfo['ID'] ?? '---',
          'versionId' => $osSrcInfo['VERSION_ID'] ?? '---',
          'fullName' => $osSrcInfo['PRETTY_NAME'] ?? '---',
          'name' => $osSrcInfo['NAME'] ?? '---',
          'version' => $osSrcInfo['VERSION'] ?? '---',
        ];
        $this->infoData['os'] = $osInfo;
      }
    }

    $this->infoData['os']['kernel'] = php_uname('r');
    $this->infoData['os']['platform'] = php_uname('m');

    $virtInfo = [];
    exec ('systemd-detect-virt', $virtInfo);
    $this->infoData['os']['virt'] = $virtInfo[0] ?? '-';

    if (is_readable('/etc/timezone'))
    {
      $tz = file_get_contents('/etc/timezone');
      if ($tz)
        $this->infoData['timeZone'] = trim($tz);
    }
  }

  protected function detectMainSWVersions()
  {
    $this->infoData['mainSW'] = [
      'php' => ['version' => phpversion()],
    ];

    // -- headless chrome
    $hbsfn = __SHPD_VAR_DIR__.'shpd/shpd-headless-browser.json';
    if (is_readable($hbsfn))
    {
      $browserStatus = $this->loadCfgFile($hbsfn);
      if (isset($browserStatus['Browser']))
      {
        $parts = explode('/', $browserStatus['Browser']);
        $this->infoData['mainSW']['headlessBrowser'] = [
          'version' => $parts[1] ?? '!!!',
          'title' => $parts[0] ?? '!!!',
        ];
      }
    }
  }

  public function detectDataSources()
  {
    $this->infoData['dataSources'] = [];

		forEach (glob ('/var/lib/shipard/data-sources/*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
			if (!is_file ($appDir.'/config/config.json'))
				continue;

			$cfg = utils::loadCfgFile($appDir.'/config/config.json');
			$dsInfo = utils::loadCfgFile($appDir.'/config/dataSourceInfo.json');
			$channelInfo = utils::loadCfgFile($appDir.'/config/_server_channelInfo.json');
			$statusData = 'RUN';
			if (is_file ($appDir.'/config/status.data'))
				$statusData = file_get_contents($appDir.'/config/status.data');

			$ds = [
        'dsid' => $cfg['dsid'],
        'name' => $dsInfo['name'] ?? '---',
        'status' => trim($statusData),
        'channelId' => $channelInfo['serverInfo']['channelId'] ?? '-',
      ];

			$this->infoData['dataSources'][] = $ds;
		}
  }

  public function run()
  {
    $this->init();
    $this->detectOSVersion();
    $this->detectShipardVersions();
    $this->detectMainSWVersions();
    $this->detectDataSources();

    file_put_contents(__SHPD_VAR_DIR__.'tmp/__server_info'.'.json', Json::lint($this->infoData));

    if (!$this->showOnly)
    {
      $url = 'https://'.$this->cfgServer['hostingDomain'].'/api/objects/call/hosting-server-info-upload';
      $ce = new \lib\objects\ClientEngine($this->app());
      $ce->apiKey = $this->cfgServer['hostingApiKey'];
      $ce->apiCall($url, $this->infoData);
    }
    else
    {
      echo Json::lint($this->infoData)."\n";
    }
  }
}
