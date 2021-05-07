#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once 'e10-modules/e10/server/php/e10-cli.php';

use \E10\CLI\Application, e10\utils, e10\json;


/**
 * Class E10HostingToolsApp
 */
class E10HostingToolsApp extends Application
{
	var $ce = NULL;

	var $apiUrl = '';
	var $apiKey = '';

	var $tablePersons;

	public function clientEngine()
	{
		if (!$this->ce)
		{
			$this->ce = new \lib\objects\ClientEngine($this);
			$this->ce->apiUrl = $this->apiUrl;
			$this->ce->apiKey = $this->apiKey;
		}

		return $this->ce;
	}

	public function sendServers()
	{
		echo "--- sendServers --- \n";

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_servers]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			if ($doc['rec']['owner'])
				$doc['rec']['owner'] = $this->personRecId($doc['rec']['owner']);
			if ($doc['rec']['customer'])
				$doc['rec']['customer'] = $this->personRecId($doc['rec']['customer']);

			json::polish($doc['rec']);

			echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.servers', $doc);
		}
	}

	public function sendDataSources()
	{
		echo "--- sendDataSources --- \n";

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_datasources] AS ds');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			unset ($doc['rec']['application']);
			unset ($doc['rec']['contract']);

			if ($doc['rec']['admin'])
				$doc['rec']['admin'] = $this->personRecId($doc['rec']['admin']);
			if ($doc['rec']['owner'])
				$doc['rec']['owner'] = $this->personRecId($doc['rec']['owner']);
			if ($doc['rec']['payer'])
				$doc['rec']['payer'] = $this->personRecId($doc['rec']['payer']);

			json::polish($doc['rec']);

			$doc['rec']['site'] = 0;

			echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.datasources', $doc);
		}
	}

	public function sendPersons()
	{
		echo "--- sendPersons --- \n";
		$np = $this->neededPersons();

		$q [] = 'SELECT persons.*';
		array_push ($q, ' FROM [e10_persons_persons] AS persons');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [ndx] IN %in', $np);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec']['company'] = $r['company'];
			$doc['rec']['complicatedName'] = $r['complicatedName'];
			$doc['rec']['beforeName'] = $r['beforeName'];
			$doc['rec']['firstName'] = $r['firstName'];
			$doc['rec']['middleName'] = $r['middleName'];
			$doc['rec']['lastName'] = $r['lastName'];
			$doc['rec']['afterName'] = $r['afterName'];
			$doc['rec']['id'] = $r['id'];
			$doc['rec']['gender'] = $r['gender'];
			$doc['rec']['language'] = $r['language'];
			$doc['rec']['fullName'] = $r['fullName'];
			$doc['rec']['docState'] = $r['docState'];
			$doc['rec']['docStateMain'] = $r['docStateMain'];
			$doc['rec']['personType'] = $r['personType'];

			$properties = $this->docProperties('e10.persons.persons', $r['ndx']);
			if (count($properties))
				$doc['lists']['properties'] = $properties;

			echo " - {$r['fullName']} \n";

			$this->clientEngine()->uploadDoc('e10.persons.persons', $doc);
		}
	}

	public function sendUsers()
	{
		echo "--- sendUsers --- \n";

		$neededUsers = [];

		$q [] = 'SELECT persons.*';
		array_push ($q, ' FROM [e10_persons_persons] AS persons');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [roles] != %s', '');
		array_push ($q, ' AND [company] = %i', 0);
		array_push ($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec']['company'] = $r['company'];
			$doc['rec']['complicatedName'] = $r['complicatedName'];
			$doc['rec']['beforeName'] = $r['beforeName'];
			$doc['rec']['firstName'] = $r['firstName'];
			$doc['rec']['middleName'] = $r['middleName'];
			$doc['rec']['lastName'] = $r['lastName'];
			$doc['rec']['afterName'] = $r['afterName'];
			$doc['rec']['id'] = $r['id'];
			$doc['rec']['gender'] = $r['gender'];
			$doc['rec']['language'] = $r['language'];
			$doc['rec']['fullName'] = $r['fullName'];
			$doc['rec']['docState'] = $r['docState'];
			$doc['rec']['docStateMain'] = $r['docStateMain'];
			$doc['rec']['personType'] = $r['personType'];

			$doc['rec']['accountType'] = 1; //force LOCAL account

			$doc['recInsert']['accountState'] = $r['accountState'];
			$doc['recInsert']['login'] = $r['login'];
			$doc['recInsert']['loginHash'] = $r['loginHash'];
			$doc['recInsert']['gid'] = $r['gid'];
			$doc['recInsert']['roles'] = 'user';

			$neededUsers[] = $r['ndx'];

			echo " - {$r['fullName']} \n";

			$this->clientEngine()->uploadDoc('e10.persons.persons', $doc);
		}

		// -- dataSourcesUsers
		echo " ... dataSourcesUsers \n";
		$enabledDataSources = $this->neededDataSources();
		$q = [];
		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_usersds]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND docState = 4000');
		array_push ($q, ' AND datasource IN %in', $enabledDataSources);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			$doc['rec']['user'] = $this->personRecId($doc['rec']['user']);

			json::polish($doc['rec']);

			//echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.usersds', $doc);
		}

		//-- passwords
		echo " ... usersPasswords \n";
		$q = [];
		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10_persons_userspasswords]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND person IN %in', $neededUsers);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();
			$doc['rec']['ndx']++;
			$doc['rec']['person'] = $this->personRecId($doc['rec']['person']);

			json::polish($doc['rec']);

			//echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10.persons.userspasswords', $doc);
		}

		//-- sessions
		echo " ... sessions \n";
		$q = [];
		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10_persons_sessions]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND person IN %in', $neededUsers);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = ['primitive' => 1];
			$doc['rec'] = $r->toArray();
			$doc['rec']['person'] = $this->personRecId($doc['rec']['person']);

			json::polish($doc['rec']);

			$this->clientEngine()->uploadDoc('e10.persons.sessions', $doc);
		}
	}

	public function sendModules()
	{
		echo "--- sendModules --- \n";

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_modules]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			json::polish($doc['rec']);

			echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.modules', $doc);
		}
	}

	public function sendPartners()
	{
		echo "--- sendPartners --- \n";

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_partners]');
		array_push ($q, ' WHERE 1');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			$doc['rec']['owner'] = $this->personRecId($doc['rec']['owner']);

			json::polish($doc['rec']);

			echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.partners', $doc);
		}
	}

	public function sendPortals()
	{
		echo "--- sendPortals --- \n";

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10pro_hosting_server_portals]');
		array_push ($q, ' WHERE 1');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$doc = [];
			$doc['rec'] = $r->toArray();

			unset ($doc['rec']['id']);
			unset ($doc['rec']['owner']);

			json::polish($doc['rec']);

			echo " - {$r['name']} \n";

			$this->clientEngine()->uploadDoc('e10pro.hosting.server.portals', $doc);
		}
	}

	public function sendBase()
	{
		$this->sendPersons();
		$this->sendModules();
		$this->sendServers();
		$this->sendPartners();
		$this->sendPortals();
		$this->sendDataSources();
	}

	public function neededPersons()
	{
		$list = [];

		// from servers
		$rows = $this->db()->query('SELECT * FROM [e10pro_hosting_server_servers] WHERE docState = 4000');
		foreach ($rows as $r)
		{
			if (!in_array($r['owner'], $list))
				$list[] = $r['owner'];
			if (!in_array($r['customer'], $list))
				$list[] = $r['customer'];
		}

		// -- from dataSources
		$rows = $this->db()->query('SELECT * FROM [e10pro_hosting_server_datasources] WHERE docState = 4000');
		foreach ($rows as $r)
		{
			if (!in_array($r['owner'], $list))
				$list[] = $r['owner'];
			if (!in_array($r['admin'], $list))
				$list[] = $r['admin'];
			if (!in_array($r['payer'], $list))
				$list[] = $r['payer'];
		}

		// -- from partners
		$rows = $this->db()->query('SELECT * FROM [e10pro_hosting_server_partners]');
		foreach ($rows as $r)
		{
			if (!in_array($r['owner'], $list))
				$list[] = $r['owner'];
		}

		return $list;
	}

	public function neededDataSources()
	{
		$list = [];

		$rows = $this->db()->query('SELECT * FROM [e10pro_hosting_server_datasources] WHERE docState = 4000');
		foreach ($rows as $r)
		{
			$list[] = $r['ndx'];
		}

		return $list;
	}

	function personRecId($ndx)
	{
		$item = $this->tablePersons->loadItem ($ndx);
		return '@id:'.$item['id'];
	}

	function docProperties($tableId, $ndx)
	{
		$list = [];

		$q[] = 'SELECT * FROM [e10_base_properties]';
		array_push ($q, ' WHERE tableid = %s', $tableId, ' AND recid = %i', $ndx);

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$item = ['group' => $r['group'], 'property' => $r['property'], 'value' => $r['valueString']];
			$list [] = $item;
		}

		return $list;
	}

	public function run ()
	{
		$this->apiUrl = $this->arg('api-url');
		if ($this->apiUrl === FALSE)
			return $this->err('param api-url not found...');

		$this->apiKey = $this->arg('api-key');
		if ($this->apiKey === FALSE)
			return $this->err('param api-key not found...');

		$this->tablePersons = $this->table('e10.persons.persons');

		switch ($this->command ())
		{
			case	'sendPersons': return $this->sendPersons();
			case	'sendServers': return $this->sendServers();
			case	'sendUsers': return $this->sendUsers();
			case	'sendDataSources': return $this->sendDataSources();
			case	'sendModules': return $this->sendModules();
			case	'sendPartners': return $this->sendModules();

			case	'sendBase': return $this->sendBase();
		}
		echo ("unknown or nothing param...\n");
		echo ("USAGE: e10-hosting-tools COMMAND --api-url=https://datasource.shipard.cz/ --api-key=123456789ABCDEFG\n");
	}
}

$myApp = new E10HostingToolsApp ($argv);
$myApp->run ();

echo ("DONE.\n");
