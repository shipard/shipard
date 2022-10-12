#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());


if ($argv[1] !== 'app-walk')
{
	$cfgServerString = file_get_contents ('config/_server_channelInfo.json');
	if (!$cfgServerString)
	{
		echo "ERROR: file `config/_server_channelInfo.json` not found.\n";
		exit(100);
	}
	$cfgServer = json_decode ($cfgServerString, true);
	if (!$cfgServer)
	{
		echo "ERROR: file `config/_server_channelInfo.json` is not valid.\n";
		exit(101);
	}

	define('__SHPD_ROOT_DIR__', $cfgServer['serverInfo']['channelPath']);
}

if (!defined ('__SHPD_ROOT_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__SHPD_ROOT_DIR__', '/'.implode('/', $parts).'/');
}

require_once __SHPD_ROOT_DIR__ . '/src/boot.php';


use \Shipard\CLI\Application, \e10\utils, \e10\DataModel, \e10\json;


/**
 * Class E10_App
 */

class E10_App extends Application
{
	var $quiet = FALSE;

	public function msg ($msg)
	{
		if (!$this->quiet)
			echo '* ' . $msg . "\r\n";
	}

	public function appDemo ()
	{
		$cfgString = file_get_contents ('config/createApp.json');
		$initConfig = json_decode ($cfgString, TRUE);

		$installModuleId = str_replace ('.', '/', $initConfig['createRequest']['installModule']);
		$installModulePath = __SHPD_MODULES_DIR__ . $installModuleId;
		$cfgString = file_get_contents ($installModulePath . '/' . 'module.json');
		if (!$cfgString)
			return;

		$installModule = json_decode ($cfgString, TRUE);
		if (!isset ($installModule['demoData']))
			return $this->err("Demo data missing in module '{$initConfig['installModule']}'");

		$demoPackage = 'default';

		if ($this->arg('fast'))
			$this->params['demoFastMode'] = 1;

		$generateDemoData = 0;

		if (isset($installModule['demoData'][$demoPackage]['demoModule']))
		{
			if (!is_file('config/modules-demo.json'))
			{
				file_put_contents ('config/modules-demo.json', "[\"{$installModule['demoData'][$demoPackage]['demoModule']}\"]");
				passthru('shpd-server app-fullupgrade');
				passthru('shpd-app appDemo --type='.$demoPackage);
				return;
			}
			$generateDemoData = 1;
		}

		forEach ($installModule['demoData'][$demoPackage]['data'] as $dataPkg)
		{
			$pkgFileName = __SHPD_MODULES_DIR__.$dataPkg.'.json';
			$installer = new \lib\DataPackageInstaller ($this);
			$installer->setFileName($pkgFileName);
			$installer->run();
		}

		passthru('shpd-server app-fullupgrade');

		if ($generateDemoData)
		{
			$today = new \DateTime();
			$today->sub(new \DateInterval('P366D'));
			passthru('shpd-app demo --max-days=367 --date-begin='.$today->format('Y-m-d'));
		}

		utils::setAppStatus ('');
	}

	public function appTask ()
	{
		$taskNdx = intval($this->arg ('taskNdx'));
		if (!$taskNdx)
			return $this->err("Missing '--taskNdx' param");

		$tableTasks = $this->table('e10.base.tasks');
		$tableTasks->runTask ($taskNdx);
	}

	public function appOptions ()
	{
		$cfgKey = $this->arg ("key");
		if ($cfgKey === FALSE)
			return $this->err("Missing '--key=some.cfgKey' param");
		$cfgValue = $this->arg ("value");
		if ($cfgValue === FALSE)
			return $this->err("Missing '--value=\"some value\"' param");

		$cfgKeyParts = explode ('.', $cfgKey);
		$options = $this->cfgItem ('appOptions.'.$cfgKeyParts[0].'.options', FALSE);

		$o = utils::searchArray($options, 'cfgKey', $cfgKeyParts[1]);

		if ($o === NULL)
			return $this->err("cfgKey 'appOptions.$cfgKey' not exist'");

		$tableAppOptions = new \E10\TblAppOptions ($this);

		$fileName = $tableAppOptions->appOptionFileName ($cfgKeyParts[0], $o);
		$cfg = utils::loadCfgFile($fileName);
		if ($cfg === FALSE)
			return $this->err("file $fileName not found");

		$cfg[$cfgKeyParts[1]] = $cfgValue;

		file_put_contents($fileName, utils::json_lint (json_encode ($cfg)));
		chmod($fileName, 0660);
	}

