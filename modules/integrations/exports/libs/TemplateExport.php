<?php

namespace integrations\exports\libs;
use \Shipard\Utils\Json;


/**
 * class TemplateExport
 */
class TemplateExport extends \Shipard\Utils\TemplateCore
{
  public function app() {return $this->app;}

	function resolveCmd ($tagCode, $tagName, $params)
	{
    switch ($tagName)
		{
			case	'cacheItem' 					: return $this->cacheItem ($params);
		}

    return parent::resolveCmd($tagCode, $tagName, $params);
  }

  public function cacheItem(array $params)
  {
    $classId = $params['classId'] ?? '';
    if ($classId === '')
    {
      return 'Missing classId param';
    }

    $forceInvalidate = FALSE;
    $cd = $this->app->cache->getCacheItem($classId, $forceInvalidate);
    $data = $cd['data'] ?? $cd;

    if (!$data)
    {
      return 'Empty data';
    }

    return Json::lint($data);
  }
}
