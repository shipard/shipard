<?php

namespace Shipard\CLI;
use \Shipard\Base\Cache;


/**
 * Class Application
 */
class Application extends \Shipard\Application\Application
{
	var $arguments;
	var $lastUsedVersionId = 0;

	public function __construct ($argv)
	{
		$this->e10dir = __SHPD_ROOT_DIR__;
		$this->arguments = $this->parseArgs($argv);
		if ($this->command() === 'appWalk' || $this->command() === 'app-walk')
		{
			$this->cfgServer = $this->loadCfgFile('/etc/shipard/server.json');
			return;
		}

		$this->debug = intval($this->arg('debug'));

		// -- load last version id
		$this->lastUsedVersionId = intval (@file_get_contents (__APP_DIR__ . "/config/E10_VERSION_ID"));

		// -- load config
		$cfgString = file_get_contents (__APP_DIR__ . "/config/curr/cfg.data");
		if (!$cfgString)
			return ;//$this->setError (500, "uncofigured application");
		$this->appCfg = unserialize ($cfgString);

		// -- load app skeleton
		$this->appSkeleton  = $this->appCfg ['appSkeleton'];
		$this->dataModel = new \Shipard\Application\DataModel ($this->appCfg ['dataModel']);

		$this->cache = new Cache ($this);
		$this->cache->init();

		// -- create db connection
		if ($this->cfgItem ('db', FALSE) != FALSE)
		{
			$connectionString = $this->cfgItem ('dbDriver') . ":host=" . $this->cfgItem ('host') . ";dbname=" . $this->cfgItem ('database');

			$dboptions = array(
			'driver'   => $this->cfgItem ('db.driver', 'mysqli'),
			'host'     => $this->cfgItem ('db.host', 'localhost'),
			'username' => $this->cfgItem ('db.login'),
			'password' => $this->cfgItem ('db.password'),
			'database' => $this->cfgItem ('db.database'),
			'charset'  => $this->cfgItem ('db.charset', 'utf8mb4'),
			'resultDetectTypes' => TRUE,
			);


			//$dboptions ['profiler'] = TRUE;

			try {
			$this->db = new \Dibi\Connection ($dboptions);;
			} catch (\Dibi\Exception $e) {
					error_log (get_class($e) . ': ' . $e->getMessage());
			}
			//$profiler = $this->db->getProfiler();
			//$profiler->setFile (__APP_DIR__ . '/log/dibi.log');
		}

		// -- default user
		if (isset ($this->appSkeleton['userManagement']['authenticator']))
			$this->setAuthenticator ($this->createObject($this->appSkeleton['userManagement']['authenticator']));

		$this->user = new \Shipard\Application\User ();
		$this->user->app = $this;
		$this->clientType = array('cli');

		if (!$this->cfgServer)
			$this->cfgServer = $this->loadCfgFile('/etc/shipard/server.json');
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return strval($this->arguments [$name]);

		return FALSE;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	protected function robotLogin()
	{
		$q[] = 'SELECT * FROM [e10_persons_persons]';
		array_push ($q, 'WHERE [personType] = %i', 3);
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' ORDER BY [fullName]');

		$robot = $this->db()->query($q)->fetch();
		if ($robot)
		{
			$userRoles = explode('.', $robot['roles']);
			$this->authenticator->checkRolesDependencies ($userRoles, $this->cfgItem ('e10.persons.roles'));
			$this->user ()->setData (
					['id' => $robot['ndx'], 'login' => 'support@shipard.com', 'name' => $robot['fullName'],
					'roles' => $userRoles]
			);
		}
	}

	public function err ($msg)
	{
		if ($msg === FALSE)
			return TRUE;

		if (is_array($msg))
		{
			if (count($msg) !== 0)
			{
				forEach ($msg as $m)
					echo ("! " . $m['text']."\n");
				return FALSE;
			}
			return TRUE;
		}

		echo ("ERROR: ".$msg."\n");
		return FALSE;
	}

	function parseArgs($argv)
	{
		// http://pwfisher.com/nucleus/index.php?itemid=45
			array_shift ($argv);
			$out = array();
			foreach ($argv as $arg){
					if (substr($arg,0,2) == '--'){
							$eqPos = strpos($arg,'=');
							if ($eqPos === false){
									$key = substr($arg,2);
									$out[$key] = isset($out[$key]) ? $out[$key] : true;
							} else {
									$key = substr($arg,2,$eqPos-2);
									$out[$key] = substr($arg,$eqPos+1);
							}
					} else if (substr($arg,0,1) == '-'){
							if (substr($arg,2,1) == '='){
									$key = substr($arg,1,1);
									$out[$key] = substr($arg,3);
							} else {
									$chars = str_split(substr($arg,1));
									foreach ($chars as $char){
											$key = $char;
											$out[$key] = isset($out[$key]) ? $out[$key] : true;
									}
							}
					} else {
							$out[] = $arg;
					}
			}
			return $out;
	}


	public function run ()
	{
		echo "nothing to do...\r\n";
	}
}


