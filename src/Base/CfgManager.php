<?php

namespace Shipard\Base;
use Shipard\Utils\Utils;
use Shipard\Application\DataModel;
use Shipard\Application\Application;


function mergeCfg(&$dest, &$src)
{
	$keys = array_keys($src);
	if (isset($keys[0]) && is_int($keys[0]))
	{
		forEach ($src as $key => $value)
		{
			if (isset ($dest [$key]))
				$dest [] = $value;
			else
				$dest [$key] = $value;
		}
		return;
	}
	forEach ($src as $key => $value)
	{
		if (is_array ($value) || is_object($value))
		{
			if (isset ($dest [$key]))
				mergeCfg ($dest [$key], $value);
			else
				$dest [$key] = $value;
		}
		else
			$dest [$key] = $value;
	}
}


class CfgManager
{
	var $db = NULL;
	var $appCfg;
	var $appSkeleton;
	var $appModules;
	var $arguments;
	var $modules = array();
	var $loadedModules = array();
	var $tables = array();
	var $sqlCommands = array ();
	var $dataModel;
	var $loadedConfigFiles = array ();
	var $msgLevel = 1;
	var $translateHints;

	var $newConfig;

	var $errors = array ();

	const TABLE_STATUS_OK = 1, TABLE_STATUS_CREATE = 2, TABLE_STATUS_ALTER = 3;

	public function __construct ()
	{
		$this->dataModel = new \Shipard\Application\DataModel();
	}

	public function createObject ($class)
	{
		$fullClassName = $class;
		if (strstr ($class, '.') !== FALSE)
		{
			$parts = explode ('.', $class);
			$className = array_pop ($parts);
			$moduleFileName = __SHPD_MODULES_DIR__ . implode ('/', $parts) . '/' . end($parts) . '.php';
			if (!is_file ($moduleFileName))
				$moduleFileName = __SHPD_MODULES_DIR__ . strtolower(implode('/', $parts)) . '/' . end($parts) . '.php';

			if (is_file ($moduleFileName))
				include_once ($moduleFileName);
			$fullClassName = '\\' . implode ('\\', $parts) . '\\' . $className;
		}

		if (class_exists ($fullClassName))
		{
			$o = new $fullClassName ($this);
			return $o;
		}

		error_log ('createObject failed for class ' . $class);
		return NULL;
	}

