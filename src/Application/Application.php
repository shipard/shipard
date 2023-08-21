<?php

namespace Shipard\Application;

use DibiConnection;
use e10\web\WebPages;
use \e10\utils;
use \e10\json;
use \Shipard\Base\Cache;
use \Shipard\Viewer\TableView;


/**
 * Class Application
 */
class Application extends \Shipard\Application\ApplicationCore
{
	var $db;
	var $otherDbs = [];
	var $cache;

	static $appLog;

	public $response;
	/** @var \e10\web\WebPages  */
	public $webEngine = NULL;
	var $appCfg;
	var $printMode = FALSE;
	var $mobileMode = FALSE;
	var $ngg = 0;
	var $remote = '';
	var $appSkeleton;
	var $requestPath;
	var $e10dir;
	var $urlRoot;
	var $dsRoot;
	var $urlProtocol;
	var $https = FALSE;
	var $clientType = [];
	var $oldBrowser = 0;
	var $authenticator = null;
	var $user;
	var $uiUser = NULL;
	var $uiUserContext = NULL;
	var $uiUserContextId = '';
	var $dataModel;
	var $errorCode = 0;
	var $errorMsg;
	var $panel;
	var $threadId;
	var $sessionId = '';
	var $deviceId = '';
	var $deviceInfo = NULL;
	var $apiKey = '';
	var $workplace = FALSE;
	var $params = [];
	static $functions = array ();

	private ?\Shipard\UI\Core\UICore $ui = NULL;
	public function ui()
	{
		return $this->ui ?? $this->ui = new \Shipard\UI\Core\UICore ($this);
	}

	private $userParams = [];

	var $systemLanguages;
	var $userLanguage = 'cs';
	static $userLanguageCode = 'cs';

	const dsmProduction = 0, dsmTesting = 1, dsmDevel = 2;

	public function __construct (?array $cfgServer = NULL)
	{
		parent::__construct($cfgServer);

		self::$appLog = new AppLog ($this);
		self::$appLog->init();
		register_shutdown_function(function () {Application::$appLog->flush();});

		$this->response = new Response($this);

		$this->threadId = mt_rand ();

		ob_start();

		// -- default user
		$this->user = new User ();
		$this->user->app = $this;


		$this->systemLanguages = [
			'cs' => ['id' => 'cs', 'sc' => 'CZ', 'f' => '🇨🇿'],
			'en' => ['id' => 'en', 'sc' => 'EN', 'f' => '🇺🇸', 'beta' => 1],
		];

		// -- load config
		if (!$this->loadConfig())
		{
			$this->setError(500, "uncofigured application");
			return;
		}
		
		self::$appLog->init2();

		// -- load app skeleton
		$this->appSkeleton  = $this->appCfg ['appSkeleton'];
		$this->dataModel = new DataModel ($this->appCfg ['dataModel']);

		$this->cache = new Cache ($this);
		$this->cache->init();

		// -- create db connection
		if ($this->cfgItem ('db', FALSE) != FALSE)
		{
			$this->db = $this->connectDb();
			unset ($this->appCfg ['db']);

			if ($this->db === null)
			{
				$this->setError(500, "internal server error 0xdbce");
				return;
			}
			else
			{
				$this->db->onEvent[] = function (\Dibi\Event $event) {
						Application::$appLog->addSql($event->sql, $event->time);
				};
			}
		}

		// -- parse url for routing
		$url = $_SERVER ["REQUEST_URI"];
		$p = strpos($url, '?');
		if($p !== false)
			$url = substr($url, 0, $p);

		$url = str_replace("//","/",$url);

		$this->dsRoot = strstr($_SERVER['SCRIPT_NAME'], '/index.php', true);
		$this->urlRoot = $this->dsRoot;

		$requestURI = explode ('/', $url);
		$scriptName = explode ('/', $_SERVER['SCRIPT_NAME']);
		$this->requestPath = array_values (array_diff_assoc ($requestURI, $scriptName));

		// https detection
		if (isset ($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on')
			$this->https = TRUE;
		$this->urlProtocol = $this->https ? 'https://' : 'http://';

		// -- detect client type
		$hdrs = utils::getAllHeaders();


		if (isset($hdrs['e10-device-info']))
		{
			$this->deviceInfo = json_decode(base64_decode($hdrs['e10-device-info']), TRUE);
		}
		elseif (isset($hdrs['user-agent']))
			$this->deviceInfo = ['userAgent' => $hdrs['user-agent']];

		if (isset ($hdrs ['e10-old-browser']))
			$this->oldBrowser = 1;

		if (isset ($hdrs ['e10-client-type']))
			$clientType = $hdrs ['e10-client-type'];
		else
		{
			$clientType = 'browser.';

			if (isset ($_SERVER['HTTP_USER_AGENT']))
			{
				$userAgent = $_SERVER['HTTP_USER_AGENT'];
				if (strpos($userAgent, 'iPad') !== FALSE)
				{
					$clientType .= 'tablet.ipad';
					$this->mobileMode = TRUE;
				}
				elseif (strpos($userAgent, 'iPhone') !== FALSE || strpos($userAgent, 'iPod') !== FALSE)
				{
					$clientType .= 'phone.iphone';
					$this->mobileMode = TRUE;
				}
				elseif (strpos($userAgent, 'Android') !== FALSE)
				{
					$this->mobileMode = TRUE;
					if (strpos($userAgent, 'Mobile') !== FALSE || strpos($userAgent, 'Opera Mobi') !== FALSE)
					{
						$clientType .= 'phone.android';
					}
					else
						$clientType .= 'tablet.android';
				}
				elseif (strpos($userAgent, 'Electron') !== FALSE)
				{
					$clientType = 'desktop.electron';
				}
				else
					$clientType .= 'desktop.html5';
			}
			else
				$clientType = 'CLI.' . PHP_OS;
		}

		$this->clientType = explode ('.', $clientType);
		if ($this->clientType[0] === 'mobile')
			$this->mobileMode = TRUE;


		// -- detect deviceId
		if (isset($hdrs['e10-device-id']))
			$this->deviceId = $hdrs['e10-device-id'];
		elseif ($this->testCookie ('_shp_did') !== '')
			$this->deviceId = $this->testCookie ('_shp_did');
		else
			$this->deviceId = $this->testCookie ('e10-deviceId');

		// -- remote
		if (isset ($hdrs ['e10-remote']))
			$this->remote = $hdrs ['e10-remote'];

		// -- authenticator
		if (isset ($this->appSkeleton['userManagement']['authenticator']))
			$this->setAuthenticator ($this->createObject($this->appSkeleton['userManagement']['authenticator']));
	}

	
	function connectDb ($dbId = NULL)
	{
		$dbKey = 'db';
		if ($dbId)
			$dbKey = 'otherDbs.'.$dbId;

		$dboptions = [
				'driver'   => $this->cfgItem ($dbKey.'.driver', 'mysqli'),
				'host'     => $this->cfgItem ($dbKey.'.host', 'localhost'),
				'username' => $this->cfgItem ($dbKey.'.login'),
				'password' => $this->cfgItem ($dbKey.'.password'),
				'database' => $this->cfgItem ($dbKey.'.database'),
				'charset'  => $this->cfgItem ($dbKey.'.charset', 'utf8mb4'),
				'resultDetectTypes' => TRUE,
		];

		$db = NULL;
		try
		{
			$db = new \Dibi\Connection ($dboptions);;
		}
		catch (\Dibi\Exception $e)
		{
			error_log (get_class($e) . ': ' . $e->getMessage());
		}

		if ($db === null)
			$this->setError(500, "internal server error 0xdbce");

		return $db;
	}

	function appOptions () {return $this->cfgItem ('appOptions');}


	function db ($dbId = NULL)
	{
		if (!$dbId)
			return $this->db;

		if (isset ($this->otherDbs[$dbId]))
			return $this->otherDbs[$dbId];

		$this->otherDbs[$dbId] = $this->connectDb($dbId);

		$this->otherDbs[$dbId]->onEvent[] = function (\Dibi\Event $event) {
			Application::$appLog->addSql($event->sql, $event->time);
		};

		return $this->otherDbs[$dbId];
	}

	public function loadConfig ()
	{
		$userLangId = $this->testCookie ('e10-user-language');
		if ($userLangId === '' || !isset($this->systemLanguages[$userLangId]))
			$userLangId = 'cs';

		$this->systemLanguages[$userLangId]['active'] = 1;

		if (is_file(__APP_DIR__ . "/config/curr/cfg.{$userLangId}.data"))
		{
			$this->userLanguage = $userLangId;
			self::$userLanguageCode = $userLangId;
			$cfgString = file_get_contents(__APP_DIR__ . "/config/curr/cfg.{$userLangId}.data");
		}
		else
			$cfgString = file_get_contents (__APP_DIR__ . "/config/curr/cfg.data");
		if (!$cfgString)
			return FALSE;
		$this->appCfg = unserialize ($cfgString);

		$this->appCfg['dsi'] = utils::loadCfgFile(__APP_DIR__.'/config/dataSourceInfo.json');
		$this->appCfg['dsi']['e10version'] = __E10_VERSION__;

		return TRUE;
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
				$top = &$top [$p];//$value;
				continue;
			}
			return $defaultValue;
		}

		return $value;
	}

