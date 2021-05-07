<?php

namespace services\sw\dc;

use e10\utils, e10\json;


/**
 * Class SWQueue
 * @package services\sw\dc
 */
class SWQueue extends \e10\DocumentCard
{
	var $dataOriginal = NULL;

	/** @var \services\sw\libs\SWInfoAnalyzer */
	var $analyzer;

	public function createContentBody ()
	{
		$to = [];
		foreach ($this->dataOriginal as $k => $v)
		{
			if (is_array($v))
			{
				$labels = [];
				foreach ($v as $i)
					$labels[] = ['text' => $i, 'class' => 'label label-default'];
				$to[] = ['k' => $k, 'v' => $labels];
			}
			else
				$to[] = ['k' => $k, 'v' => $v];
		}
		$h = ['k' => 'Klíč', 'v' => 'Hodnota'];

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $to, 'header' => $h,
			'paneTitle' => ['text' => 'Analyzovaná data', 'class' => 'h2'],
		]);

		$ha = ['#' => '#', 'id' => 'OP ID', 'data' => 'Data'];
		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->analyzer->protocol, 'header' => $ha,
			'paneTitle' => ['text' => 'Průběh analýzy', 'class' => 'h2'],
		]);
	}

	public function createContent ()
	{
		$this->dataOriginal = json_decode($this->recData ['data'], TRUE);
		$this->analyzer = new \services\sw\libs\SWInfoAnalyzer($this->app());
		$this->analyzer->setSrcData($this->dataOriginal);
		$this->analyzer->run();

		$this->createContentBody ();
	}
}
