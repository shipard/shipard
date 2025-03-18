<?php

namespace e10pro\fp\libs\apps;
use \Shipard\UI\ng\AppPageAnonymousRequest;
use \Shipard\Utils\Utils;


/**
 * class ActionInviteDownloadFile
 */
class ActionInviteDownloadFile extends AppPageAnonymousRequest
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;

  public function createContentCodeInside ()
	{
    $appTemplate = 'downloadFromInvite';
    $appTemplatePath = 'modules/e10pro/fp/libs/apps/subtemplates/';

    $templateStr = file_get_contents(__SHPD_ROOT_DIR__.$appTemplatePath.$appTemplate.'.mustache');

    $c = $this->uiTemplate->render($templateStr);

    return $c;
	}

  public function checkData()
  {
    $requestUId = $this->uiRouter->urlPath[2] ?? '';

    $requestRecData = $this->app()->db()->query('SELECT * FROM e10pro_fp_downloadInvites WHERE uid = %s', $requestUId)->fetch();
    if (!$requestRecData)
    {
      $this->uiTemplate->data['errorInvalidInviteId'] = 1;
      $this->uiTemplate->data['invalidInviteId'] = $requestUId;
      return;
    }

    // -- expired?
    if (!Utils::dateIsBlank($requestRecData['tsValidTo']))
    {
      $now = new \DateTime();
      if ($now > $requestRecData['tsValidTo'])
      {
        $this->uiTemplate->data['errorInviteExpired'] = 1;
        $this->uiTemplate->data['inviteDateExpire'] = Utils::datef($requestRecData['tsValidTo'], '%D %T');
        return;
      }
    }

    if ($requestRecData['maxDownloadCnt'])
    {
      $downloadCnt = $this->app()->db()->query('SELECT COUNT(*) AS cnt FROM e10pro_fp_downloadsLog WHERE invite = %i', $requestRecData['ndx'])->fetch();
      if ($downloadCnt && $downloadCnt['cnt'] >= $requestRecData['maxDownloadCnt'])
      {
        $this->uiTemplate->data['errorInviteMaxDownloads'] = 1;
        $this->uiTemplate->data['inviteMaxDownloads'] = $requestRecData['maxDownloadCnt'];
        return;
      }
    }

    $storageRecData = $this->app()->loadItem($requestRecData['storage'], 'e10pro.fp.storages');
    if (!$storageRecData)
    {
      $this->uiTemplate->data['dowloadStatusTitle'] = 'Neplatný požadavek2';
      return;
    }
    $portalRecData = $this->app()->loadItem($storageRecData['filePortal'], 'e10pro.fp.filePortals');
    if (!$portalRecData)
    {
      $this->uiTemplate->data['dowloadStatusTitle'] = 'Neplatný požadavek3';
      return;
    }

    $fileName = $requestRecData['filePath'];
    $fileName .= '/';
    $fileName .= $requestRecData['baseFileName'];

    $this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->setFilePortal($portalRecData['uid']);
		$this->filesEngine->setStorage($storageRecData['uid']);
    $this->filesEngine->downloadFile($fileName, $requestRecData['ndx']);
  }

  public function run()
  {
    $this->checkData();
  }
}