	public function getUserParam ($param, $defaultValue = FALSE)
	{
		if (isset($this->userParams[$param]))
			return $this->userParams[$param];

		return $defaultValue;
	}

	public function setUserParam ($param, $value)
	{
		if ($value === NULL)
			unset($this->userParams[$param]);
		else
			$this->userParams[$param] = $value;

		$params = base64_encode(json_encode($this->userParams));

		if ($this->https)
			setCookie ('e10-user-params', $params, 0, $this->urlRoot . "/", $_SERVER['HTTP_HOST'], $this->https, 1);
		else
			setCookie ('e10-user-params', $params, 0, $this->urlRoot . "/", '', 0, 1);
	}

	public function updateDeviceInfo ($info = NULL)
	{
		$info = $this->deviceInfo;

		$timeStamp = new \DateTime();
		$ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';

		$clientInfoString = implode('.', $this->clientType). '; ';

		if (isset($info['deviceModel']))
			$clientInfoString .= $info['deviceModel'];
		$clientInfoString .= '; ';

		if (isset($info['osName']))
			$clientInfoString .= $info['osName'];
		$clientInfoString .= '; ';

		if (isset($info['osVersion']))
			$clientInfoString .= $info['osVersion'];
		$clientInfoString .= '; ';

		if (isset($info['userAgent']))
			$clientInfoString .= $info['userAgent'];

		$deviceInfo = ['clientInfo' => $clientInfoString, 'clientTypeId' => implode('.', $this->clientType)];
		if (isset($info['appVersion']))
			$deviceInfo['clientVersion'] = $info['appVersion'];

		// -- search ip address
		$ipaddressndx = 0;
		$ip = $this->db()->query('SELECT * FROM e10_base_ipaddr WHERE docState = 4000 AND ipaddress = %s', $ipAddr)->fetch();
		if (isset ($ip['ndx']))
			$ipaddressndx = $ip['ndx'];

		// -- search device
		$device = $this->db()->query('SELECT * FROM e10_base_devices WHERE id = %s', $this->deviceId)->fetch();
		if (!$device)
		{
			$deviceRec = [
					'id' => $this->deviceId, 'currentUser' => $this->userNdx(), 'lastSeenOnline' => $timeStamp,
					'ipaddress' => $ipAddr, 'ipaddressndx' => $ipaddressndx,
					'docState' => 4000, 'docStateMain' => 2] + $deviceInfo;
			$this->db()->query('INSERT INTO e10_base_devices', $deviceRec);
		}
		else
		{
			$deviceRec = [
					'currentUser' => $this->userNdx(), 'lastSeenOnline' => $timeStamp,
					'ipaddress' => $ipAddr, 'ipaddressndx' => $ipaddressndx
			] + $deviceInfo;
			$this->db()->query('UPDATE e10_base_devices SET ', $deviceRec, ' WHERE ndx = %i', $device['ndx']);
		}
	}

	public function checkAccess ($item)
	{
		$allRoles = $this->cfgItem ('e10.persons.roles');
		$userRoles = $this->user()->data ('roles');

		$accessLevel = 0;

		forEach ($userRoles as $roleId)
		{
			if (!isset($allRoles[$roleId]))
				continue;
			$r = $allRoles[$roleId];

			if (isset ($r['all']) && $r['all'] > $accessLevel)
				$accessLevel = $r['all'];

			if ($item === NULL || !isset ($item['object']))
				continue;

			if ($item['object'] === 'viewer')
			{
				$table = $item['table'];
				$viewer = $item['viewer'];

				if (isset ($r['tables']) && isset ($r['tables'][$table]) && $r['tables'][$table] > $accessLevel)
					$accessLevel = $r['tables'][$table];

				if (isset ($r['viewers']) && isset ($r['viewers'][$table][$viewer]) && $r['viewers'][$table][$viewer] > $accessLevel)
					$accessLevel = $r['viewers'][$table][$viewer];
			}
			else
			if ($item['object'] === 'report')
			{
				$report = $item['class'];
				if (isset ($r['reports']) && isset ($r['reports'][$report]) && $r['reports'][$report] > $accessLevel)
					$accessLevel = $r['reports'][$report];
			}
			else
			if ($item['object'] === 'object')
			{
				$table = $item['table'];

				if (isset ($r['objects']) && isset ($r['objects']['tables'][$table]) && $r['objects']['tables'][$table]['access'] > $accessLevel)
					$accessLevel = $r['objects']['tables'][$table]['access'];
			}
			else
			if ($item['object'] === 'dashboard')
			{
				$accessLevel = 1;
			}
			else
			if ($item['object'] === 'widget')
			{
				if (isset($item['class']) && $item['class'] === 'Shipard.Report.WidgetReports')
				{
					$group = $item['subclass'];
					$reports = $this->cfgItem ('reports', []);

					forEach ($reports as $r)
					{
						if ($r ['group'] != $group)
							continue;
						if (!$this->checkAccess (array ('object' => 'report', 'class' => $r ['class'])))
							continue;
						$accessLevel++;
					}
				}
				else
				if (isset($item['class']) && $item['class'] === 'e10.widgetDashboard')
				{
					$accessLevel = 1;
				}

				if (isset($item['role']))
				{
					if ($this->hasRole($item['role']))
						$accessLevel = 1;
					else
						$accessLevel = 0;
				}
			}
			else
			if ($item['object'] === 'wizard')
			{
				if ($item['class'] === 'lib.cfg.SystemConfigWizard')
					$accessLevel = 1;
			}
			elseif ($item['object'] === 'subMenu')
				$accessLevel = 1;
		}

		return $accessLevel;
	}

	public function checkUserRights ($item, $minimalRole = 'guest')
	{
		if (!$this->authenticator)
			return true;

		$ok = false;
		$auth = array ();

		$headers = utils::getAllHeaders();

		if (isset ($headers['e10-login-user']))
		{
			if (isset ($headers['e10-login-sid']))
			{
				$this->sessionId = $headers['e10-login-sid'];
				$ok = $this->authenticator->authenticateSession ($this, $this->sessionId);
				if (!$ok)
					$this->sessionId = '';
			}

			if (!$ok)
			{
				$auth ['login'] = base64_decode($headers['e10-login-user']);
				if (isset($headers['e10-login-pw']))
					$auth ['password'] = base64_decode($headers['e10-login-pw']);
				else
				if (isset($headers['e10-login-pin']))
					$auth ['pin'] = base64_decode($headers['e10-login-pin']);

				$ok = $this->authenticator->authenticateUser ($this, $auth);
				if ($ok)
				{
					//$this->sessionId = $this->authenticator->startSession ($this, $auth);
				}
			}

			if ($ok)
				$this->user->data ['sid'] = $this->sessionId;
		}
		else
		if (isset($_SERVER['PHP_AUTH_USER']))
		{
			$auth ['login'] = $_SERVER['PHP_AUTH_USER'];
			$auth ['password'] = $_SERVER['PHP_AUTH_PW'];
			$ok = $this->authenticator->authenticateUser ($this, $auth);
		}
		else
		if (isset ($headers['e10-api-key']))
		{
			$ok = $this->authenticator->authenticateApiKey ($this, $headers['e10-api-key']);
			if ($ok)
				$this->apiKey = $headers['e10-api-key'];
		}
		else
		{
			$this->sessionId = $this->testCookie ($this->sessionCookieName ());

			if ($this->sessionId != "")
				$ok = $this->authenticator->authenticateSession ($this, $this->sessionId);
			if ($ok)
				$this->user->data ['sid'] = $this->sessionId;
		}

		if (!$ok && isset ($item['allow']) && $item['allow'] === 'all' && $this->cfgItem ('loginRequired', 0) === 0)
			return TRUE;

		if (!$ok)
			return FALSE;

		if (!$this->authenticator->isSystemPage () && $this->hasRole ('guest') && !isset ($item['allow']))
			return FALSE;

		// deviceId
		if ($this->deviceId === '' && $this->apiKey !== '')
		{
			$this->deviceId = md5($this->apiKey . $_SERVER['REMOTE_ADDR']);
		}

		if ($this->deviceId !== '')
			$this->workplace = $this->searchWorkplace ($this->deviceId);
		else
		{
			$workplaceGID = $this->testCookie ('_shp_gwid');
			if ($workplaceGID !== '')
			{
				$wkp = $this->searchWorkplaceByGID($workplaceGID);
				if ($wkp)
					$this->workplace = $wkp;
			}
		}

		$userParams = $this->testCookie ('e10-user-params');
		if ($userParams !== '')
		{
			$this->userParams = json_decode(base64_decode($userParams), TRUE);
		}

		return TRUE;
	}

