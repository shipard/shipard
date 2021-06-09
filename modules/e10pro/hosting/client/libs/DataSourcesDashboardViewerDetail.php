<?php

namespace e10pro\hosting\client\libs;

use e10\TableViewDetail;


/**
 * Class DataSourcesDashboardViewerDetail
 * @package e10pro\hosting\client\libs
 */
class DataSourcesDashboardViewerDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$cc = new \e10pro\hosting\client\libs\UsersDataSourcesReview($this->app());
		$cc->dsNdx = $this->item['ndx'];
		$cc->create();

		foreach ($cc->content['body'] as $cp)
			$this->addContent($cp);

		$this->createDSHeader($cc);
	}

	function createDSHeader($cc)
	{
		$h = ['icon' => 'system/iconDatabase'];

		if ($cc->dsHeader && count($cc->dsHeader))
			$h['info'] = $cc->dsHeader;
		else
		{
			$h ['info'][] =
			[
				'class' => 'pb05',
				'value' => [
					['text' => $this->item['name'], 'class' => 'h2 e10-me'],
					[
						'text' => '', 'icon' => 'system/iconLink', 'title' => 'Otevřít',
						'action' => 'open-link', 'element' => 'span',
						'data-url-download' => $this->item['urlApp'],
						'class' => 'pull-right df2-action-trigger h3', 'btnClass' => '',
					],
				]
			];

		}

		$this->header = $h;
	}
}
