<?php

namespace mac\data\libs;

use \Shipard\Base\Utility, \e10\utils, \e10\json;


/**
 * @class SensorsBadges
 */
class SensorsBadges extends Utility
{
  CONST bstNetdata = 0;

  var $sources = [];


  public function addSource(string $sourceId, array $sourceInfo)
  {
    $this->sources[$sourceId] = $sourceInfo;
  }

	public function netdataBadgeImg(string $sourceId, $label, $quantityId, $badgeParams)
	{
    $source = $this->sources[$sourceId] ?? NULL;
    if (!$source)
      return 'ERROR';

		$c = '';

		$params = [];
		$params['chart'] = $quantityId;
		$params['label'] = $label;

		$badgeClass = '';
		if (isset($badgeParams['badgeClass']))
		{
			$badgeClass = $badgeParams['badgeClass'];
			unset($badgeParams['badgeClass']);
		}

		if ($badgeParams)
		{
			foreach ($badgeParams as $k => $v)
			{
				if ($k[0] === '_')
					continue;
				$params[$k] = strval($v);
			}	
		}

		$srcUrl = $source['url'];
		
		$srcUrl .= "/api/v1/badge.svg".'?'.http_build_query($params);
		$params['xyz'] = time();
		$c .= "<img class='e10-auto-reload $badgeClass'";
		$c .= " data-src='$srcUrl'";
		$c .= " src='$srcUrl'";
		$c .= " data-badge-id='$quantityId'";
		if (isset($badgeParams['_title']))
			$c .= " title='".utils::es($badgeParams['_title'])."'";
		$c .= "/>";

		return $c;
	}
}
