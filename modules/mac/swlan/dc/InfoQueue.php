<?php

namespace mac\swlan\dc;

use e10\utils, e10\json;


/**
 * Class InfoQueue
 * @package mac\swlan\dc
 */
class InfoQueue extends \e10\DocumentCard
{
	public function createContentBody ()
	{
		// -- dataSanitized
		$to = [];
		$dataOriginal = json_decode($this->recData ['dataSanitized'], TRUE);
		foreach ($dataOriginal as $k => $v)
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



		// -- dataOriginal
		$to = [];
		$dataOriginal = json_decode($this->recData ['dataOriginal'], TRUE);
		foreach ($dataOriginal as $k => $v)
		{
			$to[] = ['k' => $k, 'v' => $v];
		}
		$h = ['k' => 'Klíč', 'v' => 'Hodnota'];

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $to, 'header' => $h,
			'paneTitle' => ['text' => 'Zdrojové informace', 'class' => 'h2'],
		]);
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
