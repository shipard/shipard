<?php

namespace wkf\bboard\libs\dataView;
use \lib\dataView\DataView, \Shipard\Utils\Utils;


/**
 * Class BBoard
 */
class BBoard extends DataView
{
	/** @var \wkf\bboard\TableMsgs */
	var $tableMsgs;
	/** @var \lib\core\texts\Renderer */
	var $textRenderer;
	var $bboardNdx = 0;
	var $maxCnt = 10;
	var $pinned = 0;

	protected function init()
	{
		parent::init();
		$this->tableMsgs = $this->app()->table('wkf.bboard.msgs');
		$this->textRenderer = new \lib\core\texts\Renderer($this->app());

		if (isset($this->requestParams['maxCnt']))
		{
			$this->maxCnt = intval($this->requestParams['maxCnt']);
			if (!$this->maxCnt)
				$this->maxCnt = 10;
		}

		if (isset($this->requestParams['bboard']))
		{
			$this->bboardNdx = intval($this->requestParams['bboard']);
		}

		if (isset($this->requestParams['pinned']))
		{
			$this->pinned = intval($this->requestParams['pinned']);
		}

		$this->checkRequestParamsList('withLabels');
		$this->checkRequestParamsList('withoutLabels');
	}

	protected function loadData()
	{
		$now = new \DateTime();

		$q [] = 'SELECT msgs.*,';
		array_push ($q, ' attCoverImages.path AS coverImagePath, attCoverImages.fileName AS coverImageFileName');
		array_push ($q, ' FROM [wkf_bboard_msgs] AS [msgs]');
		array_push ($q, ' LEFT JOIN e10_attachments_files AS attCoverImages ON msgs.image = attCoverImages.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [msgs].[bboard] = %i', $this->bboardNdx);
		array_push ($q, ' AND msgs.docStateMain = %i', 2);
		array_push ($q, ' AND (msgs.publishFrom IS NULL OR msgs.publishFrom = %t', '0000-00-00 00:00:00', ' OR msgs.publishFrom <= %t)', $now);
		array_push ($q, ' AND (msgs.publishTo IS NULL OR msgs.publishTo = %t', '0000-00-00 00:00:00', ' OR msgs.publishTo >= %t)', $now);
		if ($this->pinned)
			array_push ($q, ' AND msgs.pinned != %i', 0);

		if (isset($this->requestParams['withLabels']) && count($this->requestParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE msgs.ndx = recid AND tableId = %s', 'wkf.bboard.msgs',
				' AND [clsfItem] IN %in', $this->requestParams['withLabels'],
				')');
		}
		if (isset($this->requestParams['withoutLabels']) && count($this->requestParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE msgs.ndx = recid AND tableId = %s', 'wkf.bboard.msgs',
				' AND [clsfItem] IN %in', $this->requestParams['withoutLabels'],
				')');
		}

		$this->extendQuery($q);
		array_push ($q, ' ORDER BY msgs.[pinned] DESC, msgs.[order], msgs.[publishFrom] DESC');
		array_push ($q, ' LIMIT 0, %i', $this->maxCnt);

		$t = [];
		$rows = $this->db()->query($q);
		$first = 1;
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$item['first'] = $first;
			if ($item['coverImagePath'])
			{
				$imgPath = 'att/' . $item['coverImagePath'] . $item['coverImageFileName'];
				$item['imgPath'] = $this->app()->dsRoot . '/' .$imgPath;
				$item['imgPathRel'] = $imgPath;
			}

			$this->textRenderer->renderAsArticle ($item ['text'], $this->tableMsgs);
			$item['htmlText'] = $this->textRenderer->code;

			$item['htmlPerex'] = '';
			if ($item ['perex'] !== '')
			{
				$this->textRenderer->renderAsArticle ($item ['perex'], $this->tableMsgs);
				$item['htmlPerex'] = $this->textRenderer->code;
			}
			$t[] = $item;

			$first = 0;
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

		$c .= "<div class='container e10w-bboard'>";
		foreach ($this->data['table'] as $msg)
		{
			$c .= $this->renderMessageAsHtml($msg);
		}
		$c .= '</div>';

		return $c;
	}

	protected function renderMessageAsHtml($msg)
	{
		$widthColumns = 12;
		if (isset($msg['imgPath']) && $msg['useImageAs'] <= 1)
			$widthColumns = 10;

		$rowStyle = '';
		$rowClass = '';
		if (isset($msg['imgPath']) && $msg['useImageAs'] == 2)
		{
			$rowStyle = " style='background-image:url({$msg['imgPath']});'";
			$rowClass = ' e10w-background-image';
		}

		$c = '';
		$c .= "<div class='row mb-2 border p-2$rowClass'$rowStyle>";

		if (isset($msg['imgPath']) && $msg['useImageAs'] == 0)
		{
			$c .= "<div class='col-2'>";
				$c .= "<img style='width:100%;' src='{$msg['imgPath']}'>";
			$c .= '</div>';
		}

		$c .= "<div class='col-$widthColumns'>";
			$c .= '<h4>'.Utils::es($msg['title']).'</h4>';
			if (isset($msg['htmlText']))
				$c .= $msg['htmlText'];
		$c .= "</div>";

		if (isset($msg['imgPath']) && $msg['useImageAs'] == 1)
		{
			$c .= "<div class='col-2'>";
			$c .= "<img style='width:100%;' src='{$msg['imgPath']}'>";
			$c .= '</div>';
		}

		if ($msg['linkToUrl'] !== '')
		{
			$c .= "<div>";
			$c .= "<a href='".Utils::es($msg['linkToUrl'])."'>".Utils::es('Více informací').'</a>';
			$c .= "</div>";
		}

		$c .= '</div>';

		return $c;
	}
}