	public function appCompileConfig ()
	{
		$this->createDataModel ();

		$newConfig = array ();//$this->loadConfigFile (NULL, "config.json", __APP_DIR__ . "/config/");

		// -- config from modules
		forEach ($this->modules as $module)
		{
			//echo " - " . $module ['fullPath'] . "\r\n";
			if (!isset ($module['config']))
				continue;
			forEach ($module['config'] as $moduleCfg)
			{
				if (substr($moduleCfg ['file'], 0, 1) === '/')
				{
					$cfgFileName = __SHPD_MODULES_DIR__.$moduleCfg ['file'];
					$newModuleConfig = $this->loadConfigFile ($newConfig, $moduleCfg ['file'], __SHPD_MODULES_DIR__);
				}
				else
				{
					if (isset($moduleCfg['module']) && $this->dataModel->module($moduleCfg['module']) === FALSE)
						continue;

					$cfgFileName = $module ['fullPath'] . "config/" . $moduleCfg ['file'];
					$newModuleConfig = $this->loadConfigFile ($newConfig, $moduleCfg ['file'], $module ['fullPath'] . "config/");
					if ($newModuleConfig === NULL)
					{
						echo "WARNING: blank file '$cfgFileName'\n";
						continue;
					}
				}
				$this->addConfigFile ($newConfig, $moduleCfg ['id'], $newModuleConfig, $cfgFileName);
			}
			//$this->modules [] = $moduleCfg;
		}

		// -- all _*.json files
		$underscoreFiles = glob (__APP_DIR__ . '/config/_*.json', GLOB_BRACE);
		forEach ($underscoreFiles as $uf)
		{
			$newAppOptionsConfig = $this->loadConfigFileCore (basename ($uf), __APP_DIR__ . "/config/");
			$this->addConfigFile ($newConfig, '.', $newAppOptionsConfig, $uf);
		}

		// -- /etc/e10.cfg.json
		$globalCfg = '/etc/e10.cfg.json';
		if (is_file($globalCfg))
		{
			$newAppOptionsConfig = $this->loadConfigFileCore (basename ($globalCfg), "/etc/");
			$this->addConfigFile ($newConfig, '.', $newAppOptionsConfig, $globalCfg);
		}

		// -- application options
		forEach ($newConfig ['appOptions'] as $appOptionId => $appOption)
		{
			unset ($fileExt);
			if ($appOption ['type'] == 'cfgFile')
				$fileExt = 'json';
			else
			if ($appOption ['type'] == 'yamlBlob')
				$fileExt = 'yaml';
			if (isset ($fileExt))
			{
				$fn = __APP_DIR__ . "/config/appOptions.".$appOptionId.'.'.$fileExt;
				if (is_file ($fn))
				{
					$newAppOptionsConfig = $this->loadConfigFileCore ('appOptions.'.$appOptionId.'.'.$fileExt, __APP_DIR__ . "/config/");
					$this->addConfigFile ($newConfig, '+options.'.$appOptionId, $newAppOptionsConfig, $fn);
				}
			}
		}

		// -- application config
		$appSkeleton = NULL;
		if (!$appSkeleton)
			$appSkeleton = [];

		// check for default values
		$defaultPath = array ();
		$defaultPath['pathBase']										= 'user';
		$defaultPath['pathLogin']										= 'login';
		$defaultPath['pathLoginCheck']							= 'login-check';
		$defaultPath['pathLogout']									= 'logout';
		$defaultPath['pathLogoutCheck']							= 'logout-check';
		$defaultPath['pathLoginRobots']							= 'robots';
		$defaultPath['pathCheckUserRegistration']		= 'checkuserregistration';

		//$defaultPath['pathInvite']									= 'invite';
		//$defaultPath['pathInviteRequest']						= 'invite-request';
		//$defaultPath['pathInviteFinished']					= 'invite-finished';

		$defaultPath['pathRegistration']						= 'registration';
		$defaultPath['pathRegistrationRequest']			= 'registration-request';
		$defaultPath['pathRegistrationFinished']		= 'registration-finished';

		//$defaultPath['pathNewUser']									= 'new-user';
		//$defaultPath['pathNewUserRequest']					= 'new-user-request';
		//$defaultPath['pathNewUserFinished']					= 'new-user-finished';

		$defaultPath['pathSettings']								= 'settings';
		$defaultPath['pathSettingsCheck']						= 'settings-check';
		$defaultPath['pathSettingsFinished']				= 'settings-finished';

		$defaultPath['pathPasswordChange']					= 'password-change';
		$defaultPath['pathPasswordChangeRequest']		= 'password-change-request';
		$defaultPath['pathPasswordChangeFinished']	= 'password-change-finished';

		$defaultPath['pathLostPassword']						= 'lost-password';
		$defaultPath['pathLostPasswordRequest']			= 'lost-password-request';
		$defaultPath['pathLostPasswordFinished']		= 'lost-password-finished';

		$defaultPath['pathSetLanguage']									= 'set-language';

		$defaultPath['pathRequest']									= 'request';
		$defaultPath['pathRequestCheck']						= 'request-check';
		$defaultPath['pathRequestFinished']					= 'request-finished';
		foreach ($defaultPath as $path => $param)
		{
			if (!isset ($appSkeleton['userManagement'][$path]))
				$appSkeleton['userManagement'][$path] = $param;
		}

		if (isset ($newConfig ['appSkeleton']))
			mergeCfg ($newConfig ['appSkeleton'], $appSkeleton);
		else
			$newConfig ['appSkeleton'] = $appSkeleton;

		// -- check unset critical options
		$newConfig['systemConfig']['unconfigured'] = 0;
		foreach ($newConfig['appOptions'] as $i1 => $o1)
		{
			if (!isset ($o1['options']))
				continue;
			foreach ($o1['options'] as $i2 => $o2)
			{
				if (!isset ($o2['importance']))
					continue;
				if (!isset($newConfig['options'][$i1][$o2['cfgKey']]) ||
						(isset($o2['unset']) && $newConfig['options'][$i1][$o2['cfgKey']] == $o2['unset']))
				{
					$newConfig['systemConfig']['unconfigured']++;
				}
			}
		}

		$localConfig = $this->loadConfigFileCore ("config.json", __APP_DIR__ . "/config/");
		mergeCfg ($newConfig, $localConfig);

		// theme
		if (!isset ($newConfig ['appTheme']) || $newConfig ['appTheme'] === '')
			$newConfig ['appTheme'] = 'elegant';
		if (isset ($newConfig ['options']['appearanceApp']['appTheme']))
			if ($newConfig ['options']['appearanceApp']['appTheme'] !== '')
				$newConfig ['appTheme'] = $newConfig ['options']['appearanceApp']['appTheme'];

		// ad data model to config
		$newConfig ['dataModel'] = $this->dataModel->model;

		// configuration ID
		$newConfig ['cfgID'] = time();

		// -- temporary check unconfigured options
		if (!isset ($newConfig ['options']['core']['ownerDomicile']))
			$newConfig ['options']['core']['ownerDomicile'] = 'cz';

		// -- data source info
		$newConfig ['dsi']= utils::loadCfgFile('config/dataSourceInfo.json');
		$newConfig ['dsi']['e10version'] = __E10_VERSION__;

		$cfgServer = utils::loadCfgFile(__SHPD_ETC_DIR__.'/server.json');
		if (!$cfgServer)
			return $this->err ("Server config `".__SHPD_ETC_DIR__.'/server.json'."` not found or is invalid");

		if (!isset($cfgServer['hostingDomain']))
			return $this->err ("Missing 'hostingDomain' field in /etc/shipard/server.json");

		$hosting = [];
		$hosting['hostingDomain'] = $cfgServer['hostingDomain'];
		$hosting['serverDomain'] = $cfgServer['serverDomain'];

		$cfgServer['useHosting'] = $cfgServer['useHosting'];

		$newConfig['hostingCfg'] = $hosting;

		$newConfig ['authServerUrl'] = '';
		$newConfig ['hostingServerUrl'] = '';

		if (isset($hosting['hostingDomain']) && $hosting['hostingDomain'] !== '')
		{
			$newConfig ['authServerUrl'] = 'https://'.$hosting['hostingDomain'].'/';
			$newConfig ['hostingServerUrl'] = 'https://'.$hosting['hostingDomain'].'/';
		}

		// -- data source mode
		if (!isset($newConfig ['develMode']))
			$newConfig ['develMode'] = $cfgServer['develMode'];
		if (!isset($newConfig ['dsMode']))
		{
			if ($newConfig ['develMode'])
				$newConfig ['dsMode'] = Application::dsmDevel;
			elseif (isset($newConfig ['dsi']['dsType']) && $newConfig ['dsi']['dsType'] === 1)
				$newConfig ['dsMode'] = Application::dsmTesting;
			else
				$newConfig ['dsMode'] = Application::dsmProduction;
		}


		// -- post compile ops
		if (isset($newConfig['system']['postUpdateConfig']))
		{
			foreach ($newConfig['system']['postUpdateConfig'] as $ucc)
			$o = $this->createObject($ucc['classId']);
			if ($o)
				$o->postUpdateConfig($newConfig);
		}

		// -- base config dir
		if (!is_dir(__APP_DIR__.'/config'))
		{
			Utils::mkDir (__APP_DIR__.'/config');
		}

		// -- save to temp dir
		$newDir = __APP_DIR__ . "/config/new";
		$currDir = __APP_DIR__ . "/config/curr";
		if (!is_dir($newDir))
		{
			Utils::mkDir ($newDir);
		}
		else
			array_map( "unlink", glob ($newDir . '/*'));

		file_put_contents ($newDir . "/cfg.json", utils::json_lint(json_encode ($newConfig)));
		file_put_contents ($newDir . "/cfg.data", serialize ($newConfig));
		$newCheckSum = md5_file ($newDir . "/cfg.data");

		file_put_contents ($newDir . "/cfgfiles.json", json_encode ($this->loadedConfigFiles));
		file_put_contents ($newDir . "/cfgfiles.data", serialize ($this->loadedConfigFiles));

		file_put_contents ($newDir . "/datamodel.data", serialize ($this->dataModel->model));
		file_put_contents ($newDir . "/datamodel.json", json_encode ($this->dataModel->model));

		// -- languages
		$langs = ['cs', 'en'];
		foreach ($langs as $lang)
		{
			$newConfigLang = $newConfig;

			$this->appCompileConfig_LangDataModel($newConfigLang['dataModel'], $lang);
			$this->appCompileConfig_LangEnums($newConfigLang, $lang);

			//file_put_contents($newDir . "/cfg.{$lang}.json", json::lint($newConfigLang));
			file_put_contents($newDir . "/cfg.{$lang}.data", serialize($newConfigLang));

			//file_put_contents($newDir . "/datamodel.{$lang}.data", serialize($newConfigLang['dataModel']));
			//file_put_contents($newDir . "/datamodel.{$lang}.json", json_encode($newConfigLang['dataModel']));
		}

		// move config to config/curr/
		array_map( "unlink", glob ($currDir . '/*'));
		if (is_dir ($currDir))
			rmdir ($currDir);
		rename ($newDir, $currDir);
		$this->setCfgFilesRights ();

		$this->newConfig = $newConfig;

		return TRUE;
	}

