<?php

namespace e10doc\ros;

use \E10\DbTable, \E10\TableForm, \E10\utils, \E10\DataModel;


/**
 * Class TableJournal
 * @package e10doc\ros
 */
class TableJournal extends DbTable
{
	var $rosClasses = [0 => 'label-default', 1 => 'label-success', 2 => 'label-warning', 3 => 'label-danger'];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.ros.journal', 'e10doc_ros_journal', 'Evidence tržeb');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['msgId']];

		$tableHeads = $this->app()->table ('e10doc.core.heads');
		$docRecData = $tableHeads->loadItem($recData ['document']);
		$recInfo = $tableHeads->getRecordInfo ($docRecData);

		$hdr ['info'][] = ['class' => 'title', 'value' => ['text' => $recInfo ['docID'], 'suffix' => $recInfo ['docTypeName']]];

		$rosStates = $this->columnInfoEnum ('state');
		$rosStateInfo = [
			'text' => $rosStates[$recData['state']], 'icon' => 'icon-microchip',
			'class' => 'label '.$this->rosClasses[$recData['state']]
		];
		$hdr ['info'][] = ['class' => 'docState', 'value' => $rosStateInfo];

		return $hdr;
	}
}