	public function searchWorkplace ($deviceId)
	{
		$workplaceGID = $this->testCookie ('_shp_gwid');
		if ($workplaceGID !== '')
		{
			$wkp = $this->searchWorkplaceByGID($workplaceGID);
			if ($wkp)
				return $wkp;
		}

		$founded = FALSE;

		$workplaces = $this->cfgItem('e10.workplaces', FALSE);
		if (!$workplaces)
			return FALSE;
		foreach ($workplaces as $w)
		{
			if (isset($w['devices']) && count($w['devices']) && !in_array($deviceId, $w['devices']))
				continue;

			if (isset($w['allowedFrom']) && count($w['allowedFrom']))
			{
				$enabled = FALSE;
				forEach ($w['allowedFrom'] as $af)
				{
					if ($af === substr($_SERVER['REMOTE_ADDR'], 0, strlen($af)))
					{
						$enabled = TRUE;
						break;
					}
				}
				if ($enabled === FALSE)
					continue;

				return $w;
			}

			$founded = $w;
		}

		return $founded;
	}

	public function searchWorkplaceByGID ($gid)
	{
		$workplaces = $this->cfgItem('e10.workplaces', NULL);
		if (!$workplaces)
			return NULL;

		foreach ($workplaces as $w)
		{
			if (!isset($w['gid']) || $w['gid'] !== $gid)
				continue;

			return $w;
			/*
			if (isset($w['allowedFrom']) && count($w['allowedFrom']))
			{
				$enabled = FALSE;
				forEach ($w['allowedFrom'] as $af)
				{
					if ($af === substr($_SERVER['REMOTE_ADDR'], 0, strlen($af)))
					{
						$enabled = TRUE;
						break;
					}
				}
				if ($enabled === FALSE)
					continue;

				return $w;
			}
			*/
		}

		return NULL;
	}

	public function detectParams ()
	{
		$params = [];

		foreach ($_GET as $key => $value)
		{
			$valueParts = explode(',', $value);
			$paramValue = (count($valueParts) === 1) ? $value : $valueParts;
			if (substr($key, 0, 2) === '--')
			{
				$parts = explode ('-', substr($key, 2));
				if (count($parts) === 2)
					$params[$parts[0]][$parts[1]] = $paramValue;
				else
					$params[$parts[0]] = $paramValue;
			}
			else
				$params[$key] = $paramValue;
		}

		return $params;
	}

	public function hasRole ($role)
	{
		if (!$this->authenticator)
			return false;

		if (is_string($role))
			return $this->authenticator->userHasRole ($this, $role);

		if (is_array ($role) && isset ($role ['role']))
			return $this->authenticator->userHasRole ($this, $role ['role']);

		return false;
	}

	public function loadItem ($ndx, $tableId)
	{
		$table = $this->table ($tableId);
		if (!$table)
			return FALSE;

		return $table->loadItem ($ndx);
	}

	function checkPrimaryKeys($tableId, &$recData, &$errors, $syncSrc = 0)
	{
		$table = $this->table($tableId);
		if (!$table)
		{
			return;
		}
		foreach ($recData as $colId => $colValue)
		{
			$colDef = $table->column ($colId);
			if (!$colDef)
			{
				$errors[] = "INVALID COLUMN: `{$colId}` in table `".$table->tableId()."`";
				continue;
			}
			if ($colDef['type'] !== DataModel::ctInt && $colDef['type'] !== DataModel::ctEnumInt && $colId !== 'ndx')
				continue;
			if (is_string($colValue) && $colValue !== '' && $colValue[0] === '@')
			{
				$dstTableId = '';
				$dstCol = 'id';
				$dstValue = substr($colValue, 1);
				$dstParts = explode(':', $dstValue);
				if (count($dstParts) >= 2)
				{
					$dstCol = $dstParts[0];
					$dstValue = substr($colValue, strlen($dstCol) + 2);
				}
				$dstColParts = explode(';', $dstCol);
				if (count($dstColParts) === 2)
				{
					$dstTableId = $dstColParts[0];
					$dstCol = $dstColParts[1];
				}

				if ($dstTableId === '' && !isset($colDef['reference']) && $colId !== 'ndx')
				{
					$errors[] = "BAD REFERENCE: column {$colId}";
					continue;
				}
				if ($dstTableId !== '')
					$tableRef = $this->table($dstTableId);
				elseif ($colId === 'ndx')
					$tableRef = $table;
				else
					$tableRef = $this->table($colDef['reference']);

				$q = [];
				array_push($q, 'SELECT ndx FROM ['.$tableRef->sqlName ().']');
				array_push($q, ' WHERE ['.$dstCol.'] = %s', $dstValue);
				if ($dstCol === 'syncNdx' && $syncSrc)
					array_push($q, ' AND [syncSrc] = %i', $syncSrc);

				$refRow = $this->db()->query ($q)->fetch();
				if (isset($refRow['ndx']))
					$recData[$colId] = $refRow['ndx'];
				else
				{
					$recData[$colId] = 0;
					$errors[] = "ERROR: primary key for '".$table->tableId()."::{$colId}' not found: '".json_encode($colValue)."' from '".$tableRef->tableId()."::{$dstCol}'";
				}
			}
		}
	}

	public function subColumnValue ($colDef, $value)
	{
		$colType = (is_string($colDef['type'])) ? DataModel::$ctStringTypes[$colDef['type']] : $colDef['type'];
		switch ($colType)
		{
			case DataModel::ctMoney:			return utils::nf ($value, 2);
			case DataModel::ctNumber:			return utils::nf ($value, 2);
			case DataModel::ctDate:				return utils::datef ($value, '%d');
			case DataModel::ctInt:				return ($value === '') ? '' : utils::nf (intval($value));
			case DataModel::ctLong:				return ($value === '') ? '' : utils::nf (intval($value));
			case DataModel::ctEnumString:
			case DataModel::ctEnumInt:
				$values = $this->subColumnEnum ($colDef, 'cfgText');
				if (isset ($values [$value]))
					return $values [$value];
				return '!!!';
			case DataModel::ctLogical:
				return ($value) ? 'Ano' : 'Ne';
			//case DataModel::ctTimeStamp:
			//case DataModel::ctTime:
			//case DataModel::ctEnumString:
			//case DataModel::ctEnumInt:
			//case DataModel::ctMemo:
		}

		return strval($value);
	}


	public function subColumnEnum ($column, $valueType = 'cfgText'/*, \E10\TableForm $form*/)
	{
		$res = [];
		//$column = $form->inputColDef($columnId, $columnPath);

		$enumCfg = NULL;
		if (isset ($column ['enumCfg']) && isset ($column ['enumCfg']['cfgItem']))
			$enumCfg = $this->cfgItem($column ['enumCfg']['cfgItem']);
		//else
		//	$enumCfg = $this->columnInfoEnumSrc ($columnId, $form);

		if ($enumCfg)
		{
			$valueKey = '';
			if (isset ($column ['enumCfg']['cfgValue']))
				$valueKey = $column ['enumCfg']['cfgValue'];

			$textKey = '';
			if (isset ($column ['enumCfg'][$valueType]))
				$textKey = $column ['enumCfg'][$valueType];
			if (($textKey == '') && ($valueType != 'cfgText'))
			{
				if (isset ($column ['enumCfg']['cfgText']))
					$textKey = $column ['enumCfg']['cfgText'];
			}

			forEach ($enumCfg as $key => $item)
			{
				//if (!$this->columnInfoEnumTest ($columnId, $key, $item, $form))
				//	continue;
				$thisText = "";
				if ($textKey == "")
					$thisText = $item;
				else
					$thisText = $item [$textKey];

				$thisValue = "";
				if ($valueKey == "")
					$thisValue = $key;
				else
					$thisValue = $item [$valueKey];


				$res [$thisValue] = $thisText;
			}
			return $res;
		}
		if (isset ($column ['enumValues']))
		{
			forEach ($column ['enumValues'] as $value => $text)
				$res [$value] = $text;
			return $res;
		}

		return $res;
	}

	public function subColumnsCalc (&$data, $sci)
	{
		foreach ($sci['columns'] as $col)
		{
			if (!isset($col['sum']))
				continue;

			$this->subColumnsCalcColumn ($data, $sci, $col);
		}
	}

	public function subColumnsCalcColumn (&$data, $sci, $colDef)
	{
		$total = 0;
		$sumParts = explode(' ', $colDef['sum']);
		foreach ($sumParts as $sumCid)
		{
			$cid = $sumCid;
			$minus = 0;
			if ($sumCid[0] === '-')
			{
				$sumCid = substr ($sumCid, 1);
				$minus = 1;
			}

			if (!isset ($data[$sumCid]))
				continue;

			$total += ($minus) ? - ($data[$sumCid] === '' ? 0 : $data[$sumCid]) : ($data[$sumCid] === '' ? 0 : $data[$sumCid]);
		}

		$data[$colDef['id']] = $total;
	}

	public function userGroups ()
	{
		if (!$this->authenticator)
			return array();

		$groups = $this->user()->data ('groups');
		if ($groups !== FALSE)
			return $groups;

		$groups = $this->authenticator->userGroups ();
		$this->user()->setGroups ($groups);
		return $groups;
	}

	public function webSocketServers ()
	{
		return $this->webSocketServersNew ();
	}

	public function webSocketServersNew ()
	{
		$sce = new \mac\iot\libs\SensorsAndControlsEngine($this);
		$sce->load();
		return $sce->wss;
	}

	public function clientType ($type = 0)
	{
		return $this->clientType [$type];
	}

	public function nativeClient ()
	{
		return ($this->clientType [0] == "native");
	}

	public function model ()
	{
		return $this->dataModel;
	}

