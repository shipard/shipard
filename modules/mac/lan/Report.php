<?php

namespace mac\lan;

/**
 * Class Report
 * @package mac\lan
 */
class Report extends \E10\GlobalReport
{
	function init ()
	{
		$this->addParamLan();
		parent::init();

		$this->setInfo('param', 'Síť', $this->reportParams ['lan']['activeTitle']);
		$this->setInfo('icon', 'icon-file-code-o');
	}

	protected function addParamLan ()
	{
		$q[] = 'SELECT ndx, fullName FROM mac_lan_lans AS lans';
		array_push($q, ' WHERE lans.docState = 4000');
		array_push($q, ' ORDER BY lans.fullName, lans.ndx');

		$lans = $this->db()->query($q)->fetchPairs();
		$this->addParam('switch', 'lan', ['title' => 'Síť', 'switch' => $lans]);
	}

}
