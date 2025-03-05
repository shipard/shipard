<?php

namespace e10pro\fp\libs\apps;
use \Shipard\UI\ng\AppPageAnonymousRequest;


/**
 * class ActionInviteDownloadFile
 */
class ActionInviteDownloadFile extends AppPageAnonymousRequest
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;

  public function checkData()
  {
    $requestUId = $this->uiRouter->urlPath[2] ?? '';

    $requestRecData = $this->app()->db()->query('SELECT * FROM e10pro_fp_downloadInvites WHERE uid = %s', $requestUId)->fetch();
    if (!$requestRecData)
    {
      return;
    }

    $storageRecData = $this->app()->loadItem($requestRecData['storage'], 'e10pro.fp.storages');
    if (!$storageRecData)
    {
      return;
    }
    $portalRecData = $this->app()->loadItem($storageRecData['filePortal'], 'e10pro.fp.filePortals');
    if (!$portalRecData)
    {
      return;
    }

    $fileName = $requestRecData['filePath'];
    $fileName .= '/';
    $fileName .= $requestRecData['baseFileName'];

    $this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->setFilePortal($portalRecData['uid']);
		$this->filesEngine->setStorage($storageRecData['uid']);
    $this->filesEngine->downloadFile($fileName);
  }

  public function run()
  {
    $this->checkData();
  }
}

