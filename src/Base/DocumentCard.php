<?php

namespace Shipard\Base;
use \e10\base\libs\UtilsBase;
use \Shipard\UI\Core\ContentRenderer;


/**
 * Class DocumentCard
 */
class DocumentCard extends Content
{
	var $recData;
	var $requiredParts = FALSE;
	var $items = [];
	var $parts = [];
	var $header = NULL;
	var $disableAttachments = FALSE;
	var $dstObjectType = '';

	CONST spTimeLine = 'tl', spDocuments = 'docs';

	var $response;

	/** @var \Shipard\Table\DbTable $table */
	var $table;

	public function addItem ($partId, $item)
	{
		$id = isset($item['id']) ? $item['id'] : $item['table'].'-'.$item['ndx'];
		$this->items[$partId][$id] = $item;
	}

	public function addPart ($partId, $partDefinition)
	{
		$this->parts[$partId] = $partDefinition;
		if (!isset ($this->items[$partId]))
			$this->items[$partId] = [];
	}

	public function addSystemPart ($partId)
	{
		if (isset ($this->parts[$partId]))
			return;

		if ($partId === self::spTimeLine)
		{
			$this->addPart(self::spTimeLine, [
					'title' => 'Pošta a úkoly', 'icon' => 'icon-clock-o',
					'orderId' => 100000, 'XXXheaderDates' => TRUE, 'enableBlank' => TRUE, 'workflowButtons' => 1,
				]
			);
		}
		elseif ($partId === self::spDocuments)
			$this->addPart(self::spDocuments, ['title' => 'Dokumenty', 'icon' => 'icon-folder-o', 'orderId' => 100]);
	}

	public function docStateClass ()
	{
		$class = '';
		$docState = $this->table->getDocumentState ($this->recData);
		if ($docState)
		{
			$docStateClass = $this->table->getDocumentStateInfo ($docState ['states'], $this->recData, 'styleClass');
			if ($docStateClass)
			{
				$iconClass = 'e10-docstyle-on';
				$class = ' '.$docStateClass;
				$stateIcon = $this->table->getDocumentStateInfo ($docState ['states'], $this->recData, 'styleIcon');
				$stateText = \E10\es ($this->table->getDocumentStateInfo ($docState ['states'], $this->recData, 'name'));
			}
		}
		return $class;
	}

	public function setDocument ($table, $recData)
	{
		$this->table = $table;
		$this->recData = $recData;
	}

	public function addContentAttachments ($toRecId, $tableId = FALSE, $title = FALSE, $downloadTitle = FALSE)
	{
		if ($this->disableAttachments)
			return;
		if ($tableId === FALSE)
			$tableId = $this->table->tableId();

		$files = UtilsBase::loadAttachments ($this->table->app(), [$toRecId], $tableId);
		if (isset($files[$toRecId]))
			$this->content['body'][] = ['type' => 'attachments', 'attachments' => $files[$toRecId], 'title' => $title, 'downloadTitle' => $downloadTitle];
	}

	public function analyzePart ($partId)
	{
		$this->parts[$partId]['dateFirst'] = NULL;
		$this->parts[$partId]['dateLast'] = NULL;
		$this->parts[$partId]['subHeaders'] = ['years' => [], 'months' => []];
		$this->parts[$partId]['sums'] = [];

		foreach ($this->items[$partId] as $id => &$item)
		{
			if (isset($item['date']))
			{
				if (!$this->parts[$partId]['dateFirst'])
					$this->parts[$partId]['dateFirst'] = $item['date'];
				elseif ($this->parts[$partId]['dateFirst'] > $item['date'])
					$this->parts[$partId]['dateFirst'] = $item['date'];

				if (!$this->parts[$partId]['dateLast'])
					$this->parts[$partId]['dateLast'] = $item['date'];
				elseif ($this->parts[$partId]['dateLast'] < $item['date'])
					$this->parts[$partId]['dateLast'] = $item['date'];

				$yearId = $item['date']->format ('Y');
				$monthId = $item['date']->format ('Y-m');

				if (!isset ($this->parts[$partId]['subHeaders']['years'][$yearId]))
					$this->parts[$partId]['subHeaders']['years'][$yearId] = ['title' => $yearId, 'cntDocs' => 0, 'cntMonths' => 0, 'sums' => []];

				if (!isset ($this->parts[$partId]['subHeaders']['months'][$monthId]))
				{
					$this->parts[$partId]['subHeaders']['months'][$monthId] = ['title' => $monthId, 'cntDocs' => 0, 'sums' => []];
					$this->parts[$partId]['subHeaders']['years'][$yearId]['cntMonths']++;
				}

				$this->parts[$partId]['subHeaders']['years'][$yearId]['cntDocs']++;
				$this->parts[$partId]['subHeaders']['months'][$monthId]['cntDocs']++;

				if (isset($item['sumAmount']))
				{
					$sg = $item['sumGroup'];

					if (!isset($this->parts[$partId]['subHeaders']['years'][$yearId]['sums'][$sg]))
						$this->parts[$partId]['subHeaders']['years'][$yearId]['sums'][$sg] = ['amount' => 0];
					$this->parts[$partId]['subHeaders']['years'][$yearId]['sums'][$sg]['amount'] += $item['sumAmount'];

					if (!isset($this->parts[$partId]['subHeaders']['months'][$monthId]['sums'][$sg]))
						$this->parts[$partId]['subHeaders']['months'][$monthId]['sums'][$sg] = ['amount' => 0];
					$this->parts[$partId]['subHeaders']['months'][$monthId]['sums'][$sg]['amount'] += $item['sumAmount'];

					if (!isset($this->parts[$partId]['sums'][$sg]))
						$this->parts[$partId]['sums'][$sg] = ['amount' => 0];
					$this->parts[$partId]['sums'][$sg]['amount'] += $item['sumAmount'];
				}

				$item['yearId'] = $yearId;
				$item['monthId'] = $monthId;
			}
		}
	}

