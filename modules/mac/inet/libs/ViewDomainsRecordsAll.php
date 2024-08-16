<?php

namespace mac\inet\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;

/**
 * class ViewDomainsRecordsAll
 */
class ViewDomainsRecordsAll extends TableView
{
	var $domain = 0;
	var $domainRecordTypes;

	public function init ()
	{
		parent::init();
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries();

		$this->domainRecordTypes = $this->app()->cfgItem('mac.inet.domainsRecordTypes');

		if ($this->queryParam ('domain'))
		{
			$this->domain = intval($this->queryParam('domain'));
			$this->addAddParam ('domain', $this->domain);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = '';

    array_push($q, 'SELECT [records].*,');
    array_push($q, ' [domains].[domain] AS domainName');
    array_push($q, ' FROM [mac_inet_domainsRecords] AS [records]');
    array_push($q, ' LEFT JOIN [mac_inet_domains] AS [domains] ON [records].[domain] = [domains].[ndx]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
    {
			array_push ($q, ' AND (');
			array_push ($q, ' [records].[hostName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [records].[value] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [domains].[domain] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
    }

		$this->queryMain ($q, '[records].', ['[domains].[domain]', '[displayOrder]', '[hostName]', '[value]', '[priority]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);


    $listItem ['t1'] = ['text'=> ($item['hostName'] === '' ? '@' : $item['hostName']), 'suffix' => '.'.$item['domainName'], 'class' => ''];


		//if ($listItem ['t1'] === '')
		//	$listItem ['t1'] = ['text' => '@', 'class' => 'e10-off'];

		$props = [];
		$props[] = ['text' => $this->domainRecordTypes[$item['recordType']]['name'], 'class' => 'label label-info'];
		$props[] = ['text' => $item['value'], 'class' => ''];
		if ($item['priority'])
			$props[] = ['text' => Utils::nf($item['priority']), 'class' => 'label label-default', 'prefix' => 'pri'];
		if ($item['ttl'])
			$props[] = ['text' => Utils::nf($item['ttl']), 'class' => 'label label-default', 'prefix' => 'ttl'];
		$listItem ['t2'] = $props;

		$listItem ['i2'] = ['text' => '#'.utils::nf($item['registrarId']), 'class' => 'id'];

		return $listItem;
	}
}