	function appCompileConfig_LangDataModel (&$dataModel, $langId)
	{
		foreach ($dataModel['tables'] as $tableId => &$table)
		{
			$tableIdParts = explode('.', $tableId);
			array_pop($tableIdParts);
			$dictFileName = __SHPD_MODULES_DIR__.'translation/dm/tables/'.implode('/', $tableIdParts).'/'.$tableId.'/'.$tableId.'.'.$langId.'.json';
			$dict = utils::loadCfgFile($dictFileName);

			if (!$dict)
				continue;

			//echo "* $tableId\n";

			if (isset($dict['table']['name']))
			{
				$table['name'] = $dict['table']['name'];
			}

			foreach ($table['cols'] as $colId => &$col)
			{
				if (isset($dict['columns'][$colId]['name']))
					$col['name'] = $dict['columns'][$colId]['name'];
				if (isset($dict['columns'][$colId]['label']))
					$col['label'] = $dict['columns'][$colId]['label'];
			}
		}
	}

	function appCompileConfig_LangEnums (&$newConfig, $langId)
	{
		if ($langId === 'cs')
			return;

		$enums = utils::loadCfgFile(__SHPD_MODULES_DIR__.'translation/enums/_src/enums.json');
		foreach ($enums as $enumId)
		{
			$enumIdParts = array_slice(explode('.', $enumId), 0, 2);
			$fileNameData = __SHPD_MODULES_DIR__.'translation/enums/'.implode('/', $enumIdParts).'/'.$enumId.'.'.$langId.'.data';
			$data = file_get_contents($fileNameData);

			$enumTexts = unserialize($data);

			$parts = explode('.', $enumId);
			$value = NULL;
			$top = $newConfig;
			forEach ($parts as $p)
			{
				if (!isset ($top [$p]))
					continue 2;

				$value = &$top [$p];
				$top = &$top [$p];
			}

			$values = [];
			foreach ($enumTexts as $cfgId => $texts)
			{
				if (!isset($top[$cfgId]))
					continue;
				foreach ($texts as $columnId => $columnText)
				{
					if (!isset($top[$cfgId][$columnId]))
						continue;
					if ($columnText === '')
						continue;

					$values[$enumId.'.'.$cfgId.'.'.$columnId] = $columnText;
				}
			}

			$this->setNodes($values, $newConfig);
		}
	}

	function setNodes($data, &$array)
	{
		$separator = '.'; // set this to any string that won't occur in your keys
		foreach ($data as $name => $value)
		{
			if (strpos($name, $separator) === FALSE)
			{
				$array[$name] = $value;
			}
			else
			{
				$keys = explode($separator, $name);
				$opt_tree =& $array;

				while (1)
				{
					$key = array_shift($keys);
					if ($key === NULL)
						break;
					if ($keys && count($keys))
					{
						if (!isset($opt_tree[$key]) || !is_array($opt_tree[$key]))
						{
							//$opt_tree[$key] = array();
						}
						$opt_tree =& $opt_tree[$key];
					} else {
						$opt_tree[$key] = $value;
					}
				}
			}
		}
	}

	function appCompileConfig_CreateLanguage (&$mainCfg, $dictionary, &$resource, $ownerNodePath)
	{
		foreach ($resource as $resNodeId => $resNodeContent)
		{
			$thisNodeId = str_replace(".","-", $resNodeId);
			$thisTreePath = $ownerNodePath;
			if ($thisTreePath !== '')
				$thisTreePath .= '.';
			$thisTreePath .= $thisNodeId;

			$check = createCheckTranslateHints ($thisTreePath);
			$hints = array_filter ($this->translateHints, $check);
			$thisTranslateHint = array_pop ($hints);

			if (is_array($resNodeContent) && isset ($resNodeContent['#']))
			{
				$thisNodeId = str_replace(".","-", $resNodeContent['#']);
				$thisTreePath = $ownerNodePath;
				if ($thisTreePath !== '')
					$thisTreePath .= '.';
				$thisTreePath .= $thisNodeId;
			}

			if (is_string($resNodeContent))
			{
				if ($thisTranslateHint !== NULL)
				{
					$localized = utils::cfgItem ($dictionary, $thisTreePath, '');
					if ($localized !== '')
						utils::replaceAtTree ($mainCfg, $thisTreePath, $localized);
				}
			}
			else
			if (is_array ($resNodeContent))
				$this->appCompileConfig_CreateLanguage ($mainCfg, $dictionary, $resNodeContent, $thisTreePath);
		}
	} // scanLevel


