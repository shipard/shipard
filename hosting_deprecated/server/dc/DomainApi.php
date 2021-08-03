<?php

namespace e10pro\hosting\server\dc;

use \e10\utils;


/**
 * Class CertDocumentCard
 * @package e10pro\hosting\server\dc
 */
class DomainApi extends \e10\DocumentCard
{
	var $commands = [];
	var $records = [];

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
	}

	function loadData()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10pro_hosting_server_domainsRecords]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [domain] = %i', $this->recData['ndx']);
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' ORDER BY [recordType], [value], [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->records[$r['recordType']][] = $r->toArray();
		}
	}

	public function createContentBody ()
	{
		$this->createApiCommands();

		foreach ($this->commands as $rtId => $commands)
		{
			$title = ['text' => $rtId, 'class' => 'h2'];
			$this->addContent('body', [
				'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
				'type' => 'text', 'text' => $commands,
			]);
		}
	}

	function createApiCommands()
	{
		$project = 'intense-pixel-397';
		$zone = str_replace('.', '-', $this->recData['domain']);

		$recordTypes = $this->app()->cfgItem('e10pro.hosting.server.domainsRecordTypes');
		foreach ($this->records as $rtId => $records)
		{
			$this->commands[$rtId] .= "gcloud dns --project=$project record-sets transaction start --zone=$zone";
			$this->commands[$rtId] .= "\n\n";

			if ($rtId === 'MX')
			{
				$cmd = "gcloud dns --project=$project record-sets transaction add";
				foreach ($records as $rec)
				{
					$cmd .= ' "' . $rec['priority'] . ' ';
					$cmd .= $rec['value'];
					$cmd .= ".";
					$cmd .= '"';
				}
				if ($rec['hostName'] == '')
					$cmd .= " --name=" . $this->recData['domain'] . ".";
				else
					$cmd .= " --name=" . $rec['hostName'] . "." . $this->recData['domain'] . ".";

				$cmd .= " --ttl=300";
				$cmd .= " --type=" . $rec['recordType'];
				$cmd .= " --zone=$zone";
				$cmd .= "\n\n";

				$this->commands[$rtId] .= $cmd;

				$this->commands[$rtId] .= "gcloud dns --project=$project record-sets transaction execute --zone=$zone";

				continue;
			}

			if ($rtId === 'TXT')
			{
				$cmdsByHosts = [];
				foreach ($records as $rec)
				{
					$cmdsByHosts[$rec['hostName']] .= ' "';
					$cmdsByHosts[$rec['hostName']] .= $rec['value'];
					$cmdsByHosts[$rec['hostName']] .= '"';
				}

				foreach ($cmdsByHosts as $hostName => $values)
				{
					$cmd = "gcloud dns --project=$project record-sets transaction add";
					$cmd .= $values;
					if ($hostName == '')
						$cmd .= " --name=" . $this->recData['domain'] . ".";
					else
						$cmd .= " --name=" . $hostName . "." . $this->recData['domain'] . ".";

					$cmd .= " --ttl=300";
					$cmd .= " --type=" . $rec['recordType'];
					$cmd .= " --zone=$zone";
					$cmd .= "\n\n";

					$this->commands[$rtId] .= $cmd;
				}

				$this->commands[$rtId] .= "gcloud dns --project=$project record-sets transaction execute --zone=$zone";

				continue;
			}


			foreach ($records as $rec)
			{
				$cmd = "gcloud dns --project=$project record-sets transaction add ";

				$cmd .= $rec['value'];

				if ($rec['recordType'] === 'CNAME' || $rec['recordType'] === 'MX')
					$cmd .= ".";

				if ($rec['hostName'] == '')
					$cmd .= " --name=" . $this->recData['domain'] . ".";
				else
					$cmd .= " --name=" . $rec['hostName'] . "." . $this->recData['domain'] . ".";

				$cmd .= " --ttl=300";
				$cmd .= " --type=" . $rec['recordType'];
				$cmd .= " --zone=$zone";
				$cmd .= "\n\n";

				$this->commands[$rtId] .= $cmd;
				//$cmd .= 'gcloud dns --project=intense-pixel-397 record-sets transaction add 123 --name=shipard.online. --ttl=300 --type=A --zone=shipard-online';

			}
			$this->commands[$rtId] .= "gcloud dns --project=$project record-sets transaction execute --zone=$zone";

		}
	}
}

