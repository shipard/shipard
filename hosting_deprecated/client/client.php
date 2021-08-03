<?php

namespace E10Pro\Hosting\Client;

require_once __APP_DIR__ . '/e10-modules/e10pro/tools/ares.php';
require_once __APP_DIR__ . '/e10-modules/e10/persons/persons.php';

use \E10\TableView, \E10\TableViewDetail, \E10\utils;
use \E10\TableForm;
use \E10\Application;
use \e10\json;


function nacistZAresu ($app)
{
	$ares = new \Tools\Ares ();
	$ares->setIc ($app->testGetParam ('ic'));
	$aresResponse = $ares->load ();

	$r = new \E10\Response ($app);
	$r->add ("objectType", "call");
	$r->add ("ares", $aresResponse);
	return $r;
}


function appRegForm ($app, $params)
{
	$classFileName = __APP_DIR__ . '/e10-modules/' . str_replace ('.', '/', $params ['module']) . '/hosting.php';
	require_once ($classFileName);

	$className = $params ['module'] . '.' . 'appRegForm';
	$wf = $app->createObject ($className);

	// done?
	$done = intval ($app->testGetParam ('hotovo'));
	if ($done === 1)
		return 'Hotovo. Za chvíli obdržíte e-mail pro potvrzení registrace.';

	// confirm?
	$confirmKey = $app->testGetParam ('key');
	if ($confirmKey != '')
	{
		$url =  $app->cfgItem ('e10.hosting.regServerUrl') . 'api/call/e10pro.hosting.server.confirmNewDataSource?key='.$confirmKey;
		$resultCode = file_get_contents ($url);
		$resultData = json_decode ($resultCode, true);

		if ($resultData && $resultData ['success'] && isset ($resultData ['code']))
			return $resultData ['code'];
		return 'Potvrzení z neznámých důvodů selhalo.';
	}

	if (!$wf->getData ())
		return $wf->createFormCode ();

	if (!$wf->validate ())
		return $wf->createFormCode ();

	$url = $app->cfgItem ('e10.hosting.regServerUrl') . 'api/call/e10pro.hosting.server.createNewDataSource';
	$result =  \E10\http_post ($url, json_encode ($wf->data));

	$email = $wf->createEmailRequest ();
	\E10\SendEmail ($email ['subject'], $email ['message'], $email ['fromEmail'], $wf->data ['regEmail'], $email ['fromName'], $wf->data ['regName']);

	header ('Location: ' . $app->urlProtocol . $_SERVER['HTTP_HOST'] . $app->urlRoot . $app->requestPath () . '?hotovo=1');
	die();
}

function infoDataSources ($app, $params = NULL)
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Headers: e10-login-user, e10-login-pw');$data = [];

	$data['success'] = ($app->userNdx()) ? 1 : 0;

	if ($app->userNdx())
		$data['userInfo'] = ['name' => $app->user()->data('name'), 'login' => $app->user()->data('login')];
	else
		$data['userInfo'] = ['name' => '', 'login' => ''];

	$data['thisPortal'] = hostingPortalInfo ($app);
	$data['dataSources'] = usersDataSources ($app);

	$page = ['code' => json::lint($data), 'mimeType' => 'application/json'];

	return $page;
}

function infoPortals ($app, $params = NULL)
{
	header('Access-Control-Allow-Origin: *');

	$data = [];
	$data['portals'] =  $app->cfgItem('e10pro.hosting.portals.portals', []);

	/*
	$demoFileName = __APP_DIR__.'/tmp/demo-portals.json';
	if (is_file($demoFileName))
	{
		$demoDataStr = file_get_contents($demoFileName);
		if ($demoDataStr)
		{
			$demoData = json_decode($demoDataStr, TRUE);
			$dd = $demoData['portals'][1];
			$dd['portalDomain'] = 'd3m0.shipard.com';

			$data['portals'][99999] = $dd;
		}
	}
	*/

	$page = ['code' => json::lint($data), 'mimeType' => 'application/json'];

	return $page;
}