	public function addConfigFile (&$destCfg, $toCfgId, $cfg, $fullFileName)
	{
		if ($toCfgId == '.')
		{
			$this->msg (2, "appendCoreConfigFile $fullFileName");
			mergeCfg ($destCfg, $cfg);
			return;
		}

		$cfgId = $toCfgId;
		$append = false;

		if ($toCfgId [0] == '+')
		{
			$append = true;
			$cfgId = substr($toCfgId, 1);
			$this->msg (2, "appendConfigFile $fullFileName");
		}
		else
			$this->msg (2, "addConfigFile $fullFileName");

		$parts = explode ('.', $cfgId);

		$value = NULL;
		$top = &$destCfg;
		$finalKey = end ($parts);
		array_pop ($parts);
		forEach ($parts as $p)
		{
			if (isset ($top [$p]))
			{
				$top = &$top [$p];
				continue;
			}
			$top [$p] = array ();
			$top = &$top [$p];
		}

		if ($append && isset ($top [$finalKey]))
			//$top [$finalKey] = array_merge_recursive ($top [$finalKey], $cfg);
			mergeCfg ($top [$finalKey], $cfg);
		else
			$top [$finalKey] = $cfg;
	}


	public function applyExtension ($extCfg)
	{
		forEach ($extCfg as $ext)
		{
			if (isset ($ext['table']))
			{
				$table = $ext;

				if (isset ($ext['module']))
				{
					$moduleExist = utils::searchArray($this->modules, 'id', $ext['module']);
					if (!$moduleExist)
						continue;
				}

				if (!isset ($this->tables [$ext['table']]))
					return $this->err ("invalid extension table '{$ext['table']}' - ".json_encode($extCfg));
				$newTable = &$this->tables [$ext['table']];
				// --columns
				if (isset ($table ['columns']))
					mergeCfg ($newTable ['columns'], $table ['columns']);
				// --lists
				if (isset ($table ['lists']))
				{
					forEach ($table ['lists'] as $list)
					{
						$newTable ['lists'][$list['id']] = $list;
					}
				}
				// --views
				if (isset ($table ['views']))
					mergeCfg ($newTable ['views'], $table ['views']);
				// --forms
				if (isset ($table ['forms']))
				{
					forEach ($table ['forms'] as $form)
					{
						$newTable ['forms'][$form['id']] = $form;
					}
				}
				// --reports
				if (isset ($table ['reports']))
				{
					forEach ($table ['reports'] as $report)
						$newTable ['reports'][$report['id']] = $report;
				}
				// --addWizard
				if (isset ($table ['addWizard']))
				{
					if (isset($newTable ['addWizard']))
						$newTable ['addWizard'] = array_merge($newTable ['addWizard'], $table ['addWizard']);
					else
						$newTable ['addWizard'] = $table ['addWizard'];
				}
				// --docActions
				if (isset ($table ['docActions']))
				{
					forEach ($table ['docActions'] as $docActionId => $docAction)
						$newTable ['docActions'][$docActionId] = $docAction;
				}
				// --indexes
				if (isset ($table ['indexes']))
				{
					forEach ($table ['indexes'] as $index)
						$newTable ['indexes'][] = $index;
				}

				$this->checkSwDev ('load', 'table', $newTable);
			}
		}
	}

	public function load ()
	{
		// load config
		if (!$this->loadConfig())
			return $this->err ("no config found; this is not app folder.");

		// load app skeleton
		if (!$this->loadAppSkeleton())
			return $this->err ("no appSkeleton found");

		// load server module
		$this->loadModules (array ('e10/server'));
		if ($this->cntErrors ())
			return false;

		// modules - from application
		if (isset ($this->appSkeleton ['modules']))
		{
			$this->loadModules ($this->appSkeleton ['modules']);
			if ($this->cntErrors ())
				return false;
		}

		// modules - from modules.json
		if (!$this->loadAppModules())
			return $this->err ("appModules error");
		if (isset ($this->appModules))
		{
			$this->loadModules ($this->appModules);
			if ($this->cntErrors ())
				return false;
		}

		// tables
		$this->loadTables ();
		if ($this->cntErrors ())
			return false;
		$this->loadExtensions ();
		if ($this->cntErrors ())
			return false;

		return true;
	}

	public function loadConfigFileCore ($fileName, $path)
	{
		if (!is_file($path . $fileName))
			return $this->err ("config file '{$path}{$fileName}' not found");

		$cfgString = file_get_contents ($path . $fileName);
		if ($cfgString === '')
		{ // blank file
			return NULL;
		}
		else
		if ($cfgString === '[]')
		{ // blank array
			return [];
		}

		if (!$cfgString)
		{
			echo "Syntax error 1 in file $fileName \n";
			return FALSE;
		}

		$cfg = NULL;
		if (preg_match('/\\.json$/', $fileName))
		{
			$cfg = json_decode ($cfgString, true);
			if ($cfg === NULL)
				return $this->err ("parse file '{$path}{$fileName}' failed; syntax error");
		}

		if (!$cfg)
		{
			echo "Syntax error 2 in file $fileName \n";
			return FALSE;
		}

		if ($fileName != 'config.json')
			$this->loadedConfigFiles [] = array ('fileName' => $fileName, 'path' => $path);

		return $cfg;
	}

	public function loadConfigFile (&$rootCfg, $fileName, $path)
	{
		$cfg = $this->loadConfigFileCore ($fileName, $path);

		/* resolve includes */
		if ($cfg !== FALSE && isset ($cfg ['include']))
		{
			forEach ($cfg ['include'] as $key => $fn)
			{
				$incCfg = $this->loadConfigFile ($rootCfg, $fn, $path);
				if ($incCfg != NULL)
					$this->addConfigFile ($rootCfg, $key, $incCfg, $path . $fn);
			}
		}

		return $cfg;
	}