	public function modulesPath ()
	{
		return __SHPD_MODULES_DIR__;
	}

	public function notificationsClear ($tableId, $recNdx)
	{
		$q[] = 'UPDATE [e10_base_notifications] SET [state] = 1';
		array_push($q, ' WHERE tableId = %s', $tableId);
		array_push($q, ' AND (recId = %i', $recNdx, ' OR recIdMain = %i)', $recNdx);
		array_push($q, ' AND personDest = %i', $this->user()->data ('id'));

		$this->db()->query ($q);
	}

	public function callFunction ($function, $params = NULL)
	{
		$fullFunctionName = str_replace('.', "\\", $function);
		if (strstr ($function, '.') !== FALSE)
		{
			$parts = explode ('.', $function);
			if ($parts[0] === 'lib')
			{
				$functionName = array_pop($parts);
				$moduleFileName = $this->modulesPath().'/'.implode('/', $parts).'.php';
				if (is_file($moduleFileName))
					include_once($moduleFileName);
				//else
				//	error_log('file not found: ' . $moduleFileName . ' (required for function ' . $function . ')');
				array_pop($parts);
				$fullFunctionName = '\\' . implode ('\\', $parts) . '\\' . $functionName;
			}
			else
			{
				$functionName = array_pop($parts);
				$moduleFileName = $this->modulesPath() . strtolower(implode('/', $parts)) . '/' . end($parts) . '.php';
				if (is_file($moduleFileName))
				{
					include_once($moduleFileName);
					$fullFunctionName = '\\' . implode ('\\', $parts) . '\\' . $functionName;
				}
				else
				{
					$moduleFileName = $this->modulesPath() . implode('/', $parts) . '.php';
					if (is_file($moduleFileName))
					{
						include_once($moduleFileName);
						$fullFunctionName = '\\' . implode ('\\', $parts);
					}
					//else
					//	error_log('file not found: ' . $moduleFileName . ' (required for function ' . $function . ')');
				}
			}
		}

		if (function_exists ($fullFunctionName))
		{
			if ($params !== NULL)
				$r = $fullFunctionName ($this, $params);
			else
				$r = $fullFunctionName ($this);
			return $r;
		}

		error_log ('callFunction failed for for function ' . $function);
		return NULL;
	}

	public function callRegisteredFunction ($type, $functionId, $params = NULL)
	{
		if (isset (self::$functions [$type][$functionId]))
		{
			$functionName = self::$functions [$type][$functionId];
			return $this->callFunction ($functionName, $params);
		}
		$functionName = $this->cfgItem ('registeredFunctions.'.$type.'.'.$functionId, FALSE);
		if ($functionName)
		{

			return $this->callFunction ($functionName, $params);
		}

		error_log ("callRegisteredFunction failed for $type/$functionId");
		return NULL;
	}

	public function createMenu ($pageId)
	{
		return array ();
	}

	public function dsIcon()
	{
		$dsi = $this->cfgItem('dsi');

		$icon = [
			'serverUrl' => $this->cfgItem('dsi.dsIconServerUrl', 'https://shipard.app/'),
			'fileName' => $this->cfgItem('dsi.dsIconFileName', 'templates/bdc10abc-d11f23fc-257c44b5-89c5d480/img/app-icon.png'),
		];

		if (!isset($dsi['dsIconServerUrl']))
			$icon['iconUrl'] = $dsi['dsimage'];
		else
			$icon['iconUrl'] = $icon['serverUrl'].'imgs/-i256/-v2/'.$icon['fileName'];

		return $icon;
	}

	public function addPageCodeParts ($type, $parts, $page, $appWindow = FALSE)
	{
		$absUrl = '';
		$useNewWebsockets = 1;

		$c = '';
		switch ($type)
		{
			case 'jsCfg':
				$dsIcon = $this->dsIcon();
				$wss = $this->webSocketServers ();
				$c .= "\t<script type=\"text/javascript\">\nvar httpApiRootPath = '{$this->urlRoot}';var serverTitle=\"" . Utils::es ($this->cfgItem ('options.core.ownerShortName', '')) . "\";" .
					"var remoteHostAddress = '{$_SERVER ['REMOTE_ADDR']}'; e10ClientType = " . json_encode ($this->clientType) . ";\n";
				$c .= "var g_useMqtt = {$useNewWebsockets};";
				$c .= "var deviceId = '{$this->deviceId}';";
				$c .= "var webSocketServers = ".json_encode($wss).";\n";
				$c .= "var g_e10_appMode = ". (($appWindow) ? '1' : '0') . ";\n";
				$c .= "var g_e10_touchMode = ". (($this->mobileMode) ? '1' : '0') . ";\n";
				$c .= "var e10dsIcon = '{$dsIcon['iconUrl']}';\n";
				$c .= "var e10dsIconServerUrl = '{$dsIcon['serverUrl']}';\n";
				$c .= "var e10dsIconFileName = '{$dsIcon['fileName']}';\n";
				$c .= "var e10embedded = ".intval($this->testGetParam('embeddedApp')).";\n";
				break;
		}

		if ($parts)
		{
			forEach ($parts as $p)
			{
				if ($p ['type'] != $type)
					continue;
				switch ($p ['type'])
				{
					case 'css':
						if (isset ($p['fix']))
							$c .= "\t<!--[if {$p['fix']}]>\n";
						if (isset ($p['file']))
							$c .= "\t<link rel=\"stylesheet\" type=\"text/css\" href=\"$absUrl{$this->dsRoot}/" . $page['themeRoot'] . "/{$p['file']}\"/>\n";
						else
							if (isset ($p['url']))
								$c .= "\t<link rel=\"stylesheet\" type=\"text/css\" href=\"{$p['url']}\"/>\n";
						if (isset ($p['fix']))
							$c .= "\t<![endif]-->\n";
						break;
					case 'js':
						if (isset ($p['file']))
							$c .= "\t<script type=\"text/javascript\" src=\"$absUrl{$this->urlRoot}/{$p['file']}\"></script>\n";
						else
							if (isset ($p['url']))
								$c .= "\t<script type=\"text/javascript\" src=\"{$p['url']}\"></script>\n";
						break;
					case 'jsCfg':
					{
						$cfgItem = Application::cfgItem ($p ['cfg']);
						$c .= "var {$p ['var']} = " . json_encode ($cfgItem) . ";\n";
					}
						break;
				}
			} // forEach ($parts as $p)
		} // if ($parts)

		switch ($type)
		{
			case 'jsCfg': $c .= "\t</script>\n"; break;
		}

		return $c;
	}

	public function createPageCode ($page)
	{
		if (isset ($page['raw']))
			return $page['raw'];

		$style = 'style.css';
		if (isset ($page ['style']))
			$style = $page ['style'];

		$c = $this->createPageCodeOpen ($page);
		$c .= $page ['code'];
		$c .= $this->createPageCodeClose ($page);

		echo $c;
	}

