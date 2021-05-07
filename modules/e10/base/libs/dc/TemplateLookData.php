<?php

namespace e10\base\libs\dc;
use e10\json;


/**
 * Class TemplateLookData
 * @package e10\base\libs\dc
 */
class TemplateLookData extends \e10\DocumentCard
{
	function loadData()
	{
		$data = json_decode($this->recData['lookParams']);
		if (!$data)
		{
			return;
		}

		$showData = [];
		foreach ($data as $key => $value)
		{
			if ($value === '')
				continue;

			$showData[$key] = $value;
		}

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table',
			'type' => 'text', 'subtype' => 'code', 'text' => json::lint($showData)]);
	}

	public function createContent ()
	{
		$this->loadData();
	}
}