	public function cfgItem ($key, $defaultValue = '')
	{
		if (isset ($this->appCfg [$key]))
			return $this->appCfg [$key];

		$parts = explode ('.', $key);
		if (!count ($parts))
			return $defaultValue;

		$value = NULL;
		$top = &$this->appCfg;
		forEach ($parts as $p)
		{
			if (isset ($top [$p]))
			{
				$value = &$top [$p];
				$top = &$top [$p];
				continue;
			}
			return $defaultValue;
		}

		return $value;
	}

	public function connectToDb ()
	{
		if ($this->db)
			return TRUE;
		$dboptions = array(
			'driver'   => $this->cfgItem ('db.driver', 'mysqli'),
			'host'     => $this->cfgItem ('db.host', 'localhost'),
			'username' => $this->cfgItem ('db.login'),
			'password' => $this->cfgItem ('db.password'),
			'database' => $this->cfgItem ('db.database'),
			'charset'  => $this->cfgItem ('db.charset', 'utf8mb4'),
			'resultDetectTypes' => TRUE);

		try
		{
			$this->db = new \Dibi\Connection ($dboptions);
		}
		catch (\Dibi\Exception $e)
		{
			$this->err (get_class ($e) . ': ' . $e->getMessage());
			return FALSE;
		}

		return TRUE;
	}

	public function createDataModel ()
	{
		forEach ($this->modules as $module)
			$this->dataModel->addModule ($module ['id'], $module ['name']);
		forEach ($this->tables as $t)
			$this->createDataModelTable ($t);
	}

	public function createDataModelTable ($table)
	{
		$colTypes = array ('string' => DataModel::ctString, 'int' => DataModel::ctInt, 'long' => DataModel::ctLong,
											 'money' => DataModel::ctMoney, 'number' => DataModel::ctNumber,
											 'date' => DataModel::ctDate, 'timestamp' => DataModel::ctTimeStamp, 'time' => DataModel::ctTime, 'timeLen' => DataModel::ctTimeLen,
											 'memo' => DataModel::ctMemo, 'code' => DataModel::ctCode, 'subColumns' => DataModel::ctSubColumns,
											 'int_ai' => DataModel::ctInt,
											 'enumInt' => DataModel::ctEnumInt, 'enumString' => DataModel::ctEnumString,
											 'logical' => DataModel::ctLogical,
											 'short' => DataModel::ctShort);
		$colOptions = [
			'mandatory' => DataModel::coMandatory,
			'saveOnChange' => DataModel::coSaveOnChange,
			'ascii' => DataModel::coAscii,
			'scanner' => DataModel::coScanner,
			'computed' => DataModel::coComputed,
			'ui' => DataModel::coUserInput,
		];
		$newTable = ['sql'=> $table ['sql'], 'name' => $table ['name'], 'cols' => [], 'ndx' => 0];

		if (isset ($table['icon']))
			$newTable ['icon'] = $table ['icon'];
		if (isset ($table['autocomplete']))
			$newTable ['autocomplete'] = $table ['autocomplete'];
		if (isset ($table['indexes']))
			$newTable ['indexes'] = $table ['indexes'];
		if (isset ($table['addWizard']))
			$newTable ['addWizard'] = $table ['addWizard'];
		if (isset ($table['docActions']))
			$newTable ['docActions'] = $table ['docActions'];
		if (isset ($table['documentCard']))
			$newTable ['documentCard'] = $table ['documentCard'];
		if (isset ($table['ndx']))
			$newTable ['ndx'] = $table ['ndx'];

		$tableOptions = [
			'configSource' => DataModel::toConfigSource, 'timelineSource' => DataModel::toTimelineSource,
			'systemTable' => DataModel::toSystemTable, 'disableCopyRecords' => DataModel::toDisableCopyRecords,
			'notifications' => DataModel::toNotifications,
		];
		$newTable ['options'] = 0;
		if (isset ($table['options']))
		{
			forEach ($table['options'] as $option)
			{
				if (isset ($tableOptions[$option]))
					$newTable ['options'] |= $tableOptions[$option];
				else
					$this->err ("Unknown table option '{$option}' (table: {$table ['name']})");
			}
		}


		forEach ($table ['columns'] as $col)
		{
			if (isset ($col['module']) && $this->dataModel->module($col['module']) === FALSE)
					continue;

			$sqlColName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

			$newTable ['cols'][$col ['id']] = array ('sql' => $sqlColName, 'name' => $col['name']);
			if (isset ($col['len']))
				$newTable ['cols'][$col ['id']]['len'] = $col['len'];
			if (isset ($col['dec']))
				$newTable ['cols'][$col ['id']]['dec'] = $col['dec'];
			if (isset ($col['label']))
				$newTable ['cols'][$col ['id']]['label'] = $col['label'];
			if (isset ($col['placeholder']))
				$newTable ['cols'][$col ['id']]['placeholder'] = $col['placeholder'];
			if (isset ($col['subtype']))
				$newTable ['cols'][$col ['id']]['subtype'] = $col['subtype'];

			$newTable ['cols'][$col ['id']]['type'] = $colTypes [$col['type']];

			if (isset ($col['enumCfg']))
				$newTable ['cols'][$col ['id']]['enumCfg'] = $col['enumCfg'];
			else
			if (isset ($col['enumValues']))
				$newTable ['cols'][$col ['id']]['enumValues'] = $col['enumValues'];

			if (isset ($col['enumMultiple']))
				$newTable ['cols'][$col ['id']]['enumMultiple'] = $col['enumMultiple'];

			if (isset ($col['reference']))
				$newTable ['cols'][$col ['id']]['reference'] = $col['reference'];
			if (isset ($col['comboViewer']))
				$newTable ['cols'][$col ['id']]['comboViewer'] = $col['comboViewer'];
			if (isset ($col['comboTable']))
				$newTable ['cols'][$col ['id']]['comboTable'] = $col['comboTable'];
			if (isset ($col['comboClass']))
				$newTable ['cols'][$col ['id']]['comboClass'] = $col['comboClass'];

			$newTable ['cols'][$col ['id']]['options'] = 0;
			if (isset ($col['options']))
			{
				forEach ($col['options'] as $option)
				{
					if (isset ($colOptions[$option]))
						$newTable ['cols'][$col ['id']]['options'] |= $colOptions[$option];
					else
						$this->err ("Unknown column option '{$option}' (table: {$table ['name']}, column: {$col['name']}");
				}
			}
			if (isset ($col['params']))
				$newTable ['cols'][$col ['id']]['params'] = $col['params'];

			if (isset ($col['clientEvents']))
				$newTable ['cols'][$col ['id']]['clientEvents'] = $col['clientEvents'];
		}

		// --lists
		if (isset ($table ['lists']))
		{
			forEach ($table ['lists'] as $list)
			{
				$newTable ['lists'][$list['id']] = $list;
			}
		}
		// --views
		if (isset ($table ['views']))
		{
			forEach ($table ['views'] as $view)
			{
				$newTable ['views'][$view['id']] = $view;
			}
		}
		// --forms
		if (isset ($table ['forms']))
		{
			forEach ($table ['forms'] as $form)
			{
				$newTable ['forms'][$form['id']] = $form;
			}
		}
		// --reports
		if (isset ($table ['reports']))
		{
			forEach ($table ['reports'] as $report)
				$newTable ['reports'][$report['id']] = $report;
		}
		// --trash
		if (isset ($table ['trash']))
			$newTable ['trash'] = $table ['trash'];
		// --states
		if (isset ($table ['states']))
			$newTable ['states'] = $table ['states'];

		$this->dataModel->addTable ($table ['id'], $newTable);
	}