	public function createPageCodeOpen ($page, $appWindow = FALSE)
	{
		$useNewWebsockets = 1;

		$absUrl = '';

		$style = 'style.css';
		if (isset ($page ['css']))
			$style = $page ['css'];
		else
			if (isset ($this->panel ['css']))
				$style = $this->panel ['css'];

		$cfgID = $this->cfgItem ('cfgID');

		$c = "<!DOCTYPE HTML>
<html lang=\"cs\">
<head>
	<title>" . Utils::es ($page ['pageTitle']) . "</title>
	<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n";

		$scRoot = $this->scRoot();

		if ($appWindow)
		{
			$theme = $this->cfgItem ('e10settings.themes.desktop.'.$page ['themeRoot'], FALSE);
			if ($theme === FALSE)
				$theme = $this->cfgItem ('e10settings.themes.desktop.default', FALSE);
			if (1 || $theme['system'])
				$themeUrl = "$absUrl{$this->urlRoot}/www-root/.ui/OldDesktop/themes/" . $page ['themeRoot'] . "/$style?vv={$cfgID}";
			else
				$themeUrl = "$absUrl{$this->urlRoot}/themes/" . $page ['themeRoot'] . "/style.css?v={$cfgID}";

			$c .= "<meta name='robots' content='noindex, nofollow'>\n";

			$dsIcon = $this->dsIcon();
			$c .= "<meta name='mobile-web-app-capable' content='yes'>\n";
			$c .= "<link rel='shortcut icon' sizes='256x256' href='{$dsIcon['iconUrl']}' id='e10-browser-app-icon'>\n";
			$c .= "<link rel='apple-touch-icon' sizes='180x180' href='{$dsIcon['iconUrl']}'/>\n";
			if (($this->clientType [1] === 'tablet') || ($this->clientType [1] === 'phone'))
			{
				$c .= "<meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'/>\n";
				$c .= "<meta name='mobile-web-app-capable' content='yes'>\n";
				$c .= "<meta name='apple-mobile-web-app-capable' content='yes'>\n";
				//$c .= "<meta name='apple-mobile-web-app-status-bar-style' content='black-translucent'>\n";
			}

			$themeStatusColor = '#3c3c3c';//$theme['statusColor'];
			$c .= "<meta name='theme-color' content='$themeStatusColor'>\n";
			$c .= "<link rel=\"manifest\" href=\"{$this->urlRoot}/manifest.webmanifest\">\n";

			$c .= "\t<meta name=\"format-detection\" content=\"telephone=no\">
		<meta name=\"generator\" content=\"E10 ".__E10_VERSION__."\">
		<meta http-equiv=\"X-UA-Compatible\" content=\"IE=Edge; IE=11;\" />
		<link rel=\"stylesheet\" type=\"text/css\" href=\"$themeUrl\"/>
	";

			$pageParts = isset ($this->panel['pageParts']) ? $this->panel['pageParts'] : NULL;
			if ($pageParts)
				$c .= $this->addPageCodeParts ('css', $pageParts, $page);

			$c .= $this->addPageCodeParts ('jsCfg', $pageParts, $page, $appWindow);

			$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/jquery/jquery-2.2.4.min.js\"></script>";

			$iconsCfg = $this->ui()->icons()->iconsCfg;
			$c .= "<link rel='stylesheet' type='text/css' href='{$scRoot}/{$iconsCfg['styleLink']}'>\n";

			if ($useNewWebsockets)
			{
				if ($this->testGetParam('legacyBrowser') === '')
					$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/mqttws/mqttws31.min.js\"></script>\n";
			}

			$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/codemirror/codemirror-4.7.1-min.js\"></script>\n";
			$c .= "<link rel=\"stylesheet\" type=\"text/css\" href=\"{$scRoot}/libs/js/chosen/chosen.css\">\n";
			$c .= "<link rel=\"stylesheet\" type=\"text/css\" href=\"{$scRoot}/libs/js/codemirror/codemirror-4.7.1.css\">\n";
			$c .= "<link rel=\"stylesheet\" type=\"text/css\" href=\"//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.css\">\n";


			if ($this->cfgItem ('develMode', 0) === 0)
			{ // production
				$files = unserialize (file_get_contents(__SHPD_ROOT_DIR__.'/ui/clients/files.data'));
				$c .= "\t<script type='text/javascript' integrity='{$files['OldDesktop']['client.js']['integrity']}' src='$absUrl{$this->urlRoot}/www-root/.ui/OldDesktop/js/client.js?v=".$files['OldDesktop']['client.js']['ver']."'></script>\n";
			}
			else
			{ // development
				$jsFiles = utils::loadCfgFile(__SHPD_ROOT_DIR__.'/ui/clients/OldDesktop/js/package.json');
				foreach ($jsFiles['srcFiles'] as $sf)
				{
					$cs = md5_file(__APP_DIR__."/www-root/ui-dev/clients/OldDesktop/js/{$sf['fileName']}");
					$c .= "\t<script type=\"text/javascript\" src=\"{$this->urlRoot}/www-root/ui-dev/clients/OldDesktop/js/{$sf['fileName']}?v=$cs\"></script>\n";
				}
			}

			$c .= $this->addPageCodeParts ('js', $pageParts, $page, $appWindow);
		}
		else
		{
			$c .= "\t<script type=\"text/javascript\" src=\"//ajax.googleapis.com/ajax/libs/jquery/1.8/jquery.min.js\"></script>\n";
			$c .= "\t<link rel=\"stylesheet\" type=\"text/css\" href=\"$absUrl{$this->dsRoot}/" . $page ['themeRoot'] . "/$style?{$cfgID}\"/>\n";

			$pageParts = isset ($this->panel['pageParts']) ? $this->panel['pageParts'] : NULL;
			$c .= $this->addPageCodeParts ('js', $pageParts, $page, $appWindow);
		}

		$bodyClass = "e10-body-{$this->requestPath[0]} body-device-{$this->clientType[1]} body-client-{$this->clientType[0]} oriHorz";
		if (isset ($page['params']['bodyClass']))
			$bodyClass .= ' '.$page['params']['bodyClass'];

		$c .= "</head>\n<body class='$bodyClass'>";
		return $c;
	}

	public function createPageCodeClose ($page)
	{
		return "</body></html>";
	}

	public function createObject ($class)
	{
		$fullClassName = $class;
		if (strstr ($class, '.') !== FALSE)
		{
			$parts = explode ('.', $class);
			$className = array_pop ($parts);
			$moduleFileName = $this->modulesPath() . implode ('/', $parts) . '/' . end($parts) . '.php';
			if (!is_file ($moduleFileName))
				$moduleFileName = $this->modulesPath() . strtolower(implode('/', $parts)) . '/' . end($parts) . '.php';

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

	public function createMainPage ($pageId)
	{
		return "";
	}

	function iconPath ($icon, $place)
	{
		$pth = $this->urlRoot . '/' . $this->cfgItem('style') . '/icons/';
		$pth .= $icon . '-' . $place . '.png';
		//$pth .= $icon . '-all.svg';
		return $pth;
	}


	public function table ($tableId)
	{
		if (isset($tableId [0]) && $tableId [0] === '_')
		{
			$tableClassName = "\\E10\\" . substr ($tableId, 1);
			return new $tableClassName ($this);
		}
		else
			if (strstr ($tableId, '.') !== FALSE)
			{
				$parts = explode ('.', $tableId);
				$cnt = count ($parts);
				$parts [$cnt - 1] = 'Table' . $parts [$cnt - 1];
				$tableClassName = '\\' . implode ('\\', $parts);
			}
			else
				$tableClassName = "Table" . $tableId;

		if (class_exists ($tableClassName))
		{
			$t = new $tableClassName ($this);
			return $t;
		}
		return NULL;
	}

	public function tableByNdx ($tableNdx)
	{
		if (!isset($this->dataModel->model['tablesByNdx'][$tableNdx]))
			return NULL;

		return $this->table($this->dataModel->model['tablesByNdx'][$tableNdx]);
	}

	public function documentCard ($tableId, $recNdx, $objectType)
	{
		$table = $this->table($tableId);
		$recData = $table->loadItem ($recNdx);

		$documentCard = $table->documentCard ($recData, $objectType);
		$documentCard->setDocument ($table, $recData);

		return $documentCard;
	}

	public function viewer ($tableId, $viewId, $queryParams = NULL)
	{
		$table = $this->table($tableId);
		$v = NULL;

		$viewClass = '';
		$vd = NULL;

		if (strstr ($viewId, '.') === FALSE)
		{
			$vd = $table->viewDefinition ($viewId);
			if (!$vd)
				$vd = $table->viewDefinition ('default');
			if ($vd)
				$viewClass = $vd ['class'];
		}
		else
			$viewClass = $viewId;

		$table->loadModuleFile ($viewClass);
		$className = str_replace ('.', '\\', $viewClass);
		$v = new $className ($table, $viewId, $queryParams);

		return $v;
	}

	function testCookie ($cookieName)
	{
		if (isset ($_COOKIE [$cookieName]))
			return $_COOKIE [$cookieName];
		return "";
	}

	function testGetParam ($paramName, $paramName2="")
	{
		if (isset ($_GET [$paramName]))
			return $_GET [$paramName];
		if (isset ($_GET [$paramName2]))
			return $_GET [$paramName2];
		return '';
	}

	function testPostParam ($paramName, $defaultValue = '')
	{
		if (isset ($_POST [$paramName]))
			return $_POST [$paramName];
		return $defaultValue;
	}

	function testGetData ()
	{ // TODO: refactor to postData
		$data = "";
		$handle = fopen('php://input','r');
		$data = fgets($handle);
		fclose ($handle);
		return $data;
	}

	function postData ()
	{
		$data = '';
		$handle = fopen('php://input','r');
		while (1)
		{
			$buffer = fgets($handle, 4096);
			if (strlen($buffer) === 0)
				break;
			$data .= $buffer;
		}
		fclose ($handle);
		return $data;
	}

	function production()
	{
		$dsMode = intval($this->cfgItem ('dsMode', self::dsmTesting));
		return ($dsMode === self::dsmProduction);
	}

	static function registerFunction ($type, $functionId, $functionName)
	{
		self::$functions [$type][$functionId] = $functionName;
	}

	public function requestPath ($idx = -1)
	{
		if ($idx == -1)
			return '/' . implode ('/', $this->requestPath);
		elseif ($idx == -2)
			return (count($this->requestPath)) ? $this->requestPath [count($this->requestPath) - 1] : '';
		if (isset ($this->requestPath [$idx]))
			return $this->requestPath [$idx];
		return "";
	}

	public function scRoot()
	{
		return $this->dsRoot.'/www-root/sc';
	}

	function uploadFile ()
	{
		$uploadClassId = $this->requestPath (1);

		// -- new workflow temp hack
		if ($uploadClassId === 'e10pro.wkf.messages')
			$uploadClassId = 'wkf.core.issues';

		/** @var  $uploadTable \e10\DbTable */
		$uploadTable = $this->table ($uploadClassId);
		$data = $uploadTable->upload ();

		header("Content-type: text/plain");
		header('Content-Length: ' . strlen($data));
		echo $data;
		return NULL;
	}

	protected function feed ()
	{
		self::$appLog->setTaskType(AppLog::ttHttpFeed, AppLog::tkNone);

		$feedId = $this->requestPath(1);

		header ('Access-Control-Allow-Origin: *');

		$feedClass = $this->cfgItem ('registeredClasses.feed.'.$feedId, FALSE);
		if ($feedClass !== FALSE)
		{
			$classId = $feedClass['classId'];
			$listObject = $this->createObject($classId);
			$listObject->init();
			return $listObject->run ();
		}

		return new Response($this, "unknown feed", 404);
	}

	protected function incomingWebhook()
	{
		$o = new \integrations\hooks\in\services\Receiver($this);
		$o->createResponseContent($this->response);

		return $this->response;
	}

	function routeApi ()
	{
		$headers = utils::getAllHeaders();
		$origin = (isset($headers['origin'])) ? $headers['origin'] : '';
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Origin: '.$origin);
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
			header('Access-Control-Allow-Headers: Origin, e10-remote, e10-client-type, e10-old-browser, e10-login-sid, X-Requested-With');
			header("P3P: CP='CAO PSA OUR'"); // Makes IE to support cookies
			exit(0);
		}

		if ($origin !== '')
		{
			header('Access-Control-Allow-Origin: '.$origin);
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
		}

		header ("Cache-control: no-store");

		$object = isset($this->requestPath [1]) ? $this->requestPath [1] : '';
		if ($object === 'v2')
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkApiV2);
			return $this->routeApiV2();
		}