	protected function addDiaryPinnedContent()
	{
		if (!$this->table->ndx)
			return;

		$diaryContent = [];
		$diary = new \wkf\core\libs\DiaryHelper($this->app());
		$diary->init();
		$diary->pinnedContent($this->table->ndx, $this->recData['ndx'], $diaryContent);
		if (count($diaryContent))
		{
			foreach ($diaryContent as $dc)
			{
				$this->addContent('body', $dc);
			}
		}
	}

	public function createContent()
	{
	}

	protected function createContentInfo ($tileMode = TRUE)
	{
		foreach ($this->parts as $partId => $partDef)
			$this->analyzePart($partId);

		foreach (\E10\sortByOneKey($this->parts, 'orderId', TRUE) as $partId => $partDef)
		{
			if (!count($this->items[$partId]) && !isset($partDef['enableBlank']))
				continue;

			$items = \E10\sortByOneKey($this->items[$partId], 'orderId', FALSE, FALSE);
			$c = '';

			$partClass = 'e10-tl';
			if (isset($partDef['mainClass']))
				$partClass = $partDef['mainClass'];
			elseif ($tileMode)
				$partClass = 'e10-tt';
			$c .= "<div class='$partClass $partId'>";
			$title = [['text' => $partDef['title'], 'icon' => $partDef['icon'], 'class' => 'h1 clear']];

			if (isset($partDef['headerDates']) && $partDef['dateFirst'] && $partDef['dateLast'])
			{
				if ($partDef['dateFirst'] != $partDef['dateLast'])
					$title [] = ['text' => utils::datef($partDef['dateLast']).' ⇋ '.utils::datef($partDef['dateFirst']), 'class' => 'e10-small'];
			}

			$addButtons = [];
			if (isset($partDef['workflowButtons']))
			{
				$btnParams = [];
				$btnParams['srcTableId'] = $this->table->tableId();
				$btnParams['srcRecId'] = $this->recData['ndx'];

				$tableMessages = $this->app()->table('e10pro.wkf.messages');
				$tableMessages->addWorkflowButtons($addButtons, $btnParams);
			}

			$c .= "<div class='title'>";
			$c .= $this->app()->ui()->composeTextLine($title);
			if (count($addButtons))
			{
				$c .= "<span class='pull-right'>";
				$c .= $this->app()->ui()->composeTextLine($addButtons);
				$c .= '</span>';
			}
			$c .= '</div>';

			$lastYearId = '--';
			$lastMonthId = '--';
			foreach ($items as $item)
			{
				$monthId = $item['monthId'];
				$yearId = $item['yearId'];

				if (isset($this->parts[$partId]['subHeaders']['years'][$yearId]['enableMonthHeaders']))
				{
					if ($item['monthId'] !== $lastMonthId)
					{
						$subHeader = [[
								'text' => $this->parts[$partId]['subHeaders']['months'][$monthId]['title'],
								'class' => 'title'
						]];

						$c .= "<div class='tl-header month'>" . $this->app()->ui()->composeTextLine($subHeader) . '</div>';
					}
				}

				$lastYearId = $item['yearId'];
				$lastMonthId = $item['monthId'];

				$c .= $this->timeLineItemCode($item);
			}
			$c .= '</div>';

			$this->addContent('body', ['pane' => 'e10-pane', 'type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
		}
	}

	function timeLineItemCode ($d)
	{
		$c = '';

		$c .= "<div class='e10-tl-doc e10-document-trigger' data-action='edit' data-table='{$d['table']}' data-pk='{$d['ndx']}'>";
		$c .= "<div class='e10-ds {$d['docStateClass']}'>";

		foreach ($d['info'] as $i)
			$c .= $this->app()->ui()->composeTextLine($i);

		$c .= '</div>';
		$c .= '</div>';

		return $c;
	}

	public function doResponse ()
	{
		$cr = new ContentRenderer($this->app);
		$cr->mobile = TRUE;
		$cr->setDocumentCard($this);

		$this->response->add ('table', $this->table->tableId());
		$this->response->add ('pk', strval($this->recData['ndx']));
		$this->response->add ('codeHeader', $cr->createCode('header'));
		$this->response->add ('codeBody', $cr->createCode('body'));
		$this->response->add ('codeTitle', $cr->createCode('title'));
		$this->response->add ('codeSubTitle', $cr->createCode('subTitle'));

		$attCode = $this->app()->ui()->addAttachmentsInputCodeMobile($this->table->tableId(), $this->recData['ndx'], '');

		$this->response->add ('codeFooter', $attCode."<div class='e10-page-end'><i class='fa fa-chevron-up'></i></div>");
	}

	public function response ()
	{
		$this->response = new \e10\Response ($this->app, '');
		$this->createContent();
		$this->doResponse ();
		return $this->response;
	}
}