        function getPath ($sourcePath, $targetPath)
        {
            if (!strlen ($targetPath))
                if (PHP_OS == "WINNT")
                    return "C:/";
                else
                    return "";

            if ($targetPath [0] == "/")
                if (PHP_OS == "WINNT")
                    return "C:";
                else
                    return "";
            if (PHP_OS == "WINNT")
            {
                return __APP_DIR__ . "\\";
            }
            else
            {
                $result = "";
                for ($x = 0; $x < substr_count ($sourcePath, "/"); $x++)
                    $result .= "../";
                return $result;
            }
        }

        function checkPathForOS ($path)
        {
            if (PHP_OS == "WINNT")
            {
                while ($pos = strpos ($path, "/"))
                {
                    $path[$pos] = "\\";
                }
            }

            return $path;
        }

	public function dbCheck ()
	{
		if (!$this->connectToDb())
			return FALSE;

		$dbi = $this->db->getDatabaseInfo ();
		forEach ($this->tables as $t)
		{
			$this->dbCheckTable ($dbi, $t);
		}

		$cntChanges = count ($this->sqlCommands, COUNT_RECURSIVE) - count ($this->sqlCommands);
		if ($cntChanges == 0)
			$this->msg (2, "none changes in database were found");
		else
			$this->msg (1, "some changes in database structure (#$cntChanges): ", true);

		forEach ($this->sqlCommands as $cmdClass)
		{
			//print_r ($cmdClass);
			forEach ($cmdClass as $cmd)
			{
				//print_r ($cmd);
				$this->msg (1, ".", true);
				$this->db->query ($cmd);
			}
		}
		if ($cntChanges)
			$this->msg (1, " done.\r\n", true);

		// -- indexes
		$dbi = $this->db->getDatabaseInfo ();
		forEach ($this->tables as $t)
		{
			$this->dbCheckTableIndexes($dbi, $t);
		}

		return TRUE;
	}

	public function dbCheckTable ($dbi, $t)
	{
		if (!$dbi->hasTable ($t ['sql']))
		{
			//echo "* table {$t ['sql']} not exist\r\n";
			//echo $this->sqlCreateTable ($t) . "\r\n";
			$this->sqlCommands ['CT'][] = $this->sqlCreateTable ($t);
			return;
		}

		$a = "`";
		$ti = $dbi->getTable ($t ['sql']);
		$alterTableAddColumn = '';
		$colNdx = 0;
		forEach ($t ['columns'] as $col)
		{
			$sqlColName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

			if (!$ti->hasColumn ($sqlColName))
			{
				if ($colNdx != 0)
					$alterTableAddColumn .= ', ';
				$alterTableAddColumn .= "ADD COLUMN " . $this->sqlTableColumnDefinition ($t, $col);
				$colNdx++;
				continue;
			}

			$colInfo = $ti->getColumn ($sqlColName);
			if ($colInfo->getType () == \Dibi\Type::TEXT && ($col ['type'] == 'string' || $col ['type'] == 'enumString'))
			{
				if ($colInfo->getSize() != $col ['len'])
				{
					if ($colNdx != 0)
						$alterTableAddColumn .= ', ';
					$alterTableAddColumn .= "CHANGE $a{$sqlColName}$a " . $this->sqlTableColumnDefinition ($t, $col);
					$colNdx++;
					continue;
				}
			}
			$nativeType = $colInfo->getNativeType ();
			if ($nativeType === 'TEXT')
			{ // TODO: remove in next version
				if ($colNdx != 0)
					$alterTableAddColumn .= ', ';
				$alterTableAddColumn .= "CHANGE $a{$sqlColName}$a " . $this->sqlTableColumnDefinition ($t, $col);
				$colNdx++;
				continue;
			}
			if ($col ['type'] == 'number')
			{
				$sqlType = $colInfo->getVendorInfo('Type');
				$matches = [];
				$pr1 = preg_match('/[0-9\,]+/', $sqlType, $matches);
				$prParts = explode (',', $matches[0]);
				if ($col['dec'] !== intval($prParts[1]) || 18 !== intval($prParts[0]))
				{ // numeric precision changed...
					if ($colNdx != 0)
						$alterTableAddColumn .= ', ';
					$alterTableAddColumn .= "CHANGE $a{$sqlColName}$a " . $this->sqlTableColumnDefinition ($t, $col);
					$colNdx++;
					continue;
				}
			}
			if ($col ['type'] == 'enumInt' && isset($col['len']) && $col['len'] === 2 && $nativeType !== 'SMALLINT')
			{
				if ($colNdx != 0)
					$alterTableAddColumn .= ', ';
				$alterTableAddColumn .= "CHANGE $a{$sqlColName}$a $a{$sqlColName}$a smallint NULL DEFAULT '0'";
				$colNdx++;
				continue;
			}
			if ($col ['type'] == 'int' && $nativeType !== 'INT')
			{ // columns converted from ctEnumInt to int with reference
				if ($colNdx != 0)
					$alterTableAddColumn .= ', ';
				$alterTableAddColumn .= "CHANGE $a{$sqlColName}$a $a{$sqlColName}$a INT DEFAULT '0'";
				$colNdx++;
				continue;
			}
		}

		if ($alterTableAddColumn != '')
		{
			$cmd = "ALTER TABLE $a{$t ['sql']}$a " . $alterTableAddColumn;
			$this->sqlCommands ['AT'][] = $cmd;
			//echo "* $cmd\r\n";
		}
	}