function pageUser ($app, $params = NULL)
{
	if (!$app->user ()->isAuthenticated ())
	{
		$page ['title'] = 'nejste přihlášen(a)';
		$page ['text'] = 'nejste přihlášen(a)';

		return $page;
	}

	$tableDataSources = $app->table ('e10pro.hosting.server.datasources');

	// -- users data sources
	$q =	"SELECT usersds.*, ds.name AS dsName, ds.gid as dsid, ds.docState AS docState, ds.docStateMain AS docStateMain, ds.urlWeb AS urlWeb, ds.urlApp AS urlApp, ds.docStateMain AS docStateMain, owners.fullName as ownerName FROM [e10pro_hosting_server_usersds] as usersds " .
				"RIGHT JOIN e10pro_hosting_server_datasources as ds ON usersds.datasource = ds.ndx " .
				"RIGHT JOIN e10_persons_persons as owners ON ds.owner = owners.ndx " .
				"WHERE usersds.user = %i AND ds.docState = 4000 AND usersds.docState = 4000 ORDER BY ds.docStateMain, dsName";
	$dataSourcesRows = $app->db()->query ($q, $app->user ()->data('id'));

	forEach ($dataSourcesRows as $row)
	{
		$ds ['name'] = $row ['dsName'];
		$ds ['dsid'] = $row ['dsid'];
		$ds ['ownerName'] = $row ['ownerName'];
		if ($row ['urlApp'] != '')
			$ds ['urlApp'] = $row ['urlApp'];
		if ($row ['urlWeb'] != '')
			$ds ['urlWeb'] = $row ['urlWeb'];

		$documentStates = $tableDataSources->documentStates ();
		$ds ['stateName'] = $tableDataSources->getDocumentStateInfo ($documentStates, $row, 'name');
		$ds ['stateIcon'] = $tableDataSources->getDocumentStateInfo ($documentStates, $row, 'styleIcon');
		$ds ['stateClass'] = $tableDataSources->getDocumentStateInfo ($documentStates, $row, 'styleClass');

		switch ($row ['docState'])
		{
			case 0:
			case 1100:
				$ds ['comment'] = "Vaše databáze se zakládá. Vyčkejte prosím dvě minuty a obnovte stránku.";
				break;
			case 4000:
				$ds ['ready'] = TRUE;
				break;
			default:
				$ds ['comment'] = "Na databázi probíhá technická údržba.";
				break;
		}
		$page ['userDataSources'][] = $ds;
		unset ($ds);
	}

	$page ['title'] = 'Moje databáze';
	$page ['dataSources'] = TRUE;
	$page ['enableCreateDatabases'] = intval ($app->cfgItem ('options.hosting.createNewDatabaseAllowed', 0));
	if ($app->hasRole('hstngcd'))
		$page ['enableCreateDatabases'] = 1;
	$page ['subTemplate'] = 'e10pro.hosting.server.datasources';

	return $page;
}

function hostingPortalInfo ($app, $params = NULL)
{
	$portalNdx = $app->cfgItem('e10pro.hosting.portals.portalsDomains.' . str_replace('.', '-', $_SERVER['HTTP_HOST']), 1);
	$portalCfg = $app->cfgItem('e10pro.hosting.portals.portals.' . $portalNdx);

	if (!$params)
		return $portalCfg;

	$varName = \E10\searchParam($params, 'var', 'hostingPortal');
	unset ($params ['owner']->data[$varName]);

	$params ['owner']->data[$varName] = [];
	$params ['owner']->data[$varName]['this'] = $portalCfg;
}