	public function appWalk ()
	{
		$dsroot = $this->cfgServer['dsRoot'];
		chdir($dsroot);

		$paramsArray = $_SERVER ['argv'];
		unset ($paramsArray [1]);
		$cmd = implode (' ', $paramsArray);

		forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
			if (is_file($appDir.'/.disable-upgrade'))
				continue;
			if (is_file ($appDir.'/config/config.json'))
			{
				$this->msg ("---- $appDir");
				chdir ($appDir);
				passthru ($cmd);
				chdir ('..');
			}
		}
	}

	public function demo()
	{
		$e = new \demo\core\libs\MakeHistory($this);
		$e->init();
		$e->run();
	}

	public function importFile()
	{
		$fileName = $this->arg ('file');
		if (!$fileName)
			return $this->err("Missing '--file=fileWithData.json' param");
		if (!is_readable($fileName))
			return $this->err("File `{$fileName}` not exist");

		$apiKey = $this->arg ('apiKey');
		if (!$apiKey)
			return $this->err("Missing '--apiKey=API-KEY' param");

		$url = $this->arg ('url');
		if (!$url)
			return $this->err("Missing '--url=https://example.shipard.app' param");

		$tableId = $this->arg ('tableId');
		if (!$tableId)
			return $this->err("Missing '--tableId=e10.table.id' param");

		$stringData = file_get_contents($fileName);
		if (!$stringData)
			return $this->err("File `{$fileName}` is invalid");

		$data = json_decode($stringData, TRUE);
		if (!$data)
			return $this->err("File `{$fileName}` is not valid JSON");

		$ce = new \lib\objects\ClientEngine($this);
		$ce->apiUrl = $url;
		$ce->apiKey = $apiKey;
		$ce->uploadDocs($tableId, $data);

	}

	public function cfgUpdate ()
	{
		\E10\updateConfiguration ($this);
	}



	public function dumpCfg ()
	{
		$cfgId = $this->arg ("id");
		if ($cfgId === FALSE)
			return $this->err("Missing '--id=cfg.tree.id' param");

		$cfg = $this->cfgItem($cfgId, FALSE);

		echo (json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
		echo "\n\n";
	}

	public function generateBooks ()
	{
		$sectionId = $this->arg ('section-id');
		$pageId = $this->arg ('page-id');

		if ($sectionId === FALSE && $pageId === FALSE)
			return $this->err ('Missing --section-id or --page-id param...');

		$dicFile = $this->arg ('dictFile');
		$dicVariant = $this->arg ('dictVariant');
		$destSubFolder = $this->arg ('destSubFolder');

		$booksNdxs = [];


		$bg = new lib\documentation\BookGenerator($this);

		if ($pageId !== FALSE)
		{
			$bg->addSrcBookPage(intval($pageId));
		}
		if ($sectionId !== FALSE)
		{
			$bg->addSrcBookSection(intval($sectionId));
		}

		if ($dicFile)
			$bg->setOptions($dicFile, $dicVariant, $destSubFolder);

		$bg->generateBook();

		return TRUE;
	}

	public function installDataPackage ()
	{
		$fullFileName = '';

		$fileName = $this->arg ("file");
		if ($fileName !== FALSE)
			$fullFileName = $fileName;
		else
		{
			$packageId = $this->arg ("id");
			if ($packageId !== FALSE)
				$fullFileName = __SHPD_MODULES_DIR__.$packageId.'.json';
		}

		if ($fullFileName === '')
			return $this->err("Missing '--file=/path/to/file.json' OR '--id=pkgs/data/cz/test/person1' param");

		$installer = new \lib\DataPackageInstaller ($this);
		$installer->setFileName($fullFileName);
		$installer->run();
	}	// installDataPackage

	public function initRestoredDemo()
	{
		$request = $this->loadCfgFile('config/createApp.json');
		if (!$request)
		{
			return $this->err ('File `config/createApp.json` is invalid');
		}

    $this->db()->query('DELETE FROM [e10_persons_sessions]');
    $this->db()->query('DELETE FROM [e10_persons_userspasswords]');

    $updateAdmin = [
      'fullName' => $request['admin']['fullName'],
      'complicatedName' => $request['admin']['complicatedName'],
      'beforeName' => $request['admin']['beforeName'],
      'firstName' => $request['admin']['firstName'],
      'middleName' => $request['admin']['middleName'],
      'lastName' => $request['admin']['lastName'],
      'afterName' => $request['admin']['afterName'],
      'login' => $request['admin']['login'],
      'loginHash' => md5(strtolower(trim($request['admin']['login']))),
    ];
    $this->db()->query('UPDATE [e10_persons_persons] SET ', $updateAdmin, ' WHERE [ndx] = %i', 1);


		$coreOptions = $this->loadCfgFile('config/appOptions.core.json');
		if ($coreOptions)
		{
			$coreOptions['ownerPhone'] = '+420 123 456 789';
			$coreOptions['ownerEmail'] = 'info@example.com';
			$coreOptions['ownerWeb'] = 'shipard.org';
			$coreOptions['ownerVATID'] = $request['createRequest']['vatId'];
			$coreOptions['firstFiscalYearMonth'] = 1;
			$coreOptions['vatPeriod'] = 1;
			$coreOptions['ownerBankAccount'] = '1234567/8901';
			$coreOptions['ownerLegalRegInfo'] = 'Registrace u Rejstříkového soudu v Praze, značka DEMO-1234-ABCDE';

			file_put_contents('config/appOptions.core.json', Json::lint($coreOptions));
		}

		return TRUE;
	}

	public function localAccount ()
	{
		$email = $this->arg ('email');
		if (!$email)
			return $this->err ('missing argument --email=user@some.email');

		$suggestedPassword = $this->arg ('password');

		$loginHash = md5(strtolower(trim($email)));
		$userRec = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE docStateMain <= 2 AND loginHash = %s', $loginHash)->fetch();
		if (!$userRec)
			return $this->err ("user with email '{$email}' not exist");

		//if ($userRec['accountType'] == 1)
		//	return $this->err ("account with email '{$email}' is local");

		if ($userRec['company'] == 1)
			return $this->err ("account with email '{$email}' is company");

		if ($suggestedPassword)
			$newPwd = $suggestedPassword;
		else
			$newPwd = base_convert (mt_rand (1000000, 9999999), 10, 35).base_convert (mt_rand (1000000, 9999999), 10, 35);
		$this->db()->query ('UPDATE [e10_persons_persons] SET accountType = 1, accountState = 1 WHERE loginHash = %s', $loginHash);
		$this->db()->query ('DELETE FROM [e10_persons_userspasswords] WHERE [pwType] = 0 AND [emailHash] = %s', $loginHash);
		$this->db()->query ('DELETE FROM [e10_persons_sessions] WHERE person = %i', $userRec['ndx']);

		$salt = sha1 ('--' . time() . "--" . $userRec['fullName'] . '--' . mt_rand () . $loginHash);
		$password = $this->authenticator->passwordHash ($newPwd);

		$newPassword = [
			'person' => $userRec['ndx'], 'emailHash' => $loginHash,
			'salt' => $salt, 'password' => $password, 'pwType' => 0, 'version' => 1,
		];
		$this->db()->query ('INSERT INTO [e10_persons_userspasswords]', $newPassword);

		echo "new password is $newPwd \n";
	}

	public function addLocalAccount ()
	{
		$email = $this->arg ('email');
		if (!$email)
			return $this->err ('missing argument --email=user@some.email');
		$name = $this->arg ('name');
		if (!$name)
			return $this->err ('missing argument --name="John Doe"');
		$password = $this->arg ('password');
		if (!$password)
			return $this->err ('missing argument --password="Very Secret P4ssw0rd"');

		$loginHash = md5(strtolower(trim($email)));


		$userRec = [
			'lastName' => $name, 'fullName' => $name, 'personType' => 1, 'accountType' => 1, 'roles' => 'user',
			'login' => $email, 'loginHash' => $loginHash, 'docState' => 4000, 'docStateMain' => 2, 'accountState' => 1
			];
		$this->db()->query ('INSERT INTO [e10_persons_persons]', $userRec);
		$userNdx = intval ($this->db()->getInsertId ());

		$newPwd = $password;

		$salt = sha1 ('--' . time() . "--" . $userRec['fullName'] . '--' . mt_rand () . $loginHash);
		$password = $this->authenticator->passwordHash($newPwd);

		$newPassword = [
			'person' => $userNdx, 'emailHash' => $loginHash,
			'salt' => $salt, 'password' => $password, 'pwType' => 0, 'version' => 1,
		];
		$this->db()->query ('INSERT INTO [e10_persons_userspasswords]', $newPassword);
	}

	public function makeAdmin ()
	{
		$email = $this->arg ('email');
		if (!$email)
			return $this->err ('missing argument --email=user@some.email');

		$loginHash = md5(strtolower(trim($email)));
		$userRec = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE docStateMain <= 2 AND loginHash = %s', $loginHash)->fetch();
		if (!$userRec)
			return $this->err ("user with email '{$email}' not exist");

		if ($userRec['company'] == 1)
			return $this->err ("account with email '{$email}' is company");

		$newRoles = $userRec['roles'].'.admin';

		$this->db()->query ('UPDATE [e10_persons_persons] SET roles = %s', $newRoles, ' WHERE ndx = %i', $userRec['ndx']);
	}

	public function apiKey ()
	{
		$email = $this->arg ('email');
		if (!$email)
			return $this->err ('missing argument --email=user@some.email');

		$loginHash = md5(strtolower(trim($email)));
		$userRec = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE loginHash = %s', $loginHash)->fetch();
		if (!$userRec)
			return $this->err ("user with email '{$email}' not exist");

		if ($userRec['company'] == 1)
			return $this->err ("account with email '{$email}' is company");

		$apiKeySrc = '';
		for ($step = 0; $step < mt_rand (10, 50); $step++)
			$apiKeySrc = base_convert (mt_rand (1000000, 9999999), 10, 35).base_convert (mt_rand (1000000, 9999999), 10, 35);

		$newPwd = base_convert (mt_rand (1000000, 9999999), 10, 35).base_convert (mt_rand (1000000, 9999999), 10, 35);
		$salt = sha1 ('--' . time() . "--" . $userRec['fullName'] . '--' . mt_rand () . $loginHash);
		$password = sha1 ($newPwd . $salt);

		$apiKey = ['person' => $userRec['ndx'], 'salt' => $salt, 'password' => $password, 'emailHash' => sha1($apiKeySrc), 'pwType' => 1];
		$this->db()->query ('INSERT INTO [e10_persons_userspasswords]', $apiKey);

		echo "new password is $newPwd \n";
	}

	public function userPin ()
	{
		$email = $this->arg ('email');
		if (!$email)
			return $this->err ('missing argument --email=user@some.email');

		$loginHash = md5(strtolower(trim($email)));
		$userRec = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE loginHash = %s', $loginHash)->fetch();
		if (!$userRec)
			return $this->err ("user with email '{$email}' not exist");

		if ($userRec['company'] == 1)
			return $this->err ("account with email '{$email}' is company");

		$newPwd = '';
		for ($step = 0; $step < mt_rand (10, 50); $step++)
			$newPwd = sprintf('%04d', mt_rand (700, 9999));

		$salt = sha1 ('--' . time() . "--" . $userRec['fullName'] . '--' . mt_rand () . $loginHash);
		$password = sha1 ($newPwd . $salt);

		$pin = ['person' => $userRec['ndx'], 'salt' => $salt, 'password' => $password, 'emailHash' => $loginHash, 'pwType' => 2];
		$this->db()->query ('DELETE FROM [e10_persons_userspasswords] WHERE emailHash = %s', $loginHash, ' AND pwType = 2');
		$this->db()->query ('INSERT INTO [e10_persons_userspasswords]', $pin);

		echo "new PIN is $newPwd \n";
	}

	public function userChangeLogin ()
	{
		$params = ['srcLogin' => '', 'dstLogin' => '', 'personId' => ''];
		$srcLogin = $this->arg ('src-login');
		if ($srcLogin)
			$params['srcLogin'] = $srcLogin;
		$dstLogin = $this->arg ('dst-login');
		if ($dstLogin)
			$params['dstLogin'] = $dstLogin;
		$personId = $this->arg ('person-id');
		if ($personId)
			$params['personId'] = $personId;

		$e = new \lib\hosting\UserLoginChangeHosting($this);
		$e->setParams($params);
		$e->run();

		$msgs = $e->messagess ();
		if ($msgs !== FALSE)
		{
			foreach ($msgs as $m)
			{
				echo '* '.$m['text']."\n";
			}
		}
	}

	public function createShare ()
	{
		$class = $this->arg ('class');
		if (!$class)
			return $this->err ('missing argument --class=path.to.Class');

		$ndx = $this->arg ('ndx');

		$generator = $this->createObject($class);
		if ($generator === NULL)
			return $this->err ('invalid class');

		$params = ['srcNdx' => intval($ndx)];

		foreach ($this->arguments as $argId => $argValue)
		{
			if (substr($argId, 0, 5) === 'param')
				$params[substr($argId, 6)] = $argValue;
		}

		$generator->init ();
		$generator->setCoreParams ($params);

		$generator->run ();
	}

	public function saveShare ()
	{
		$shareId = $this->arg ('shareId');
		if (!$shareId)
			return $this->err ('missing argument --shareId=shareid');

		$sharePackager = $this->createObject('e10.share.SharePackager');
		if ($sharePackager === NULL)
			return $this->err ('error');

		$params = ['shareId' => $shareId];

		$sharePackager->init ();
		$sharePackager->setCoreParams ($params);

		$sharePackager->run ();
	}

	public function sendBulkEmails ()
	{
		$e = new \lib\wkf\SendBulkEmailEngine($this);
		$e->sendAllBulkEmails();
	}

	public function generateBankOrders ()
	{
		$generator = $this->createObject('lib.docs.BankOrderGenerator');

		$params = [];
		$docTypeParam = $this->arg ('docType');
		if ($docTypeParam)
			$params['docType'] = $docTypeParam;

		$generator->setParams ($params);
		$generator->run ();
	}

	public function uploadBankOrder ()
	{
		$orderNdx = $this->arg ('orderNdx');
		if (!$orderNdx)
			return $this->err("Missing '--orderNdx' param.");

		\lib\ebanking\upload\UploadBankOrder::upload($this, $orderNdx);
	}

	public function downloadBankStatements ()
	{
		$bankAccounts = $this->cfgItem ('e10doc.bankAccounts', []);
		foreach ($bankAccounts as $ba)
		{
			if (!isset($ba['ds']) || $ba['ds'] === '' || $ba['ds'] === 'none')
				continue;

			$ebankingCfg = $this->cfgItem ('ebanking.downloads.'.$ba['ds'], FALSE);
			if ($ebankingCfg === FALSE || !isset($ebankingCfg['class']))
				continue;
			$engine = $this->createObject($ebankingCfg['class']);
			if (!$engine)
				continue;
			$engine->setBankAccount ($ba);
			$engine->init ();
			$engine->run ();
		}
	}

	public function refreshWebPages ()
	{
		$tablePages = $this->table ('e10.web.pages');
		$rows = $this->db()->query ("SELECT * from [e10_web_pages] WHERE [pageType] = 0");
		forEach ($rows as $row)
		{
			$tablePages->checkBeforeSave ($row);
			$this->db()->query ("UPDATE [e10_web_pages] SET ", $row, " WHERE [ndx] = %i", $row['ndx']);
		}

		// -- web menu for all servers
		$rows = $this->db()->query ("SELECT * from [e10_web_servers] WHERE [docState] != 9800");
		forEach ($rows as $row)
		{
			$webMap = $tablePages->checkTree ($row['ndx'], '', '', 0, NULL);

			$cfg ['e10']['web']['menu'][strval($row['ndx'])]['items'] = $webMap;
			file_put_contents(__APP_DIR__ . "/config/_e10.web.menu{$row['ndx']}.json", utils::json_lint (json_encode ($cfg)));
			unset ($cfg);
		}

		// -- temporary: deprecated file - TODO: remove in version 98
		if (is_file(__APP_DIR__ . "/config/_e10.web.menu.json"))
			unlink (__APP_DIR__ . "/config/_e10.web.menu.json");
	}

	public function rebuildTemplates ()
	{
		$tableTemplates = $this->table ('e10.base.templates');
		$rows = $this->db()->query ("SELECT * from [e10_base_templates] WHERE docState != 9800");
		forEach ($rows as $row)
		{
			$tableTemplates->rebuildTemplate ($row, TRUE);
		}
	}

	public function revalidatePersons()
	{
		$this->db()->query('UPDATE [e10_persons_personsValidity] SET [revalidate] = 1 WHERE [valid] = 2');
	}

	public function saveDataPackage ()
	{
		$tableId = $this->arg ("table");
		if ($tableId === FALSE)
			return $this->err("Missing '--table=tableId' param");

		$table = $this->table($tableId);
		if ($table === NULL)
			return $this->err("Invalid tableId; table not found");

		$disabledColumns = ['ndx', 'docState', 'docStateMain'];
		$dc = $this->arg ("disabled-columns");
		if ($dc !== FALSE)
		{
			$dclist = preg_split("/[\s,]+/", $dc);
			$disabledColumns = array_merge($disabledColumns, $dclist);
		}

		$hashtag = $this->arg ("hashtag");
		$hashtagColumn = $this->arg ("hashtag-column");


		$pkgText = '';

		$q [] = 'SELECT * FROM '. $table->sqlName () . ' WHERE 1';

		$queryStr = $this->arg ("query");
		if ($queryStr !== FALSE)
			array_push($q, ' AND ', $queryStr);

		$orderStr = $this->arg ("order");
		if ($orderStr !== FALSE)
			array_push($q, ' ORDER BY ', $orderStr);

		$rows = $this->db()->query ($q);
		$cntId = 1;
		$cntIds = [];
		forEach ($rows as $r)
		{
			$htId = '';
			if ($hashtagColumn !== FALSE && isset($r[$hashtagColumn]))
			{
				$htId = $r[$hashtagColumn];
				if (!isset($cntIds[$htId]))
					$cntIds[$htId] = 1;
				else
					$cntIds[$htId]++;
			}

			$pkgRow = [];

			if ($hashtag !== FALSE)
			{
				$htCntId = ($htId !== '') ? $htId.'-'.$cntIds[$htId] : $cntId;
				$pkgRow['#'] = $hashtag . '-' . $htCntId;
			}

			$pkgRow += $r->toArray ();

			$memoColsIds = [];
			$recMemos = [];

			foreach ($pkgRow as $rowKey => $rowValue)
			{
				$colDef = $table->column($rowKey);

				if ($colDef['type'] === 6 | $colDef['type'] === 12)
				{ // memo/code
					$memoColsIds[] = $rowKey;
					$recMemos[$rowKey] = explode("\n", $rowValue);
				}

				if ($rowValue instanceof \DateTimeInterface)
				{
					$date = $rowValue->format('Y-m-d');
					$time = $rowValue->format('H:i:s');
					if ($time === '00:00:00')
						$pkgRow[$rowKey] = $date;
					else
						$pkgRow[$rowKey] = $date.' '.$time;
				}
			}

			foreach ($recMemos as $rmKey => $rmRows)
				unset ($pkgRow[$rmKey]);

			foreach ($disabledColumns as $dc)
			{
				if (isset ($pkgRow[$dc]))
					unset ($pkgRow[$dc]);
				else
				if ($pkgRow[$dc] === null)
					unset ($pkgRow[$dc]);
			}

			if (count($recMemos))
				$pkgText .= "{\n\t\"rec\": ";
			else
				$pkgText .= "{\"rec\": ";
			$pkgText .= json_encode ($pkgRow, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

			if (count($recMemos))
			{
				$pkgText .= ",\n\t";
				$pkgText .= "\"recMemos\": {\n";
				foreach ($recMemos as $rmKey => $rmRows)
				{
					$pkgText .= "\t\t".'"'.$rmKey.'": ['."\n";
					$rowNdx = 0;
					foreach ($rmRows as $row)
					{
						$pkgText .= "\t\t\t";
						if ($rowNdx)
							$pkgText .= ",";
						$pkgText .= json_encode ($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

						$rowNdx++;
					}

					$pkgText .= "\t\t]\n";
				}
				$pkgText .= "\t}\n";
			}

			$pkgText .= "},\n";
			unset($pkgRow);
			$cntId++;
		}

		echo $pkgText;
	}


	public function runModuleServices ($st = '')
	{
		if ($st === '')
		{
			$serviceType = $this->arg("service");
			if ($serviceType === FALSE)
			{
				echo("no --service param! \n");
				return;
			}
		}
		else
			$serviceType = $st;

		foreach ($this->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$fmp = __SHPD_MODULES_DIR__.str_replace('.', '/', $moduleId).'/';

			$msfn = $fmp.'services.php';
			if (!is_file ($msfn))
				continue;
			include_once ($msfn);
			$className = str_replace ('.', '\\', $moduleId . '.ModuleServices');
			if (!class_exists ($className))
				continue;

			//echo "* $moduleId: $fmp \n";
			$moduleService = new $className ($this);

			switch ($serviceType)
			{
				case 'appPublish'				: $moduleService->onAppPublish (); break;
				case 'beforeAppUpgrade'	: $moduleService->onBeforeAppUpgrade (); break;
				case 'appUpgrade'				: $moduleService->onAppUpgrade (); break;
				case 'checkSystemData'	: $moduleService->onCheckSystemData (); break;
				case 'createDataSource' : $moduleService->onCreateDataSource (); break;
				case 'anonymize'				: $moduleService->onAnonymize (); break;

				default									:	echo ("unknown service '$serviceType'\n"); return;
			}
		}
	}

	public function cliAction ()
	{
		$completeActionId = $this->arg('action');
		if (!$completeActionId)
		{
			echo("no --action param!\n");
			return FALSE;
		}

		$parts = explode ('/', $completeActionId);
		if (count($parts) != 2)
		{
			echo("bad --action param!\n");
			return FALSE;
		}

		//$moduleId = str_replace('.', '/', $parts[0]);
		$moduleId = $parts[0];
		$actionId = $parts[1];

		if (!isset($this->dataModel->model ['modules'][$moduleId]))
		{
			echo("Invalid moduleId `{$moduleId}`.\n");
			return FALSE;
		}

		$module = $this->dataModel->model ['modules'][$moduleId];
		$fmp = __SHPD_MODULES_DIR__.str_replace('.', '/', $moduleId).'/';

		$msfn = $fmp.'services.php';
		if (!is_file ($msfn))
		{
			echo("Module `{$moduleId}` has no actions.\n");
			return FALSE;
		}

		include_once ($msfn);
		$className = str_replace ('.', '\\', $moduleId . '.ModuleServices');
		if (!class_exists ($className))
		{
			echo("Module `{$moduleId}` has no actions.\n");
			return FALSE;
		}

		//echo "* $moduleId: $fmp \n";
		$moduleService = new $className ($this);
		$moduleService->onCliAction($actionId);

		return TRUE;
	}

	public function anonymize ()
	{
		$faker = \Faker\Factory::create('cs_CZ');
		$this->faker = $faker;

		// -- persons
		$fullNames = [];
		$q = 'SELECT * FROM [e10_persons_persons] WHERE [company] = 0';
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$tryNum = 0;
			while (1)
			{
				$ln = $faker->lastName;

				if (mb_substr($ln, -1, 1, 'UTF-8') === 'á')
					$fn = $faker->firstNameFemale;
				else
					$fn = $faker->firstNameMale;

				$fullName = $ln . ' ' . $fn;

				if (!in_array($fullName, $fullNames))
					break;

				$tryNum++;
				if ($tryNum > 1000)
					break;
			}
			$this->db()->query ("UPDATE [e10_persons_persons] SET firstName = %s, ", $fn, 'lastName = %s, ', $ln,
					'fullName = %s', $fullName, ' WHERE ndx = %i', $r['ndx']);

			echo ("* {$r['fullName']} -> $fn $ln \n");
		}

		// -- companies
		$q = 'SELECT * FROM [e10_persons_persons] WHERE [company] = 1';
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$fn = $faker->company;

			$this->db()->query ('UPDATE [e10_persons_persons] SET lastName = %s, ', $fn,
					'fullName = %s', $fn, ' WHERE ndx = %i', $r['ndx']);
			echo ("* {$r['fullName']} -> $fn \n");
		}

		// -- properties
		$q = "SELECT * FROM [e10_base_properties] where [tableid] = %s";
		$rows = $this->db()->query ($q, 'e10.persons.persons');
		forEach ($rows as $r)
		{
			$new = '';
			if ($r['group'] === 'contacts')
			{
				if ($r['property'] === 'email')
					$new = $faker->email;
				else
					if ($r['property'] === 'phone')
						$new = $faker->phoneNumber;
			}
			else
			if ($r['group'] === 'ids')
			{
				if ($r['property'] === 'oid')
					$new = $faker->randomNumber(8);
				else
				if ($r['property'] === 'taxid')
					$new = 'CZ'.$faker->randomNumber(8);
				else
				if ($r['property'] === 'pid')
					$new = $faker->randomNumber(6).'/'.$faker->randomNumber(4);
			}

			if ($new !== '')
			{
				$this->db()->query ('UPDATE [e10_base_properties] SET valueString = %s ', $new, ' WHERE ndx = %i', $r['ndx']);
				echo ("* {$r['valueString']} -> $new \n");
			}
		}

		$this->runModuleServices ('anonymize');
	}

	public function geoCode ()
	{
		$limit = 100;
		$tableAddress = $this->table('e10.persons.address');

		$q[] = 'SELECT * FROM [e10_persons_address] AS [address]';
		array_push ($q, ' WHERE locState = 0');
		array_push ($q, ' ORDER BY ndx DESC');

		$cnt = 0;
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			echo '#'.$r['ndx'].": ".$r['street'].', '.$r['city'].' '.$r['zipcode'];

			if ($tableAddress->geoCode ($r))
				echo " OK";
			else
				echo " FAIL";

			echo "\n";

			$cnt++;
			if ($cnt > $limit)
				break;

			usleep(200000); // max 5 request per second
		}
	}

	public function parseBankStatement ()
	{
		$fileName = $this->arg ('file');
		if (!is_readable($fileName))
			return $this->err("File '$fileName' not found!");

		$parser = new \lib\ebanking\parsers\mt940 ($this);
		$parser->parse ($fileName);
		file_put_contents($fileName.'.json', json_encode($parser->statements));
	}

	public function cacheInvalidateItem()
	{
		$itemId = $this->arg ('itemId');
		if ($itemId === FALSE)
			return $this->err("Missing '--itemId=cache-item-id' param");

		$parts = explode ('-', $itemId);
		if (count ($parts) !== 3)
			return $this->err("Invalid itemId '$itemId'");

		$classId = $parts[2];

		$redis = new Redis();
		$redis->connect('/var/run/redis/redis.sock');

		$beginInc = $redis->get ($itemId.'_inv');
		$o = $this->createObject($classId);
		$o->createData();

		$redis->hSet ($itemId, 'data', json_encode($o->data));
		$redis->hSet ($itemId, 'cacheInfo', json_encode($o->cacheInfo));

		$endInc = $redis->get ($itemId.'_inv');
		if ($beginInc === $endInc)
			$redis->set ($itemId.'_inv', 0);
	}


	function eetTest ()
	{
		$rosTypes = $this->cfgItem('terminals.ros.types', NULL);
		if (!$rosTypes)
		{
			echo "# No ROS types declared (missing ROS modules?)...\n";
			return;
		}

		$rosRegs = $this->cfgItem('terminals.ros.regs', NULL);
		if (!$rosRegs || count($rosRegs) < 2)
		{
			echo "# No ROS registration defined...\n";
			return;
		}

		foreach ($rosRegs as $rosRegNdx => $rosReg)
		{
			if (!$rosRegNdx)
				continue;

			echo "----- {$rosReg['title']} ----\n";

			$rosType = $rosTypes[$rosReg['rosType']];
			$e = $this->createObject($rosType['engine']);
			$e->test();
			echo json::lint ($e->resultData)."\n\n";
		}
	}

	function provozovny()
	{
		$this->db()->query ('delete from e10_persons_address where `type` = 99 AND ndx != 352150');

		$q[] = 'SELECT * FROM [e10_persons_persons] WHERE [company] = 1';

		array_push ($q, ' AND EXISTS (',
			' SELECT ndx FROM e10doc_core_heads ',
			' WHERE e10_persons_persons.ndx = e10doc_core_heads.person AND e10doc_core_heads.docType = %s', 'purchase', ')'
		);

		$invalid = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$personNdx = $r['ndx'];

			$qid = [];
			array_push($qid, 'SELECT * FROM [e10_base_properties] as props');
			array_push($qid, ' WHERE [tableid] = %s', 'e10.persons.persons',
				' AND [group] = %s', 'ids', ' AND [property] = %s', 'oid');
			array_push($qid, ' AND recid = %i', $r['ndx']);

			$cidRec = $this->db()->query ($qid)->fetch();
			if (!$cidRec)
				continue;
			$cid = $cidRec['valueString'];

			echo "* $cid: ";//.$r['fullName'];

			$personInfoStr = file_get_contents("https://services.shipard.com/feed/subject-info/$cid");
			if (!$personInfoStr)
			{
				echo " ERR1 \n";
				continue;
			}
			$personInfo = json_decode($personInfoStr, true);
			if (!$personInfo || !isset($personInfo['subjects'][0]))
			{
				echo " ERR2 \n";
				continue;
			}

			$pi = $personInfo['subjects'][0];
			if (!isset($pi['addresses']) || !count($pi['addresses']))
			{
				echo " --- \n";
				continue;
			}

			echo " | ".count($pi['addresses']);

			$firstAddNdx = 0;
			$firstSuggestedNdx = 0;
			$secondSuggestedNdx = 0;

			$cnt = 0;
			forEach ($pi['addresses'] as $address)
			{
				if ($address['type'] != 99)
					continue;
				$newAddress = $address;
				$newAddress['tableid'] = 'e10.persons.persons';
				$newAddress['recid'] = $r['ndx'];

				$this->db->query ('INSERT INTO [e10_persons_address]', $newAddress);
				$newNdx = intval ($this->db()->getInsertId ());
				if (!$firstAddNdx)
					$firstAddNdx = $newNdx;
				if (!$firstSuggestedNdx && $newAddress['city'] === 'Zlín')
					$firstSuggestedNdx = $newNdx;
				if (!$secondSuggestedNdx && substr($newAddress['zipcode'], 0, 2) === '76')
					$secondSuggestedNdx = $newNdx;

				$cnt++;
			}

			if ($cnt === 1)
			{
				$this->db()->query ('UPDATE [e10doc_core_heads] SET otherAddress1 = ', $firstAddNdx,
					'WHERE [person] = %i', $personNdx, ' AND [dateAccounting] >= %d', '2016-01-01');

				echo "[1]";
			}
			else if ($firstSuggestedNdx)
			{
				$this->db()->query ('UPDATE [e10doc_core_heads] SET otherAddress1 = ', $firstSuggestedNdx,
					'WHERE [person] = %i', $personNdx, ' AND [dateAccounting] >= %d', '2016-01-01');

				echo "[ZL]";
			}
			else if ($secondSuggestedNdx)
			{
				$this->db()->query ('UPDATE [e10doc_core_heads] SET otherAddress1 = ', $secondSuggestedNdx,
					'WHERE [person] = %i', $personNdx, ' AND [dateAccounting] >= %d', '2016-01-01');

				echo "[76]";
			}
			else
			{
				echo "[!]";
				if ($cnt)
					$invalid[] = "* $cid [$cnt]: ".$r['fullName'];
			}

			echo "\n";
		}


		echo "-- ".count($invalid)." -----------------------------------\n";
		foreach ($invalid as $msg)
		{
			echo $msg."\n";
		}
	}

	public function repairDocNumbers ()
	{
		$doRepair = intval($this->arg('do-repair'));
		$e = new \lib\docs\utils\DocNumbersRepair($this);
		if ($doRepair)
			$e->doRepair = TRUE;
		$e->run();
	}

	function copyTable ($srcTableId, $srcTableSqlName, $dstTableId, $disableLogUpdate = FALSE, $disabledCols = [])
	{
		/** @var \e10\DbTable */
		$dstTable = $this->table ($dstTableId);

		$colsList = [];
		foreach ($dstTable->columns() as $colDef)
		{
			if (in_array($colDef['sql'], $disabledCols))
				continue;
			$colsList[] = '[' . $colDef['sql'] . ']';
		}

		$sqlCommand = 'INSERT INTO ['.$dstTable->sqlName().']';
		$sqlCommand .= ' ('.implode(', ', $colsList).')';
		$sqlCommand .= ' SELECT '.implode(', ', $colsList).' FROM ['.$srcTableSqlName.'] ORDER BY [ndx]';

		echo $sqlCommand."\n";
		$this->db()->query($sqlCommand);

		if (!$disableLogUpdate)
			$this->db()->query ('UPDATE [e10_base_docslog] SET tableId = %s', $dstTableId, ' WHERE tableid = %s', $srcTableId);
	}

	public function upgradeOldE10()
	{
		$u = new \Shipard\Upgrade\OldE10\Upgrade($this);
		$u->run();
	}

	public function importOldBBoard()
	{
		$u = new \Shipard\Upgrade\OldE10\Upgrade($this);
		$u->upgradeOldBBoard();

		return TRUE;
	}

	public function tests()
	{
		$this->robotLogin();

		$tests = $this->cfgItem('tests');
		foreach ($tests as $t)
		{
			$testObject = $this->createObject($t['class']);
			if (!$testObject)
			{
				echo "ERROR: Class '{$t['class']}' not found.\n";
				continue;
			}
			$testObject->setDefinition($t);
			$testObject->run();
			unset($testObject);
		}

		return TRUE;
	}

	public function run ()
	{
		$this->quiet = $this->arg ('quiet');

		switch ($this->command ())
		{
			case	"appDemo":											return $this->appDemo();
			case	"appOptions":										return $this->appOptions();
			case	"appTask":											return $this->appTask();
			case	"app-walk":											return $this->appWalk();
			case	"cfgUpdate":										return $this->cfgUpdate ();
			case	'cli-action':										return $this->cliAction();
			case	'createShare':									return $this->createShare();
			case	'demo':													return $this->demo();
			case	'importFile':										return $this->importFile();
			case	'saveShare':										return $this->saveShare();
			case	'sendBulkEmails':								return $this->sendBulkEmails();
			case	'generateBankOrders':						return $this->generateBankOrders();
			case	'uploadBankOrder':							return $this->uploadBankOrder();
			case	'downloadBankStatements':				return $this->downloadBankStatements();
			case	"generateBooks":								return $this->generateBooks ();
			case	"moduleService":								return $this->runModuleServices ();
			case	"rebuildTemplates":							return $this->rebuildTemplates ();
			case	"refreshWebPages":							return $this->refreshWebPages ();

			case	'initRestoredDemo':							return $this->initRestoredDemo ();

			case	"addLocalAccount":							return $this->addLocalAccount ();
			case	"localAccount":									return $this->localAccount ();
			case	"makeAdmin":									  return $this->makeAdmin ();
			case	"apiKey":												return $this->apiKey ();
			case	"userPin":											return $this->userPin();
			case	"userChangeLogin":							return $this->userChangeLogin();

			case	"parseBankStatement":						return $this->parseBankStatement();

			case  "revalidatePersons":						return $this->revalidatePersons();

			case  "anonymize":										return $this->anonymize();

			case	"dumpCfg":											return $this->dumpCfg ();
			case	"saveDataPackage":							return $this->saveDataPackage ();
			case	"installDataPackage":						return $this->installDataPackage ();

			case	"geoCode":											return $this->geoCode();

			case	'cache-invalidate-item':				return $this->cacheInvalidateItem();


			case	"eetTest":											return $this->eetTest();

			case 	"provozovny":										return $this->provozovny();

			case	"repairDocNumbers":							return $this->repairDocNumbers();

			case	"upgradeOldE10":								return $this->upgradeOldE10();
			case	"importOldBBoard": 							return $this->importOldBBoard();

			case	"tests":												return $this->tests();
		}
		echo ("unknown or nothing param...\r\n");
	}

	public function superuser ()
	{
		return (0 == posix_getuid());
	}
}

$myApp = new E10_App ($argv);
$myApp->run ();