	public function dbCheckTableIndexes ($dbi, $t)
	{
		if (!$dbi->hasTable ($t ['sql']))
		{
			echo "* table {$t ['sql']} not exist\r\n";
			return;
		}
		$ti = $dbi->getTable ($t ['sql']);

		$dibiIndexes = $ti->getIndexes ();
		$existedIndexes = array ();
		forEach ($dibiIndexes as $index)
		{
			$indexName = $index->getName ();
			if ($indexName === 'PRIMARY')
				continue;

			$thisIndex = array ('name' => $indexName, 'columns' => array());
			$indexColumns = $index->getColumns();
			forEach ($indexColumns as $ic)
				$thisIndex['columns'][] = $ic->getName();
			$existedIndexes[$indexName] = $thisIndex;
		}

		// -- create, drop or alter indexes
		if (isset($t['indexes']))
		{
			forEach ($t['indexes'] as $i)
			{
				$sqlIndexName = $i['id'];
				if (!isset ($existedIndexes[$sqlIndexName]))
				{ // index not exist
					$sql = $this->sqlCreateTableIndex ($t, $i);
					$this->db->query ($sql);
					continue;
				}
				// check changed columns
				$reCreate = FALSE;
				if (count ($i['columns']) !== count($existedIndexes[$sqlIndexName]['columns']))
					$reCreate = TRUE;
				else
				{
					$colNdx = 0;
					forEach ($i['columns'] as $c)
					{
						if ($c !== $existedIndexes[$sqlIndexName]['columns'][$colNdx])
						{
							$reCreate = TRUE;
							break;
						}
						$colNdx++;
 					}
				}
				if ($reCreate)
				{
					$this->db->query ("ALTER TABLE [{$t['sql']}] DROP INDEX [$sqlIndexName]");
					$sql = $this->sqlCreateTableIndex ($t, $i);
					$this->db->query ($sql);
					continue;
				}
			}

			forEach ($existedIndexes as $i)
			{
				$existedIndex = utils::searchArray($t['indexes'], 'id', $i['name']);
				if ($existedIndex === NULL)
				{
					$this->db->query ("ALTER TABLE [{$t['sql']}] DROP INDEX [{$i['name']}]");
				}
			}
		}
	}

	public function loadConfig ()
	{
		// -- load config
		$cfgString = file_get_contents (__APP_DIR__ . "/config/config.json");
		if (!$cfgString)
			return FALSE;
		$this->appCfg = json_decode ($cfgString, true);
		//if (!isset ($this->appCfg ['modulesPath']))
		//	$this->appCfg ['modulesPath'] = __SHPD_MODULES_DIR__;
		return TRUE;
	}

	public function loadAppSkeleton ()
	{
		// -- load app skeleton
		$cfgString = @file_get_contents (__APP_DIR__ . "/config/application.json");
		if (!$cfgString || $cfgString == '')
			$this->appSkeleton  = array ();
		else
			$this->appSkeleton  = json_decode ($cfgString, true);
		return TRUE;
	}

	public function loadAppModules ()
	{
		// -- load app modules
		if (!is_file(__APP_DIR__ . "/config/modules.json"))
			return TRUE;
		$cfgString = file_get_contents (__APP_DIR__ . "/config/modules.json");
		if ($cfgString === FALSE)
		{
			return FALSE;
		}
		if ($cfgString == '')
		{
			$this->appModules = array();
			return TRUE;
		}
		$this->appModules = json_decode ($cfgString, true);

		if (is_file(__APP_DIR__ . "/config/modules-demo.json"))
		{
			$cfgString = file_get_contents (__APP_DIR__ . "/config/modules-demo.json");
			$demoModules = json_decode ($cfgString, TRUE);
			if ($demoModules)
				$this->appModules = array_merge($this->appModules, $demoModules);
		}

		return TRUE;
	}


	public function loadExtensions ()
	{
		// -- modules
		foreach ($this->modules as $m)
		{
			if (!isset ($m ['extensions']))
				continue;
			foreach ($m ['extensions'] as $extId)
			{
				$fileName = $m ['fullPath'] . "extensions/$extId.json";
				$cfgString = file_get_contents ($fileName);
				if (!$cfgString)
				{
					return $this->err ("Cannot read file $fileName");
				}
				$extCfg = json_decode ($cfgString, true);
				if (!$extCfg)
					return $this->err ("Parsing file $fileName failed");
				$this->applyExtension($extCfg);
			}
		}

		// -- application
		if (isset ($this->appSkeleton ['extensions']))
		{
			foreach ($this->appSkeleton ['extensions'] as $extId)
			{
				$fileName = __APP_DIR__ . '/extension/' . $extId . '.json';
				$cfgString = file_get_contents ($fileName);
				if (!$cfgString)
				{
					continue;
				}
				$extCfg = json_decode ($cfgString, true);
				if (!$extCfg)
					return $this->err ("Parsing file $fileName failed");
				$this->applyExtension ($extCfg);
			}
		}
		return TRUE;
	}

