<?php

namespace wkf\msgs;

use \Shipard\Viewer\TableView, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * class TableMsgsRecipients
 */
class TableMsgsRecipients extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('wkf.msgs.msgsRecipients', 'wkf_msgs_msgsRecipients', 'Příjemci zpráv');
	}
}


/**
 * class ViewMsgsRecipients
 */
class ViewMsgsRecipients extends TableView
{
	var $virtualGroups;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['personName'];
		$listItem ['t2'] = $item['email'];

		$props = [];

		if ($item['sent'])
			$props [] = ['icon' => 'system/iconPaperPlane', 'text' => utils::datef ($item['sentDate'], '%D, %T')];
		else
			$props [] = ['icon' => 'icon-hourglass-half', 'text' => 'Čeká se na odeslání'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q = [];

		array_push ($q, 'SELECT recps.*, persons.fullName AS personName');
		array_push ($q, ' FROM [wkf_msgs_msgsRecipients] AS recps');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON recps.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND recps.[msg] = %i', $this->queryParam('msgNdx'));

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([email] LIKE %s OR persons.[fullName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		array_push ($q, ' ORDER BY persons.lastName, persons.fullName, ndx');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}