function usersDataSources ($app, $params = NULL)
{
	if ($app->cfgItem ('dsMode') === Application::dsmDevel)
		return usersDataSourcesDEV ($app, $params);

	$portalDomain = '';

	if (isset($_SERVER['HTTP_HOST']))
	{
		$hp = explode('.', $_SERVER['HTTP_HOST']);
		$hpr = array_reverse($hp);
		$portalDomain = $hpr[1] . '.' . $hpr[0];
	}

	$result = ['list' => [], 'count' => 0];

	$q[] = 'SELECT usersds.*, ds.name AS dsName, ds.shortName AS dsShortName, ds.gid as dsid, ds.docState AS docState,';
	array_push ($q, ' ds.docStateMain AS docStateMain, usersds.lastLogin AS lastLogin,');
	array_push ($q, ' ds.urlWeb AS urlWeb, ds.urlApp AS urlApp, ds.urlApp2 AS urlApp2, ds.docStateMain AS docStateMain, ds.imageUrl AS dsImageUrl,');
	array_push ($q, ' owners.fullName as ownerName, servers.id as serverId');
	array_push ($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
	array_push ($q, ' RIGHT JOIN e10pro_hosting_server_datasources as ds ON usersds.datasource = ds.ndx');
	array_push ($q, ' RIGHT JOIN e10_persons_persons as owners ON ds.owner = owners.ndx');
	array_push ($q, ' RIGHT JOIN e10pro_hosting_server_servers as servers ON ds.server = servers.ndx');

	array_push ($q, ' WHERE usersds.user = %i', $app->userNdx(),' AND ds.docState = 4000 AND usersds.docState = 4000');
	array_push ($q, ' ORDER BY dsName');

	$dataSourcesRows = $app->db()->query ($q);
	$cnt = 0;
	$all = [];
	forEach ($dataSourcesRows as $row)
	{
		$ds = [];
		$ds ['name'] = $row ['dsName'];
		$ds ['shortName'] = ($row ['dsShortName'] !== '') ? $row ['dsShortName'] : $row ['dsName'];
		$ds ['dsid'] = $row ['dsid'];
		$ds ['ownerName'] = $row ['ownerName'];
		if ($row ['urlApp'] != '')
			$ds ['urlApp'] = $row ['urlApp'];
		if ($row ['urlWeb'] != '')
			$ds ['urlWeb'] = $row ['urlWeb'];

		$urlParts = parse_url($row ['urlApp']);
		$dsDomainParts = array_reverse(explode ('.', $urlParts['host']));
		$dsDomain = $dsDomainParts[1].'.'.$dsDomainParts[0];

		if ($dsDomain !== $portalDomain && $row['urlApp2'] !== '')
		{
			$ds ['urlApp'] = $row ['urlApp2'];
			$urlParts = parse_url($row ['urlApp2']);
			$dsDomainParts = array_reverse(explode ('.', $urlParts['host']));
			$dsDomain = $dsDomainParts[1].'.'.$dsDomainParts[0];
		}

		if ($portalDomain !== 'shipard-demo.cz')
			$ds ['urlApp'] = 'https://'.$row ['serverId'].'.'.'shipard.com'.'/'.$row['dsid'].'/';

		$ds['imageUrl'] = $row ['dsImageUrl'];

		switch ($row ['docState'])
		{
			case 0:
			case 1100:
				$ds ['comment'] = "Databáze se zakládá. Vyčkejte prosím dvě minuty a obnovte stránku.";
				break;
			case 4000:
				$ds ['ready'] = TRUE;
				break;
			default:
				$ds ['comment'] = "Na databázi probíhá technická údržba.";
				break;
		}

		$cnt++;

		$dsId = strval($row ['dsid']);
		$dsOrder = ($row['lastLogin']) ? $row['lastLogin']->format ('Y-m-d') : '0000-99-99';
		$ds['order'] = $dsOrder;

		$all[$dsId] = $ds;
		$result['count']++;
	}

	$cnt = 0;
	$o = \e10\sortByOneKey($all, 'order', TRUE, FALSE);
	foreach ($o as $dsId => $ds)
	{
		$o[$dsId]['counter'] = $cnt;
		$cnt++;
	}

	// -- top
	foreach ($all as $dsId => $ds)
	{
		if ($o[$dsId]['counter'] > 5)
			continue;
		$ds ['class'] = 'top';
		$result['all'][] = $ds;
	}
	// -- next
	foreach ($all as $dsId => $ds)
	{
		if ($o[$dsId]['counter'] < 6 || $o[$dsId]['counter'] > 11)
			continue;
		$ds ['class'] = 'next';
		$result['all'][] = $ds;
	}
	// -- bottom
	foreach ($all as $dsId => $ds)
	{
		if ($o[$dsId]['counter'] < 12)
			continue;
		$ds ['class'] = 'bottom';
		$result['all'][] = $ds;
	}

	if (!$params)
		return $result;

	$varName = \E10\searchParam ($params, 'var', 'dataSources');
	unset ($params ['owner']->data[$varName]);
	$params ['owner']->data[$varName] = $result;

	return '';
}

function usersDataSourcesDEV ($app, $params = NULL)
{
	$result = ['list' => [], 'count' => 0];


	$scanMask = '/var/www/data-sources/' . '*';
	forEach (glob($scanMask, GLOB_ONLYDIR) as $dsDir)
	{
		if (is_link($dsDir))
			continue;

		$dsCfg = utils::loadCfgFile($dsDir.'/config/dataSourceInfo.json');
		if (!$dsCfg)
			continue;

		$pathParts = explode ('/', $dsDir);
		$dsDir = array_pop($pathParts);

		$ds = [];
		$ds ['name'] = $dsCfg ['name'];
		$ds ['shortName'] = (isset($dsCfg ['shortName']) && $dsCfg ['shortName'] !== '') ? $dsCfg ['shortName'] : $dsCfg ['name'];
		$ds ['dsid'] = $dsCfg ['dsid'];
		$ds ['ownerName'] = $dsCfg ['name'];
		$ds ['urlApp'] = 'https://sebik-ds.shipard.pro/'.$dsDir.'/';
		$ds ['ready'] = TRUE;
		$ds['imageUrl'] = ($dsCfg ['dsimage'] !== 'https://shipard.com/templates/bdc10abc-d11f23fc-257c44b5-89c5d480/img/app-icon.png') ? $dsCfg ['dsimage'] : '';

		$ds ['class'] = 'top';

		$result['all'][] = $ds;
		$result['count']++;
	}

	if (!$params)
		return $result;

	$varName = \E10\searchParam ($params, 'var', 'dataSources');
	$params ['owner']->data[$varName] = $result;

	return '';
}

/**
 * createNewDataSourceRequest
 *
 */

function createNewDataSourceRequest ($app)
{ // TODO: remove?
	//$app->testPostParam ('webFormState')

	// -- create new person (owner)
	$newPerson ['person'] = array ('fullName' => $app->testPostParam ('regOrgName'), 'lastName' => $app->testPostParam ('regOrgName'), 'company' => 1);
	//if (isset ($requestData ['regEmail']))
	//	$newPerson ['contacts'][] = array ('type' => 'email', 'value' => $requestData ['regEmail']);

	$newPerson ['address'][] = array ('street' => $app->testPostParam ('regOrgStreet'), 'city' => $app->testPostParam ('regOrgCity'),
																		'zipcode' => $app->testPostParam ('regOrgZIPCode'), 'country' => 'cz');

	if ($app->testPostParam ('regOrgID', '') != '')
		$newPerson ['ids'][] = array ('type' => 'oid', 'value' => $app->testPostParam ('regOrgID'));

	$newPersonNdx = \E10\Persons\createNewPerson ($app, $newPerson);

	// -- create new datasource
	$newDataSource ['name'] = $app->testPostParam ('regOrgName');
	$newDataSource ['installModule'] = intval ($app->testPostParam ('regDbType'));
	$newDataSource ['gid'] = mt_rand (10000, 999999).'0'.mt_rand (100000, 9999999);
	$newDataSource ['admin'] = $app->user()->data ('id');
	$newDataSource ['owner'] = $newPersonNdx;
	$newDataSource ['payer'] = 0;
	$newDataSource ['created'] = new \DateTime ();
	//$newDataSource ['server'] = $request ['server'];
	$newDataSource ['site'] = 1;
	$newDataSource ['dateStart'] = utils::today();
	$newDataSource ['dateTrialEnd'] = new \DateTime ();
	$newDataSource ['dateTrialEnd']->add (new \DateInterval('P30D'));
	$newDataSource ['docState'] = 1100;
	$newDataSource ['docStateMain'] = 0;
	$app->db()->query ("INSERT INTO [e10pro_hosting_server_datasources]", $newDataSource);
	$newDatasourceNdx = intval ($app->db()->getInsertId ());

	// -- link new data source to admin
	$newLinkedDataSource = ['user' => $app->user ()->data('id'), 'datasource' => $newDatasourceNdx,
													'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2];
	$tableUsersDS = $app->table('e10pro.hosting.server.usersds');
	$tableUsersDS->addUsersDSLink($newLinkedDataSource);
}


/**
 * dsRegForm
 *
 */

class dsRegForm extends \E10\WebForm
{ // TODO: remove
	public function createFormCode ($options = 0)
	{
		$modules = $this->app->cfgItem ('e10pro.hosting.modules');
		$dbTypes = [];
		foreach ($modules as $m)
		{
			if ($m['private'] && !$this->app->hasRole('hstng'))
				continue;
			$dbTypes[$m['ndx']] = $m['name'];
		}
		$activeDbType = $this->app->testgetParam ('p');

		$c = "<script type=\"text/javascript\" src=\"{$this->app->dsRoot}/e10-modules/e10pro/hosting/client/js/client.js\"></script>";

		$c .= "<form class='form-horizontal' method='POST'>";
		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='e10pro.hosting.server.dsRegForm'/>";

		$c .= $this->addFormInput ('Cenový program', 'select', 'regDbType', array ('select' => $dbTypes, 'selected' => $activeDbType));
		$c .= $this->addFormInput ('IČ', 'text', 'regOrgID', array ('inline' => "<button type='button' onclick='nacistZAresu ($(this), \"regOrgID\");return 0;' class='btn btn-info'><i class='icon-download-alt'></i> Načíst informace z obchodního rejstříku</button>"));
		$c .= $this->addFormInput ('Název', 'text', 'regOrgName');
		$c .= $this->addFormInput ('Ulice', 'text', 'regOrgStreet');
		$c .= $this->addFormInput ('Město', 'text', 'regOrgCity');
		$c .= $this->addFormInput ('PSČ', 'text', 'regOrgZIPCode');

		$c .= "<div class='form-group'><div class='col-sm-offset-2 col-sm-10'><button type='submit' class='btn btn-primary'>Vytvořit databázi</button></div></div>";
		$c .= '</form>';

		return $c;
	}

	public function fields ()
	{
		return array ('regFormModuleId', 'regDbType', 'regName', 'regEmail', 'regPassword', 'regOrgID', 'regOrgName', 'regOrgStreet', 'regOrgCity', 'regOrgZIPCode');
	}

	public function validate ()
	{
		if ($this->app->testPostParam ("regOrgName") == "")
		{
			$this->formErrors ['regOrgName'] = 'Název není vyplněn';
			return FALSE;
		}

		if ($this->app->testPostParam ("regOrgStreet") == "")
		{
			$this->formErrors ['regOrgStreet'] = 'Ulice není vyplněna';
			return FALSE;
		}

		if ($this->app->testPostParam ("regOrgCity") == "")
		{
			$this->formErrors ['regOrgCity'] = 'Město není vyplněno';
			return FALSE;
		}

		return TRUE;
	}

} // dsRegForm


function pageNewDataSource ($app, $params = NULL)
{
	$title = 'Nová databáze';

	if (!$app->user ()->isAuthenticated ())
	{
		$page ['title'] = $title;
		$page ['text'] = "Před vytvořením nové databáze se musíte
											<a href='{$app->urlRoot}/{$app->authenticator->option ('pathBase')}/{$app->authenticator->option ('pathRegistration')}'>zaregistrovat</a> nebo
											<a href='{$app->urlRoot}/{$app->authenticator->option ('pathBase')}/{$app->authenticator->option ('pathLogin')}'>přihlásit</a>.";

		return $page;
	}

	$enableCreateDatabases = intval ($app->cfgItem ('options.hosting.createNewDatabaseAllowed', 0));
	if ($app->hasRole('hstngcd'))
		$enableCreateDatabases = 1;

	if (!$enableCreateDatabases)
	{
		$page ['title'] = $title;
		$page ['text'] = "Přístup zamítnut. Nemáte oprávnění zakládat nové databáze.";

		return $page;
	}

	$wf = new dsRegForm ($app);

	// done?
	$done = intval ($app->testGetParam ('done'));
	if ($done === 1)
	{
		$page ['title'] = 'Hotovo';
		$page ['text'] = 'Hotovo. Za chvíli obdržíte e-mail s adresou pro přihlášení.';
		return $page;
	}

	if (!$wf->getData ())
	{
		$page ['title'] = $title;
		$page ['text'] = $wf->createFormCode ();
		return $page;
	}

	if (!$wf->validate ())
	{
		$page ['title'] = $title;
		$page ['text'] = $wf->createFormCode ();
		return $page;
	}

	createNewDataSourceRequest ($app);
	header ('Location: ' . $app->urlProtocol . $_SERVER['HTTP_HOST'] . $app->urlRoot . $app->requestPath () . '?done=1');
	die();
}

/**
 * Class siteForm
 * @package E10Pro\Hosting\Client
 */
class siteForm extends \E10\WebForm
{
	protected $dataSources = [];

	public function fields ()
	{
		return ['login', 'password', 'loginRemember'];
	}

	public function successMsg ()
	{
		return '';
	}

	public function createFormCode ()
	{
		$c = '';

		$c .= "
		<!--[if lt IE 9]>
			<div class='label label-warning' style='padding: 2ex;'>
				Váš prohlížeč bohužel není podporován. Aby všechno fungovalo, je vyžadován <a href='http://windows.microsoft.com/cs-CZ/internet-explorer/products/ie/home'>Internet Explorer verze 9</a>.<br><br>
				Pokud používáte Windows XP, můžete si nainstalovat třeba <a href='http://www.google.cz/chrome/' target='_blank'>Google Chrome</a>
				nebo <a href='http://www.mozilla.org/cs/firefox/new/' target='_blank'>Firefox</a>.
			</div>
		<![endif]-->";

		$c .= "<form class='form-horizontal' method='POST'>";
		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='e10pro.hosting.client.siteForm'/>";

		$c .= $this->addFormInput ('E-mail', 'email', 'login');
		$c .= $this->addFormInput ('Heslo', 'password', 'password');

		if ($this->app->authenticator->option ('enableLoginRemember', 0))
			$c .= $this->addFormInput ('Zapamatovat si přihlášení', 'checkbox', 'loginRemember');

		$c .= "<div class='form-group'>";
		$c .= "<div class='col-sm-offset-2 col-sm-10'>";
		$c .= "<button type='submit' class='btn btn-primary'>Přihlásit se</button>";
		$c .= '</div>';
		$c .= '</div>';

		$c .= "</fieldset></form>";

		return $c;
	}

	public function validate ()
	{
		if ($this->app->testPostParam ('login') == '')
		{
			$this->formErrors ['login'] = 'E-email není vyplněn';
			return FALSE;
		}

		if ($this->app->testPostParam ('password') == '')
		{
			$this->formErrors ['password'] = 'Heslo není vyplněno';
			return FALSE;
		}

		$credentials = ['login' => $this->data['login'], 'password' => $this->data['password']];

		$opts = array(
			'http'=>array(
				'method'=>"GET",
				'header'=>"e10-login-user: " . base64_encode($credentials['login']). "\r\n" .
					"e10-login-pw: " . base64_encode($credentials['password']). "\r\n" .
					"Connection: close\r\n"
			)
		);
		$context = stream_context_create($opts);

		$url = $this->app->cfgItem ('authServerUrl') . '/users.php?op=userDataSources&site='.$_SERVER['HTTP_HOST'];
		$resultCode = file_get_contents ($url, false, $context);
		if ($resultCode === FALSE)
			$resultCode = file_get_contents ($url, false, $context);
		$resultData = json_decode ($resultCode, true);
		if (!isset ($resultData ['data']['success']) || $resultData ['data']['success'] !== 1)
		{
			$this->formErrors ['password'] = 'Přihlášení selhalo.';
			return FALSE;
		}

		$this->dataSources = $resultData['data']['dataSources'];

		return TRUE;
	}

	public function doIt ()
	{
		if (count($this->dataSources) === 1)
		{
			header ('Location: ' . $this->dataSources['urlApp'].'app');
			die();
		}

		$c = '';
		foreach ($this->dataSources as $ds)
		{
			$c .= "<a href='{$ds['urlApp']}app'>".utils::es($ds['name'])."</a>"."<br/>";
		}

		return $c;
	}
}