	public function loadModules ($moduleList)
	{
		/*
		if (!isset ($this->appSkeleton ['modules']))
		{
			$this->err ("config.json: no 'module' section found");
			return FALSE;
		}*/

		foreach ($moduleList as $m)
		{
			if (isset ($this->loadedModules [$m]))
				continue;

			$fileName = /*$this->cfgItem('modulesPath')*/ __SHPD_MODULES_DIR__ . $m . '/module.json';
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
			{
				echo "Module '$m' not exists\n";
				continue;
			}
			$moduleCfg = json_decode ($cfgString, true);
			$moduleCfg ['fullPath'] = __SHPD_MODULES_DIR__ . $m . '/';
			$this->modules [] = $moduleCfg;
			$this->loadedModules [$m] = $fileName;

			$this->checkSwDev ('load', 'module', $moduleCfg);

			if (isset ($moduleCfg ['modules']))
				$this->loadModules ($moduleCfg ['modules']);
			//echo 'M ' . $fileName . "\r\n";
		}
		//print_r ($this->modules);
		return TRUE;
	}

	public function loadTables ()
	{
		// -- modules
		foreach ($this->modules as $m)
		{
			if (!isset ($m ['tables']))
				continue;
			foreach ($m ['tables'] as $t)
			{
				$fileName = $m ['fullPath'] . "tables/$t.json";
				$cfgString = file_get_contents ($fileName);
				if (!$cfgString)
					return $this->err ("Invalid table structure; file not found: $fileName");

				$tableCfg = json_decode ($cfgString, true);
				if ($tableCfg === NULL)
					return $this->err ("Invalid table structure; syntax error in file $fileName");

				$this->tables [$tableCfg['id']] = $tableCfg;
				$this->checkSwDev ('load', 'table', $tableCfg);
			}
		}
		//print_r ($this->modules);
		//print_r ($this->tables);

		// -- application
		if (isset ($this->appSkeleton ['tables']))
		{
			foreach ($this->appSkeleton ['tables'] as $tableId)
			{
				$fileName = __APP_DIR__ . '/tables/' . $tableId . '.json';

				$cfgString = file_get_contents ($fileName);
				if (!$cfgString)
					return $this->err ("Invalid table structure; file not found: $fileName");

				$tableCfg = json_decode ($cfgString, true);
				if ($tableCfg === NULL)
					return $this->err ("Invalid table structure; syntax error in file $fileName");

				$this->tables [$tableCfg['id']] = $tableCfg;
				//echo 'T2 ' . $tableId . "\r\n";
			}
		}
		return TRUE;
	}

	protected function checkSwDev ($operation, $type, $data)
	{
	}

	public function err ($msg)
	{
		$this->errors [] = $msg;
		if (PHP_SAPI === 'cli')
			echo 'ERROR: ' . $msg . "\r\n";
		else
			error_log ('CFG-ERROR: ' . $msg);
		return FALSE;
	}

	public function cntErrors ()
	{
		return count ($this->errors);
	}

	public function msg ($level, $msg, $disableNewLine = false)
	{
		if ($level > $this->msgLevel)
			return;
		if (PHP_SAPI === 'cli')
		{
			if ($disableNewLine)
				echo $msg;
			else
				echo '* ' . $msg . "\r\n";
		}
		else
			error_log ('CFG-MSG: ' . $msg);
	}

	public function userIsRoot ()
	{
		if (isset ($_SERVER ['USER']))
			return ($_SERVER ['USER'] == 'root');
		return false;
	}

	function setCfgFilesRights ()
	{
		$cfgDir = __APP_DIR__ . '/config/';

		chgrp ($cfgDir . 'curr', utils::wwwGroup());
		forEach (glob ($cfgDir . 'curr/*') as $fn)
			Utils::checkFilePermissions ($fn);
		forEach (glob ($cfgDir . 'appOptions.*') as $fn)
			Utils::checkFilePermissions ($fn);
	}

	function sqlCreateTableIndex ($table, $index)
	{
		$c = 'CREATE ';
		if (isset($index['fullText']))
			$c .= 'FULLTEXT ';
		$c .= "INDEX [{$index['id']}] ON [{$table['sql']}] ";
		$c .= '(['.implode ('], [', $index['columns']) . '])';
		return $c;
	}

	function sqlCreateTable ($table)
	{
		$c = "CREATE TABLE [{$table['sql']}] (";

		$colNdx = 0;
		forEach ($table ['columns'] as $col)
		{
			if ($colNdx != 0)
				$c .= ", ";
			$c .= $this->sqlTableColumnDefinition ($table, $col);
			$colNdx++;
		}
		$c .= ")";

		return $c;
	}


	function sqlTableColumnDefinition ($table, $col)
	{
		$c = '';
		$enumIntType = 'TINYINT DEFAULT 0';
		if (isset($col['len']) && $col['len'] === 2)
			$enumIntType = 'SMALLINT DEFAULT 0';
		elseif (isset($col['len']) && $col['len'] === 4)
			$enumIntType = 'INT DEFAULT 0';

		$colTypes = array ("string" => 'CHAR', "int" => 'INT DEFAULT 0', "long" => 'BIGINT DEFAULT 0',
											 "money" => "NUMERIC", "number" => "NUMERIC",
											 "date" => 'DATE', "timestamp" => 'DATETIME', 'time' => 'CHAR', 'timeLen' => 'INT DEFAULT 0',
											 'memo' => 'MEDIUMTEXT', 'code' => 'MEDIUMTEXT', 'subColumns' => 'MEDIUMTEXT',
											 "int_ai" => "INT AUTO_INCREMENT NOT NULL PRIMARY KEY",
											 "enumInt" => $enumIntType,
											 "enumString" => 'CHAR', "logical" => 'TINYINT DEFAULT 0', 'short' => 'SMALLINT UNSIGNED');

		$sqlColName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

		$c .= '['.$sqlColName.'] ';
		$c .= $colTypes [$col['type']];
		switch ($col['type'])
		{
			case 'string': $c .= "({$col['len']}) DEFAULT \"\""; break;
			case 'enumString': $c .= "({$col['len']}) DEFAULT \"\""; break;
			case 'money': $c .= '(12, 2) DEFAULT 0.0'; break;
			case 'number': $c .= "(18, {$col['dec']}) DEFAULT 0.0"; break;
			case 'time': $c .= "(5) DEFAULT \"\""; break;
		}

		return $c;
	}
}