		if (!$this->checkUserRights (NULL, 'user'))
			return new Response ($this, 'need authentication', 403);

		self::$appLog->setUser();

		if (isset($this->requestPath [2]) && $this->requestPath [2] !== 'hosting.core.libs.api.getNewDataSource' && $this->requestPath [2] !== 'e10.base.NotificationCentre') // TODO: cleanup
			touch (__APP_DIR__.'/tmp/api/access/'.$this->user->data ['id'].'_'.$_SERVER ['REMOTE_ADDR'].'_'.$this->deviceId);

		$object = isset($this->requestPath [1]) ? $this->requestPath [1] : '';
		header ("Cache-control: no-store");

		if ($object === '')
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkApiRun);
			return $this->routeApiRun();
		}

		if ($object == "call")
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkCall);
			$functionName = '';
			if (isset ($this->requestPath [2]))
				$functionName = $this->requestPath [2];
			$response = $this->callFunction ($functionName);
			return $response;
		}
		if ($object == "widget")
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkWidget);
			if (isset ($this->requestPath [2]))
				$widgetClass = $this->requestPath [2];
			$wdgt = $this->createObject ($widgetClass);
			if ($wdgt !== NULL)
				return \e10\createWidgetResponse ($this, $wdgt);
		}
		if ($object == "wizard")
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkWizard);
			if (isset ($this->requestPath [2]))
				$wizardClass = $this->requestPath [2];
			$wzrd = $this->createObject ($wizardClass);
			if ($wzrd)
				$wzrd->app = $this;
			return \e10\createWizardResponse ($this, $wzrd);
		}
		if ($object === 'window')
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkWindow);
			if (isset ($this->requestPath [2]))
				$windowClass = $this->requestPath [2];
			$window = $this->createObject ($windowClass);
			return \e10\createWindowResponse ($this, $window);
		}
		if ($object === 'f')
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkF);
			if (isset ($this->requestPath [2]))
				$formClass = $this->requestPath [2];
			$form = $this->createObject ($formClass);
			if ($form)
				return $form->response();
		}
		if ($object === 'd')
			return $this->apiDocumentCard();
		if ($object == "report")
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkReport);
			$reportClass = $this->requestPath [2];
			$formReport = $this->createObject ($reportClass);
			$formReport->app = $this;
			if ($formReport)
			{
				$formReport->init ();
				$formReport->renderReport ();
				$formReport->createReport ();
				return \e10\createReportResponse ($this, $formReport);
			}
		}
		if ($object === 'objects')
		{
			self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkObjects);
			$objectsManager = new \lib\objects\ObjectsManager ($this);
			return $objectsManager->run ();
		}

		$tableId = $this->requestPath [2];
		$view = $this->requestPath [3];
		$format = "";
		if (isset ($this->requestPath [5]))
			$format = $this->requestPath [5];
		else
			if (isset ($this->requestPath [4]))
				$format = $this->requestPath [4];

		//$object, $table, $view, $format, $pk=""
		$table = $this->table ($tableId);
		if ($table != NULL)
		{
			if ($object == "viewer")
			{
				self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkViewer);

				$queryParams = NULL;
				$dataObjectParams = $this->testGetParam ('data-object-params');
				if ($dataObjectParams !== '')
				{
					$parts = explode (';', $dataObjectParams);
					foreach ($parts as $oneParam)
					{
						$pp = explode (':', $oneParam);
						if (count($pp) === 2)
						{
							if ($queryParams === NULL)
								$queryParams = [];
							$queryParams[$pp[0]] = $pp[1];
						}
					}
				}
				$view = $table->getTableView ($view, $queryParams);
				if ($view->ok)
				{
					$view->renderViewerData ($format);
					return \e10\createViewerResponse ($this, $view, $format, Application::testGetParam ("fullCode"));
				}
			}
			if ($object == "detail")
			{
				self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkViewerDetail);
				$viewId = $this->requestPath [3];
				$detailId = $this->requestPath [4];
				$pk = $this->requestPath [5];
				$detailData = $table->getDetailData ($viewId, $detailId, $pk);

				if ($detailData->ok)
				{
					return \e10\createDetailResponse ($this, $detailData, Application::testGetParam ("fullCode"));
				}
			}
			if ($object == "viewerpanel")
			{
				self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkViewerPanel);
				$viewId = $this->requestPath [3];
				$panelId = $this->requestPath [4];
				$panel = $table->getViewerPanel ($viewId, $panelId);
				return \e10\createViewerPanelResponse ($this, $panel);
			}
			if ($object == "list")
			{ // api/list/table/attachments/widget/1234
				$pk = $this->requestPath [5];
				$listId = $this->requestPath [3];
				$listOp = $this->requestPath [4];
				$listData = $table->getListData ($listId, $listOp, $pk);

				if ($listData->ok)
				{
					return \e10\createListResponse ($this, $listData);
				}
			}
			if ($object == "formreport")
			{
				self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkFormReport);
				$pk = $this->requestPath [4];
				$reportClass = $this->requestPath [3];
				$formReport = $table->getReportData ($reportClass, $pk);

				if ($formReport)
				{
					$formReport->renderReport ();
					$formReport->createReport ();
					return \e10\createReportResponse ($this, $formReport);
				}
			}
			if ($object == "form")
			{
				self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkForm);
				$pk = "";
				if (isset ($this->requestPath [4]))
					$pk = $this->requestPath [4];
				$formData = $table->getTableForm ($view, $pk);

				if ($formData->ok)
				{
					$formData->doFormData ();
					$formData->createCode ();
					return \e10\createFormResponse ($this, $formData, $format);
				}
			}
			return new Response ($this, "invalid object name", 404);
		}

		return new Response ($this, "invalid table name", 404);
	}

	function routeApiRun()
	{
		$requestParamsStr = $this->postData();
		if ($requestParamsStr === '')
		{
			return new Response ($this, "blank request", 404);
		}

		$requestParams = json_decode($requestParamsStr, TRUE);
		if (!$requestParams)
		{
			return new Response ($this, "invalid request data", 404);
		}

		$objectClassId = isset($requestParams['object-class-id']) ? $requestParams['object-class-id'] : '';
		if ($objectClassId === '')
		{
			return new Response ($this, "no object-class-id param", 404);
		}

		/** @var \e10\E10ApiObject $o */
		$o = $this->createObject($objectClassId);
		if (!$o)
		{
			return new Response ($this, "invalid object-class-id param", 404);
		}
		$o->setRequestParams($requestParams);
		$o->createResponseContent($this->response);

		return $this->response;
	}

	function routeApiV2()
	{
		$requestParamsStr = $this->postData();
		if ($requestParamsStr === '')
		{
			return new Response ($this, "blank request", 404);
		}

		$requestParams = json_decode($requestParamsStr, TRUE);
		if (!$requestParams)
		{
			return new Response ($this, "invalid request data", 404);
		}

		$o = new \Shipard\Api\v2\Router($this);
		$o->setRequestParams($requestParams);
		return $o->run();
	}

	function routeDisplay ()
	{
		$this->mobileMode = TRUE;

		$router = $this->createObject ('ui.display.Router');
		return $router->run ();
	}

	function routeMobile ()
	{
		$router = $this->createObject ('Shipard.UI.OldMobile.Router');
		return $router->run ();
	}

	function routeUI ($uiId = NULL)
	{
		$router = $this->createObject ('Shipard.UI.ng.Router');
		$router->setUIId($uiId);
		return $router->run ();
	}

	protected function apiDocumentCard ()
	{
		self::$appLog->setTaskType(AppLog::ttHttpApi, AppLog::tkDocumentCard);

		$documentTableId = $this->requestPath(2);
		$documentNdx = intval($this->requestPath(3));

		$documentCard = $this->documentCard($documentTableId, $documentNdx, 0);
		if ($documentCard)
		{
			return $documentCard->response();
		}

		return new Response ($this, "invalid url", 404);
	}

	public function createLoginRequest ()
	{
		/*if (0)
		{
			$r = new Response ($this);
			$r->add ("responseType", 1);
			$r->add ("objectType", "loginPrompt");
			$r->add ("message", "Access disabled, please login...");
			return $r;
		}*/
		$loginPath = "/" . $this->appSkeleton['userManagement']['pathBase'] . "/" . $this->appSkeleton['userManagement']['pathLogin'];
		$fromPath = implode ('/', $this->requestPath);

		header ('Location: ' . $this->urlProtocol . $_SERVER['HTTP_HOST']. $this->urlRoot . $loginPath . "/" . $fromPath);
		return new Response ($this, "access disabled, please login...", 302);
	} // createLoginRequest


	function topMenuAll ()
	{
		$menu = [];

		if (isset ($this->panel['order']) && $this->panel['order'] == -1)
		{
			$menu[] = $this->panel;
			return $menu;
		}

		$tm = \e10\sortByOneKey($this->appSkeleton ['panels'], 'order', true);
		forEach ($tm as $topMenuId => $topMenuItem)
		{
			if ($topMenuItem ['objectType'] !== 'panel')
				continue;
			if (!utils::enabledCfgItem ($this, $topMenuItem))
				continue;
			if (isset ($topMenuItem ['hidden']))
				continue;
			if (isset ($topMenuItem ['checkWorkplace']))
			{
				if (!$this->workplace || !$this->workplace['useTerminal'])
					continue;
			}
			if (isset ($topMenuItem['order']) && $topMenuItem['order'] == -1)
				continue;
			if (isset ($topMenuItem['role']) && !$this->hasRole($topMenuItem['role']))
				continue;

			$m = $topMenuItem;
			unset ($m ['items']);
			$m ['items'] = [];

			$menuItems = $this->panelItems($topMenuItem);
			forEach ($menuItems as $menuId => $menuItem)
			{
				if (!$this->checkAccess($menuItem))
					continue;
				$m ['items'][] = $menuItem;
			}
			if (count($m ['items']))
				$menu[] = $m;
		}
		return $menu;
	}

	function topMenuFirst ()
	{
		$menu = $this->topMenuAll();
		if (isset($menu[0]['url']))
			return $menu[0]['url'];

		return '/' . $this->appSkeleton['userManagement']['pathBase'] . '/' . $this->appSkeleton['userManagement']['pathLogin'];
	}

	public function checkZone ()
	{
		if (!isset($this->panel ['zone']))
			return FALSE;

		if ($this->cfgItem ('develMode', 0) === 1)
			return FALSE;

		if ($this->panel ['zone'] === 'sec' && $this->urlProtocol !== 'https://')
		{
			$url = $this->cfgItem ('hostingServerUrl') . $this->cfgItem ('dsid');
			header ('Location: ' . $url);
			return TRUE;
		}

		return FALSE;
	}

	public function panelMenu ($type = 'items')
	{
		$pm = array ();
		if (isset ($this->panel [$type]))
		{
			$menuItems = $this->panelItems($this->panel, $type);
			foreach ($menuItems as $oneItem)
			{
				if (!utils::enabledCfgItem ($this, $oneItem))
					continue;
				if (!$this->checkAccess ($oneItem))
					continue;
				$pm [] = $oneItem;
			}
		}
		return $pm;
	}

	function panelItems ($panel, $type = 'items')
	{
		$items = [];
		if (!isset($panel [$type]))
			return $items;

		$miUid = 1;

		foreach ($panel [$type] as $oneItem)
		{
			if (isset($oneItem['creatorClass']))
			{
				$creatorObject = $this->createObject($oneItem['creatorClass']);
				if ($creatorObject)
				{
					$creatorObject->run();
					if (isset($creatorObject->panelContent['items']))
						$items = array_merge($items, $creatorObject->panelContent['items']);
					unset($creatorObject);
				}
			}
			else
			{
				$oneItem['miUid'] = 'app-lm-'.$miUid;

				$this->panelItemsSubMenu ($oneItem);
				$items[] = $oneItem;

				$miUid++;
			}
		}

		return \e10\sortByOneKey($items, 'order');
	}

	function panelItemsSubMenu (&$srcItem)
	{
		if (!isset($srcItem['subMenu']))
			return;

		$subMenu = [];

		foreach ($srcItem['subMenu']['items'] as $smId => $sm)
		{
			if (isset($sm['creatorClass']))
			{
				$creatorObject = $this->createObject($sm['creatorClass']);
				if ($creatorObject)
				{
					$creatorObject->run();
					if (isset($creatorObject->subMenuContent['items']))
						$subMenu = array_merge($subMenu, $creatorObject->subMenuContent['items']);
					unset($creatorObject);
				}
			}
			else
			{
				if ($this->checkAccess($sm))
					$subMenu[] = $sm;
			}
		}

		$srcItem['subMenu']['items'] = \e10\sortByOneKey($subMenu, 'order', TRUE);
	}

	public function route ()
	{
		if ($this->errorCode)
		{
			return new Response ($this, $this->errorMsg, $this->errorCode);
		}

		$nonAppsHosts = $this->cfgItem ('e10.web.servers.nonAppsHosts', NULL);
		$uiDomains = $this->cfgItem ('e10.ui.domains', NULL);
		if ($nonAppsHosts && in_array($_SERVER['HTTP_HOST'], $nonAppsHosts))
		{
			$page = $this->callFunction ('e10.web.checkWebPage');
			$response = new Response ($this, $page ['code'], $page ['status']);
			if (isset ($page['mimeType']))
				$response->setMimeType($page['mimeType']);
			return $response;
		}
		elseif ($uiDomains && isset($uiDomains[$_SERVER['HTTP_HOST']]))
		{
			return $this->routeUI ($uiDomains[$_SERVER['HTTP_HOST']]);
		}
		elseif ($this->requestPath [0] === 'www' && $nonAppsHosts && in_array($this->requestPath [1], $nonAppsHosts))
		{
			$page = $this->callFunction ('e10.web.createWebPageSec');
			$response = new Response ($this, $page ['code'], $page ['status']);
			if (isset ($page['mimeType']))
				$response->setMimeType($page['mimeType']);
			return $response;
		}

		if ($this->authenticator)
		{
			$ar = $this->authenticator->doIt ();
			if ($ar)
				return $ar;
		}

		switch ($this->requestPath [0])
		{
			case 'api':
				return $this->routeApi ();
			case 'mapp':
				return $this->routeMobile ();
			case 'ui':
				return $this->routeUI ();
			case 'dspl':
				return $this->routeDisplay ();
			case 'feed':
				return $this->feed ();
			case 'hooks':
				return $this->incomingWebhook();
			case 'imgs':
				return \e10\getImage ($this);
			case 'upload':
				return $this->uploadFile ();
			case 'manifest.webmanifest':
				return $this->webManifest();
			case 'robots.txt':
				return $this->webRobotsTxt();
			case 'sw.js':
				$this->serviceWorker(); return NULL;
		}

		// search route node
		$panel = NULL;

		if (!$panel)
		{
			foreach ($this->appSkeleton ["panels"] as $p)
			{
				if (isset($p['host']) && $p['host'] !== $_SERVER['HTTP_HOST'])
					continue;
				if (isset($p ["url"]) && ($p ["url"] == $this->requestPath ()))
				{
					$panel = $p;
					break;
				}
			}
		}

		if (!$panel)
		{
			$panelOrder = PHP_INT_MAX;
			foreach ($this->appSkeleton ["panels"] as $p)
			{
				if (isset($p['host']) && $p['host'] !== $_SERVER['HTTP_HOST'])
					continue;
				if (isset($p ["urlRegExp"]) && preg_match ($p ["urlRegExp"], $this->requestPath ()) === 1)
				{
					$o = (isset ($p['order'])) ? intval($p['order']) : PHP_INT_MAX - 1;

					if ($o <= $panelOrder)
					{
						$panel = $p;
						$panelOrder = $o;
					}
				}
			}
		}

		// -- check app panels
		$systemPage = FALSE;
		$page = array ();
		$page ['status'] = 404;
		$page ['themeRoot'] = $this->cfgItem('appTheme');
		if ($panel)
		{
			$this->panel = &$panel;

			if ($this->checkZone ())
				return new Response ($this, "...", 301);

			if ($this->authenticator)
				$systemPage = $this->authenticator->isSystemPage ();

			if (!$this->checkUserRights ($panel) && (!$systemPage))
				return $this->createLoginRequest ();

			if (isset ($panel ["redirect"]))
			{
				$rto = $panel ["redirect"];
				if ($rto === '*')
					$rto = $this->topMenuFirst ();
				elseif ($rto === '')
					$rto = $this->requestPath() . '/';
				header ('Location: ' . $this->urlProtocol . $_SERVER['HTTP_HOST'] . $this->urlRoot . $rto);
				return new Response ($this, "...", 301);
			}

			if ($panel ["objectType"] === 'panel' && $this->cfgItem('systemConfig.unconfigured', 0) != 0) // need startup wizard?
			{
				$panel = $this->appSkeleton ['panels']['startupConfig'];
				$this->panel = &$panel;
			}

			$rightBarCode = "";
			if (isset ($panel ["rightBar"]))
				$rightBarCode = \call_user_func ($panel ["rightBar"], $this);
			$leftBarCode = "";
			if (isset ($panel ["leftBar"]))
				$leftBarCode = \call_user_func ($panel ["leftBar"], $this);
			$page ['code'] = '';
			$page ['pageTitle'] = isset ($panel ["name"]) ? $panel ["name"] : '';
			if ($panel ["objectType"] === 'panel')
			{
				self::$appLog->setTaskType(AppLog::ttHttpAppPanel, AppLog::tkNone);

				$browser = new \lib\core\app\MainWindow($this);
				$page ['code'] = $browser->createBrowserCode ($page, $rightBarCode, $leftBarCode);
				$page ['status'] = 200;
			}
			elseif ($panel ['objectType'] === 'embedd')
			{
				$browser = new \lib\core\app\MainWindow ($this);

				if ($this->requestPath [2] === 'widget')
					$page ['code'] = $browser->createBrowserCode ($page, '', '', TRUE);
				else
					$page ['code'] = $browser->createBrowserCodeEmbedd($page);

				$page ['status'] = 200;
			}
			elseif ($panel ['objectType'] === 'function')
			{
				$page = $this->callFunction ($panel ["function"]);
			}
			$page ['panel'] = $panel;
			$response = new Response ($this, $page ['code'], $page ['status']);
			if (isset ($page['mimeType']))
				$response->setMimeType($page['mimeType']);
			return $response;
		}
		return new Response ($this, "invalid path, page not found", 404);
	}


	public function run ()
	{
		$response = $this->route ();
		if ($response)
			$response->send ();
		ob_flush();
	}

	public function setAuthenticator ($a)
	{
		$this->authenticator = $a;
	}

	public function setError ($code, $msg)
	{
		$this->errorCode = $code;
		$this->errorMsg = $msg;
		error_log ("FATAL ERROR: $code $msg");
		return false;
	}

	public function sessionCookieName ()
	{
		if ($this->cfgServer['useHosting'])
			$id = '_shp_sid_'.str_replace('.', '_', $this->cfgItem('hostingCfg.hostingDomain'));
		else	
			$id = '_shp_sid_'.str_replace('.', '_', $this->cfgItem('hostingCfg.serverDomain'));

		return $id;
	}

	public function sessionCookieDomain ()
	{
		if ($this->cfgServer['useHosting'])
		{
			$hp = explode('.', $_SERVER['HTTP_HOST']);
			$hpr = array_reverse($hp);
			$activeDomain = $hpr[1].'.'.$hpr[0];

			return $activeDomain;
		}

		return $this->cfgServer['serverDomain'];
	}

	public function sessionCookiePath ()
	{
		if (!$this->cfgServer['useHosting'])
			return $this->dsRoot;
		return '/';
	}

	public function setCookie (string $name, string $value, int $expires)
	{
		$options = [
			'expires' => $expires, 
			'path' => $this->sessionCookiePath(), 
			'domain' => $this->sessionCookieDomain(), 
			'secure' => TRUE, 
			'httponly' => TRUE,
			'samesite' => 'strict',
		];

		return \setCookie($name, $value, $options);
	}

	public function user ()
	{
		return $this->user;
	}

	public function userNdx ()
	{
		if (isset($this->user))
			return $this->user->data('id');
		return 0;
	}

	public function uiUserNdx ()
	{
		if (isset($this->uiUser))
			return $this->uiUser['ndx'] ?? 0;
		return 0;
	}

	public function uiUserContext ()
	{
		if ($this->uiUserContext)
			return $this->uiUserContext;

		$contextCreator = new \e10\users\libs\UserContextCreator($this);
		$contextCreator->setUserNdx($this->uiUserNdx());
		$contextCreator->run();

		$this->uiUserContext = $contextCreator->contextData;
		$this->detectUIUserContext();

		return $this->uiUserContext;
	}

	public function setUIUserContext($contextId)
	{
		setCookie ('shp-user-context', $contextId, 0, $this->urlRoot . "/", $_SERVER['HTTP_HOST'], 1, 1);
	}

	public function detectUIUserContext()
	{
		if (!$this->uiUserContext || !isset($this->uiUserContext['contexts']) || !count($this->uiUserContext['contexts']))
			return;

		$this->uiUserContextId = $_COOKIE ['shp-user-context'] ?? '';

		if (!isset($this->uiUserContext['contexts'][$this->uiUserContextId]))
		{
			if (count($this->uiUserContext['contexts']))
			{
				$this->uiUserContextId = key($this->uiUserContext['contexts']);
				setCookie ('shp-user-context', $this->uiUserContextId, 0, $this->urlRoot . "/", $_SERVER['HTTP_HOST'], 1, 1);
			}
		}

		if (isset($this->uiUserContext['contexts'][$this->uiUserContextId]))
			$this->uiUserContext['contexts'][$this->uiUserContextId]['active'] = 1;

		$this->uiUserContext['activeContextId'] = $this->uiUserContextId;
	}

	public function addLogEvent ($event)
	{
		if (!isset($event ['eventTitle']))
		{
			$table = $this->table($event['tableid']);
			$recData = $table->loadItem($event['recid']);
			$recInfo = $table->getRecordInfo ($recData);
			$event ['eventTitle'] = $recInfo ['title'];
		}
		$ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';
		$event ['ipaddress'] = $ipAddr;
		$event ['created'] = new \DateTime;
		$event ['deviceId'] = $this->deviceId;
		if (!isset($event ['user']))
			$event ['user'] = $this->userNdx();

		$this->db()->query ('INSERT INTO [e10_base_docslog]', $event);
	}

	function serviceWorker()
	{
		$dsMode = $this->cfgItem ('dsMode', Application::dsmTesting);
		header ('Content-type: text/javascript', TRUE);

		if ($dsMode !== Application::dsmDevel)
			header ('X-Accel-Redirect: ' . $this->urlRoot.'/e10-modules/.cfg/mobile/e10swm.js');
		else
			header ('X-Accel-Redirect: ' . $this->urlRoot.'/e10-client/lib/js/e10-service-worker.js');
		die();
	}

	function webManifest()
	{
		$themeId = $this->cfgItem('appTheme');
		$theme = $this->cfgItem ('e10settings.themes.desktop.'.$themeId, FALSE);
		if ($theme === FALSE)
			$theme = $this->cfgItem ('e10settings.themes.desktop.default', FALSE);

		$themeStatusColor = '';//$theme['statusColor'];
		$dsIcon = $this->dsIcon();

		$wm = [
			'name' => $this->cfgItem ('options.core.ownerShortName', 'TEST'),
			'short_name' => $this->cfgItem ('options.core.ownerShortName', 'TEST'),
			'start_url' => $this->urlRoot.'/',
			'display' => 'standalone',
			'background_color' => $themeStatusColor,
			'theme_color' => $themeStatusColor,
			'scope' => '/',
			'icons' => [],
		];

		if (substr($dsIcon['iconUrl'], -4, 4) === '.svg')
		{
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i192/'.$dsIcon['fileName'], 'sizes' => '192x192', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i512/'.$dsIcon['fileName'], 'sizes' => '512x512', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i1024/'.$dsIcon['fileName'], 'sizes' => '1024x1024', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i1980/'.$dsIcon['fileName'], 'sizes' => '1980x1980', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].$dsIcon['fileName'], 'type' => 'image/svg+xml'];
		}
		else
		{
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i192/'.$dsIcon['fileName'], 'sizes' => '192x192'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i512/'.$dsIcon['fileName'], 'sizes' => '512x512'];
		}

		$code = json::lint($wm);

		return new Response ($this, $code, 200);
	}

	function webRobotsTxt()
	{
		$code = "Disallow: /\n";
		return new Response ($this, $code, 200);
	}
}


