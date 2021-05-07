<?php

namespace Shipard\Base;
use \Shipard\Utils\Utils;


class Cache extends BaseObject
{
	var $redis;
	var $dsId;

	public function init ()
	{
		$this->dsId = $this->app->cfgItem ('dsid');
		$this->redis = new \Redis ();
		//$this->redis->connect('/var/run/redis/redis.sock');
		$this->redis->connect('127.0.0.1');
	}

	public function getCacheItem ($classId, $force = FALSE)
	{
		$keyId = $this->dsId.'-cacheItems-'.$classId;
		$i = $this->redis->hGetAll($keyId);
		if (!count($i) || $force)
		{
			$o = $this->app->createObject($classId);
			$o->createData();
			$i = ['data' => $o->data];
			$this->redis->hSet ($keyId, 'data', json_encode($o->data));
			$this->redis->hSet ($keyId, 'cacheInfo', json_encode($o->cacheInfo));
			$this->redis->incr($keyId.'_inv');
			$i['invalidate'] = intval($this->redis->get($keyId.'_inv'));
			$i['changed'] = FALSE;
			return $i;
		}

		$res = [];
		foreach ($i as $key => $value)
			$res[$key] = json_decode($value, TRUE);
		$res['invalidate'] = intval($this->redis->get($keyId.'_inv'));
		$res['changed'] = $this->redis->get($keyId.'_changed');

		if ($res['invalidate'])
			$this->invalidateItem($classId, TRUE);

		return $res;
	}

	public function invalidate ($tableId, $itemId)
	{
		$ic = $this->app->cfgItem('appCache.invalidate');
		if (isset($ic[$tableId]) && isset($ic[$tableId][$itemId]))
		{
			$classes = $ic[$tableId][$itemId];
			foreach ($classes as $classId)
				$this->invalidateItem($classId, FALSE);
		}
	}

	function invalidateItem ($classId, $force = TRUE)
	{
		$keyId = $this->dsId.'-cacheItems-'.$classId;

		$classInfo = $this->app->cfgItem('appCache.items.'.$classId);
		$this->redis->incr($keyId.'_inv');
		$this->redis->set($keyId.'_changed', Utils::now('Y-m-d H:i:s'));

		if ($force || isset($classInfo['onFly']))
			$this->redis->publish ('invalidate-cache-item', $keyId);
	}

	public function getDataItem ($key)
	{
		$keyId = $this->dsId.'-cacheDataItems-'.$key;
		$i = $this->redis->get($keyId);
		if (!$i)
			return NULL;

		$res = json_decode($i, TRUE);

		return $res;
	}

	public function setDataItem ($key, $data)
	{
		$keyId = $this->dsId.'-cacheDataItems-'.$key;
		$keyValue = json_encode($data);

		$this->redis->set($keyId, $keyValue);
	}
}

