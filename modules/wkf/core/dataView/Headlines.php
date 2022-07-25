<?php

namespace wkf\core\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class Headlines
 * @package wkf\core\dataView
 */
class Headlines extends DataView
{
	/** @var \wkf\core\TableHeadlines */
	var $tableHeadlines;
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;
	var $maxCnt = 10;
	var $pinned = 0;

	protected function init()
	{
		parent::init();
		$this->tableHeadlines = $this->app()->table('wkf.core.headlines');
		$this->textRenderer = new \lib\core\texts\Renderer($this->app());

		if (isset($this->requestParams['maxCnt']))
		{
			$this->maxCnt = intval($this->requestParams['maxCnt']);
			if (!$this->maxCnt)
				$this->maxCnt = 10;
		}

		if (isset($this->requestParams['pinned']))
		{
			$this->pinned = intval($this->requestParams['pinned']);
		}
	}

	protected function loadData()
	{
		$now = new \DateTime();

		$q [] = 'SELECT headlines.*,';
		array_push ($q, ' attCoverImages.path AS coverImagePath, attCoverImages.fileName AS coverImageFileName');
		array_push ($q, ' FROM [wkf_core_headlines] AS [headlines]');
		array_push ($q, ' LEFT JOIN e10_attachments_files AS attCoverImages ON headlines.image = attCoverImages.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND headlines.docStateMain < %i', 3);
		array_push ($q, ' AND (headlines.dateFrom IS NULL OR headlines.dateFrom = %t', '0000-00-00 00:00:00', ' OR headlines.dateFrom <= %t)', $now);
		array_push ($q, ' AND (headlines.dateTo IS NULL OR headlines.dateTo = %t', '0000-00-00 00:00:00', ' OR headlines.dateTo >= %t)', $now);
		if ($this->pinned)
			array_push ($q, ' AND headlines.onTop != %i', 0);
		$this->extendQuery($q);
		array_push ($q, ' ORDER BY headlines.[onTop] DESC, headlines.[order], headlines.[dateFrom] DESC');
		array_push ($q, ' LIMIT 0, %i', $this->maxCnt);

		$t = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			if ($item['coverImagePath'])
			{
				$imgPath = $this->app()->dsRoot . '/att/' . $item['coverImagePath'] . $item['coverImageFileName'];
				$item['imgPath'] = $imgPath;
			}

			$this->textRenderer->render ($item ['text']);
			$item['htmlText'] = $this->textRenderer->code;

			$t[] = $item;
		}

		$this->data['header'] = ['#' => '#', 'title' => 'Titulek', 'htmlText' => 'Text'];
		$this->data['table'] = $t;
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'html')
			return $this->renderDataAsHtml();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsHtml()
	{
		$c = '';

		$c .= "<div class='container e10w-headlines'>";
		foreach ($this->data['table'] as $headline)
		{
			$c .= $this->renderHeadlineAsHtml($headline);
		}
		$c .= '</div>';

		return $c;
	}

	protected function renderHeadlineAsHtml($headline)
	{
		$widthColumns = 12;
		if (isset($headline['imgPath']) && $headline['useImageAs'] <= 1)
			$widthColumns = 10;

		$rowStyle = '';
		$rowClass = '';
		if (isset($headline['imgPath']) && $headline['useImageAs'] == 2)
		{
			$rowStyle = " style='background-image:url({$headline['imgPath']});'";
			$rowClass = ' e10w-background-image';
		}

		$c = '';
		$c .= "<div class='row mb-2 border p-2$rowClass'$rowStyle>";

		if (isset($headline['imgPath']) && $headline['useImageAs'] == 0)
		{
			$c .= "<div class='col-2'>";
				$c .= "<img style='width:100%;' src='{$headline['imgPath']}'>";
			$c .= '</div>';
		}

		$c .= "<div class='col-$widthColumns'>";
			$c .= '<h4>'.utils::es($headline['title']).'</h4>';
			if (isset($headline['htmlText']))
				$c .= $headline['htmlText'];
		$c .= "</div>";

		if (isset($headline['imgPath']) && $headline['useImageAs'] == 1)
		{
			$c .= "<div class='col-2'>";
			$c .= "<img style='width:100%;' src='{$headline['imgPath']}'>";
			$c .= '</div>';
		}

		if ($headline['linkToUrl'] !== '')
		{
			$c .= "<div>";
			$c .= "<a href='".utils::es($headline['linkToUrl'])."'>".utils::es('Více informací').'</a>';
			$c .= "</div>";
		}

		$c .= '</div>';

		return $c;
	}
}
