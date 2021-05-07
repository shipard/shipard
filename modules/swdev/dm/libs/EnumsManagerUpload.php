<?php

namespace swdev\dm\libs;

use \e10\Utility, \e10\json, \e10\utils;


/**
 * Class EnumsManagerUpload
 * @package swdev\dm\libs
 */
class EnumsManagerUpload extends Utility
{
	var $devServerCfg = NULL;

	function prepareEnum($cfgPath, $cfgData, $enumData, &$dstData)
	{
		if (isset($cfgData['enabled']) && !in_array($cfgPath, $cfgData['enabled']))
			return;

		$dstData[$cfgPath] = [
			'name' => $cfgData['name'],
			'textsIds' => $cfgData['values'],
			'data' => [],
			'texts' => [],
		];

		$keyId = isset($cfgData['key']) ? $cfgData['key'] : '';

		foreach ($enumData as $key => $value)
		{
			$k = ($keyId == '') ? strval($key) : $value[$keyId];

			$firstText = '';
			//$dstData[$cfgPath]['texts'][$k] = [];
			$dstData[$cfgPath]['data'][$k] = $value;
			if (isset($cfgData['values']))
			{
				foreach ($cfgData['values'] as $valueId => $valueTitle)
				{
					if (isset($value[$valueId]) && $value[$valueId] !== '' && $value[$valueId] != NULL)
					{
						$dstData[$cfgPath]['texts'][$k][$valueId] = $value[$valueId];
						if ($firstText === '')
							$firstText = $value[$valueId];
					}
				}
			}

			if (!isset($cfgData['subItems']))
				continue;

			foreach ($cfgData['subItems'] as $subItemId => $subItemCfgData)
			{
				$subCfgPath = $cfgPath.'.'.$k.'.'.$subItemId;
				$subEnumData = $this->app()->cfgItem($subCfgPath, NULL);
				if (!$subEnumData)
					continue;

				$subItemCfgData['name'] .= ' / '.$firstText;

				$this->prepareEnum($subCfgPath, $subItemCfgData, $subEnumData,$dstData);
			}
		}
	}

	function prepareOneFile($cfgData)
	{
		foreach ($cfgData as $cfgPath => $enumCfgData)
		{
			$enum = $this->app()->cfgItem($cfgPath, NULL);
			if (!$enum)
				continue;

			echo "  - ".$cfgPath."\n";
			//echo json_encode($enum);

			$dstData = [];
			$this->prepareEnum($cfgPath, $enumCfgData, $enum, $dstData);
			//echo json::lint($dstData)."\n------------\n";

			$apiData = ['object-class-id' => 'swdev.dm.UploaderDataModel', 'operation' => 'upload', 'type' => 'enums', 'data' => $dstData];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	function prepareFolder($path)
	{
		// -- files
		forEach (glob ($path.'/*.json') as $jfn)
		{
			$data = utils::loadCfgFile($jfn);
			if (!$data)
			{
				echo "ERROR: file `$jfn` has bad syntax...\n";
				return;
			}
			//echo 'file: '.$jfn."\n";

			$this->prepareOneFile($data);
		}

		// sub folders
		forEach (glob($path.'/*', GLOB_ONLYDIR) as $subFolder)
		{
			$this->prepareFolder($subFolder);
		}
	}

	public function run()
	{
		$path = __APP_DIR__.'/e10-modules/translation/enums/_src';
		//echo $path."\n";
		$this->prepareFolder($path);
	}
}
