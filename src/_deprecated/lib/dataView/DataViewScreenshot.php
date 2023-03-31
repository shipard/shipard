<?php

namespace lib\dataView;

use \Shipard\Utils\Json;


/**
 * class DataViewScreenshot
 */
class DataViewScreenshot extends \lib\dataView\DataView
{
	protected function renderData()
	{
    $sc = new \Shipard\Base\Screenshot ($this->app());

    $vpWidth = intval($this->app()->testGetParam('vpWidth'));
    if ($vpWidth)
      $sc->vpWidth = $vpWidth;

    $vpHeight = intval($this->app()->testGetParam('vpHeight'));
    if ($vpHeight)
      $sc->vpHeight = $vpHeight;

    $sc->url = $this->app()->testGetParam('url');
    $sc->run ();

    $pageInfoStr = file_get_contents($sc->scCreator->dstFileNameInfo);
    $pageInfo = Json::decode($pageInfoStr);
    if (!$pageInfo)
      $pageInfo = [];

    $pageInfo['imageUri']  = $this->app()->urlRoot.substr($sc->dstFullFileName, strlen(__APP_DIR__));

    $this->template->data['forceCode'] = Json::lint($pageInfo);
    $this->template->data['forceMimeType'] = 'application/json';
	}
}