Application::RegisterFunction ('template', 'thisWorkplace', 'e10.thisWorkplace');


Application::RegisterFunction ('template', 'dataView', 'Shipard.Application.runDataView');

function runDataView ($app, $params)
{
	$pp = [];
	foreach ($params as $key => $value)
	{
		if (is_string($value))
			$pp[$key] = $value;
	}

	$res = '';
	$showParam = \E10\searchParam ($params, 'show', '');
	$varName = \E10\searchParam($params, 'var', '');

	$classId = \E10\searchParam ($params, 'classId', '');
	if ($classId === 'documentCard')
		$classId = 'lib.dataView.DataViewDocumentCard';
	elseif ($classId === 'documentCard')
		$classId = 'lib.dataView.DataView';

	if ($classId !== '')
	{
		$o = $app->createObject($classId);
		if (!$o)
		{
			$res = \Shipard\Utils\MiniMarkdown::render('Invalid `classId` param.');
			return $res;
		}

		$o->setTemplate($params ['owner']);
		$o->setRequestParams($pp);
		$o->run();

		if ($varName !== '')
		{
			unset ($params ['owner']->data[$varName]);
			$params ['owner']->data[$varName] = $o->data;
		}

		$res = $o->errorsHtml();

		if ($showParam === '')
			$res .= $o->data['html'];
	}
	else
	{
		$res = \Shipard\Utils\MiniMarkdown::render('Missing `classId` param.');
	}

	return $res;
}


Application::RegisterFunction ('template', 'tr', 'e10.trDict');
