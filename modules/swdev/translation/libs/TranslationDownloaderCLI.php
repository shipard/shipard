<?php

namespace swdev\translation\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class TranslationDownloaderCLI
 * @package swdev\translation\libs
 */
class TranslationDownloaderCLI extends Utility
{
	public function getTables()
	{
		echo "Synchronize tables: ";


		$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getIndexTables'];
		$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

		if (!$result || !isset($result['tables']))
		{
			echo "  ### ERROR ###";
		}

		echo count($result['tables']).' tables, ';

		$toUpdate = [];
		foreach ($result['tables'] as $tableId => $tableTexts)
		{
			foreach ($tableTexts as $langId => $langState)
			{
				$tableIdParts = explode('.', $tableId);
				array_pop($tableIdParts);
				$fileName = __SHPD_MODULES_DIR__.'translation/dm/tables/'.implode('/', $tableIdParts).'/'.$tableId.'/'.$tableId.'.'.$langId.'.json';
				$doUpdate = 0;
				if (!is_readable($fileName))
					$doUpdate = 1;
				elseif (sha1_file($fileName) !== $langState['checksum'])
					$doUpdate = 1;
				if (!$doUpdate)
					continue;
				$toUpdate[] = ['fileName' => $fileName, 'langId' => $langId, 'tableId' => $tableId, 'trNdx' => $langState['trNdx']];
			}
		}

		echo count($toUpdate).' to update';
		echo "\n";

		foreach ($toUpdate as $u)
		{
			echo ' - '.$u['tableId'].'.'.$u['langId'];

			$tableIdParts = explode('.', $u['tableId']);
			array_pop($tableIdParts);
			$dir = __SHPD_MODULES_DIR__.'translation/dm/tables/'.implode('/', $tableIdParts).'/'.$u['tableId'];
			if (!is_dir($dir))
				mkdir($dir, 0777, TRUE);

			$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getTableTrData', 'trNdx' => $u['trNdx']];
			$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

			if (isset($result['trData']))
				file_put_contents($u['fileName'], json::lint($result['trData']));

			echo "\n";
		}

		return TRUE;
	}

	public function getDicts()
	{
		echo "Synchronize dicts: ";

		$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getIndexDicts'];
		$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

		if (!$result || !isset($result['dicts'])) {
			echo "  ### ERROR ###";
		}

		echo count($result['dicts']) . ' dicts, ';

		$toUpdate = [];
		foreach ($result['dicts'] as $dictId => $dict)
		{
			$dictIdParts = explode('.', $dictId);
			$dictClassName = array_pop($dictIdParts);
			$dictPath = __SHPD_MODULES_DIR__.'translation/dicts/'.implode('/', $dictIdParts);
			$dictClassFileName = $dictPath.'/'.$dictClassName.'.php';

			$doUpdate = 0;
			if (!is_readable($dictClassFileName))
				$doUpdate = 1;
			elseif (sha1_file($dictClassFileName) !== $dict['checksum'])
				$doUpdate = 1;
			if ($doUpdate)
				$toUpdate[] = ['fileName' => $dictClassFileName, 'langId' => '', 'dictId' => $dictId, 'trNdx' => $dict['trNdx']];

			foreach ($dict['langs'] as $langId => $langState)
			{
				$langFileName =  $dictPath.'/'.$dictClassName.'.'.$langId.'.json';
				$langFileNameData =  $dictPath.'/'.$dictClassName.'.'.$langId.'.data';
				$doUpdate = 0;
				if (!is_readable($langFileName))
					$doUpdate = 1;
				elseif (sha1_file($langFileName) !== $langState['checksum'])
					$doUpdate = 1;
				if (!$doUpdate)
					continue;
				$toUpdate[] = ['fileName' => $langFileName, 'fileNameData' => $langFileNameData, 'langId' => $langId, 'dictId' => $dictId, 'trNdx' => $langState['trNdx']];
			}
		}

		echo count($toUpdate).' files to update';
		echo "\n";

		foreach ($toUpdate as $u)
		{
			echo ' - '.$u['dictId'];
			if ($u['langId'] !== '')
				echo ('.'.$u['langId']);

			$dictIdParts = explode('.', $dictId);
			$dictClassName = array_pop($dictIdParts);
			$dictPath = __SHPD_MODULES_DIR__.'translation/dicts/'.implode('/', $dictIdParts);
			if (!is_dir($dictPath))
				mkdir($dictPath, 0777, TRUE);

			$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getDictTrData', 'trNdx' => $u['trNdx']];
			$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

			if (isset($result['trData']))
			{
				file_put_contents($u['fileName'], $result['trData']);
				if ($u['langId'] !== '')
				{
					$data = json_decode($result['trData'], TRUE);
					file_put_contents($u['fileNameData'], serialize($data));
				}
			}
			echo "\n";
		}
	}

	public function getEnums()
	{
		echo "Synchronize enums: ";

		$enumsList = [];

		$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getIndexEnums'];
		$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

		if (!$result || !isset($result['enums'])) {
			echo "  ### ERROR ###";
		}

		echo count($result['enums']) . ' enums, ';

		$toUpdate = [];
		foreach ($result['enums'] as $enumId => $enum)
		{
			$enumsList[] = $enumId;
			foreach ($enum as $langId => $langState)
			{
				$enumIdParts = array_slice(explode('.', $enumId), 0, 2);
				$fileName = __SHPD_MODULES_DIR__.'translation/enums/'.implode('/', $enumIdParts).'/'.$enumId.'.'.$langId.'.json';
				$fileNameData = __SHPD_MODULES_DIR__.'translation/enums/'.implode('/', $enumIdParts).'/'.$enumId.'.'.$langId.'.data';
				$doUpdate = 0;
				if (!is_readable($fileName))
					$doUpdate = 1;
				elseif (sha1_file($fileName) !== $langState['checksum'])
					$doUpdate = 1;
				if (!$doUpdate)
					continue;
				$toUpdate[] = ['fileName' => $fileName, 'fileNameData' => $fileNameData, 'langId' => $langId, 'enumId' => $enumId, 'trNdx' => $langState['trNdx']];
			}
		}

		echo count($toUpdate).' files to update';
		echo "\n";

		sort($enumsList, SORT_STRING);
		file_put_contents(__SHPD_MODULES_DIR__.'translation/enums/_src/enums.json', json::lint($enumsList));

		foreach ($toUpdate as $u)
		{
			echo ' - '.$u['enumId'];
			if ($u['langId'] !== '')
				echo ('.'.$u['langId']);

			$enumIdParts = array_slice(explode('.', $u['enumId']), 0, 2);
			$enumPath = __SHPD_MODULES_DIR__.'translation/enums/'.implode('/', $enumIdParts).'/';
			if (!is_dir($enumPath))
				mkdir($enumPath, 0777, TRUE);

			$apiData = ['object-class-id' => 'swdev.translation.libs.TranslationDownloaderServer', 'operation' => 'getEnumTrData', 'trNdx' => $u['trNdx']];
			$result = $this->app->runApiCall($this->app()->devServerCfg['devServerUrl'] . '/api', $this->app()->devServerCfg['devServerApiKey'], $apiData);

			if (isset($result['trData']))
			{
				file_put_contents($u['fileName'], json::lint($result['trData']));

				if ($u['langId'] !== '')
					file_put_contents($u['fileNameData'], serialize($result['trData']));
			}
			echo "\n";
		}
	}
}
