<?php

namespace swdev\translation\libs;

use e10\E10ApiObject, \e10\utils;


/**
 * Class TranslationDownloaderServer
 * @package swdev\translation\libs
 */
class TranslationDownloaderServer extends E10ApiObject
{
	var $allLanguages;

	public function init ()
	{
		$this->allLanguages = $this->app()->cfgItem ('swdev.tr.lang.langs', []);
	}

	function getIndexDMTables($response)
	{
		$tt = new \swdev\dm\libs\TranslationTable($this->app());
		$tt->updateTableTrData();

		$tables = [];

		$q[] = 'SELECT trData.ndx, trData.checksum, dmTables.id, langs.[code] FROM [swdev_dm_tablesTrData] AS trData';
		array_push($q, ' LEFT JOIN [swdev_dm_tables] AS dmTables ON trData.[table] = dmTables.ndx');
		array_push($q, ' LEFT JOIN [swdev_translation_languages] AS [langs] ON trData.lang = langs.ndx');
		array_push($q, ' WHERE 1');
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$lang = $this->allLanguages[$r['lang']];
			$tables[$r['id']][$r['code']] = ['checksum' => $r['checksum'], 'trNdx' => $r['ndx']];
		}

		$response->add('tables', $tables);

		return TRUE;
	}

	function getIndexDicts($response)
	{
		$tt = new \swdev\dm\libs\TranslationTable($this->app());
		$tt->updateDictsTrData();

		$dicts = [];

		$q[] = 'SELECT trData.ndx, trData.lang, trData.checksum, dicts.identifier, langs.[code] FROM [swdev_translation_dictsTrData] AS trData';
		array_push($q, ' LEFT JOIN [swdev_translation_dicts] AS dicts ON trData.[dict] = dicts.ndx');
		array_push($q, ' LEFT JOIN [swdev_translation_languages] AS [langs] ON trData.lang = langs.ndx');
		array_push($q, ' ORDER BY trData.dict, trData.lang');


		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			if (!isset($dicts[$r['identifier']]))
			{
				$dicts[$r['identifier']] = [
					'id' => $r['identifier'], 'checksum' => $r['checksum'], 'trNdx' => $r['ndx'],
					'langs' => []
				];
			}
			if (!$r['lang'])
				continue;

			$lang = $this->allLanguages[$r['lang']];
			$dicts[$r['identifier']]['langs'][$r['code']] = ['checksum' => $r['checksum'], 'trNdx' => $r['ndx']];
		}

		$response->add('dicts', $dicts);

		return TRUE;
	}

	function getIndexEnums($response)
	{
		$tt = new \swdev\dm\libs\TranslationTable($this->app());
		$tt->updateEnumsTrData();

		$enums = [];

		$q[] = 'SELECT trData.ndx, trData.lang, trData.checksum, enums.id, langs.[code] FROM [swdev_dm_enumsTrData] AS trData';
		array_push($q, ' LEFT JOIN [swdev_dm_enums] AS enums ON trData.[enum] = enums.ndx');
		array_push($q, ' LEFT JOIN [swdev_translation_languages] AS [langs] ON trData.lang = langs.ndx');
		array_push($q, ' ORDER BY trData.enum, trData.lang');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$r['lang'])
				continue;

			$lang = $this->allLanguages[$r['lang']];
			$enums[$r['id']][$r['code']] = ['checksum' => $r['checksum'], 'trNdx' => $r['ndx']];
		}

		$response->add('enums', $enums);

		return TRUE;
	}

	function getTableTrData($response)
	{
		$trNdx = intval($this->requestParams['trNdx']);
		if (!$trNdx)
			return FALSE;

		$exist = $this->db()->query('SELECT * FROM [swdev_dm_tablesTrData] WHERE ndx = %i', $trNdx)->fetch();
		if (!$exist)
		{
			return FALSE;
		}

		$trData = json_decode($exist['data']);
		$response->add('trData', $trData);

		return TRUE;
	}

	function getDictTrData($response)
	{
		$trNdx = intval($this->requestParams['trNdx']);
		if (!$trNdx)
			return FALSE;

		$exist = $this->db()->query('SELECT * FROM [swdev_translation_dictsTrData] WHERE ndx = %i', $trNdx)->fetch();
		if (!$exist)
		{
			return FALSE;
		}

		//$trData = json_encode($exist['data']);
		$response->add('trData', $exist['data']);

		return TRUE;
	}

	function getEnumTrData($response)
	{
		$trNdx = intval($this->requestParams['trNdx']);
		if (!$trNdx)
			return FALSE;

		$exist = $this->db()->query('SELECT * FROM [swdev_dm_enumsTrData] WHERE ndx = %i', $trNdx)->fetch();
		if (!$exist)
		{
			return FALSE;
		}

		$trData = json_decode($exist['data']);
		$response->add('trData', $trData);

		return TRUE;
	}

	public function createResponseContent($response)
	{
		$this->init();

		if ($this->requestParams['operation'] === 'getIndexTables')
		{
			if ($this->getIndexDMTables($response))
			{
				return;
			}
		}
		elseif ($this->requestParams['operation'] === 'getTableTrData')
		{
			if ($this->getTableTrData($response))
			{
				return;
			}
		}
		elseif ($this->requestParams['operation'] === 'getIndexDicts')
		{
			if ($this->getIndexDicts($response))
			{
				return;
			}
		}
		elseif ($this->requestParams['operation'] === 'getDictTrData')
		{
			if ($this->getDictTrData($response))
			{
				return;
			}
		}
		elseif ($this->requestParams['operation'] === 'getIndexEnums')
		{
			if ($this->getIndexEnums($response))
			{
				return;
			}
		}
		elseif ($this->requestParams['operation'] === 'getEnumTrData')
		{
			if ($this->getEnumTrData($response))
			{
				return;
			}
		}

		$response->add ('success', 1);
	}
}
