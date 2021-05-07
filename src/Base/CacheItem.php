<?php

namespace Shipard\Base;


class CacheItem extends BaseObject
{
	var $data = [];
	var $cacheInfo = ['created' => NULL];

	function createData ()
	{
		$now = new \DateTime();
		$this->cacheInfo['created'] = $now->format ('Y-m-d H:i:s');
	}
}
