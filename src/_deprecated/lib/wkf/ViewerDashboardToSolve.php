<?php

namespace lib\wkf;


use \e10\utils, e10pro\wkf\TableMessages;


/**
 * Class ViewerDashboardToSolve
 * @package lib\wkf
 */
class ViewerDashboardToSolve extends \lib\wkf\ViewerDashboardCore
{
	var $dateTomorrow;
	var $dateNextWeek;

	var $viewerGroups = [
		'past' => ['title' => ['text' => 'Po termínu', 'icon' => 'icon-frown-o', 'class' => 'e10-widget-big-number e10-error']],
		'now' => ['title' => ['text' => 'Dnes a zítra', 'icon' => 'icon-gavel', 'class' => 'e10-widget-big-text e10-me']],
		'nextWeek' => ['title' => ['text' => 'Do týdne', 'icon' => 'icon-bell', 'class' => 'e10-widget-primary-number']],
		'future' => ['title' => ['text' => 'Po příštím týdnu', 'icon' => 'icon-coffee', 'class' => 'e10-widget-primary-number']],
		'nodate' => ['title' => ['text' => 'Bez termínu', 'icon' => 'icon-calendar-times-o', 'class' => 'h2 e10-off']],
	];

	public function init ()
	{
		$this->usePanelRight = 1;
		$this->msgTypes = [TableMessages::mtInbox, TableMessages::mtIssue, TableMessages::mtActivity];
		parent::init();

		$this->dateTomorrow = new \DateTime('+2 days');
		$this->dateNextWeek = new \DateTime('+1 week');
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		array_push ($q, ' AND (');

		// -- my task (issues with deadline)
		array_push ($q, '(',
				'messages.[msgType] IN %in', $this->msgTypes,
				' AND (messages.[docStateMain] = 1)');
		$this->qryForLinkedPersons ($q, ['e10pro-wkf-message-assigned', 'e10pro-wkf-message-participant']);
		array_push ($q, ')');

		// -- my inbox
		array_push ($q, ' OR (',
				'messages.[msgType] = %i', TableMessages::mtInbox, 'AND (messages.[docStateMain] = 1)');
		$this->qryForLinkedPersons ($q, ['e10pro-wkf-message-to', 'e10pro-wkf-message-notify']);
		array_push ($q, ')');

		array_push ($q, ' OR (',
				'messages.[msgType] IN %in', $this->msgTypes,
				' AND messages.author = %i', $this->thisUserId, ' AND messages.docStateMain = 0',
				')');

		if ($this->app->hasRole ('scrtr'))
		{
			array_push ($q, ' OR (',
					'messages.[msgType] = %i', TableMessages::mtInbox,
					' AND messages.source = %i', 1, ' AND messages.docStateMain = 0',
					')');
		}

		array_push ($q, ')');
	}

	protected function qryOrder (&$q, $selectPart)
	{
		array_push ($q, ' ORDER BY -messages.date DESC, messages.[docStateMain], messages.dateTouch');
	}

	function checkViewerGroup (&$item)
	{
		if ($item['date'])
		{
			if ($item['date'] < $this->today)
				$item['vgId'] = 'past';
			elseif ($item['date'] < $this->dateTomorrow)
				$item['vgId'] = 'now';
			elseif ($item['date'] < $this->dateNextWeek)
				$item['vgId'] = 'nextWeek';
			else
				$item['vgId'] = 'future';
		}
		else
		{
			$item['vgId'] = 'nodate';
		}

		$this->addViewerGroup($item['vgId'], $this->viewerGroups[$item['vgId']]);
	}

	function addViewerGroup ($groupId, $groupDef)
	{
		if (!isset($this->objectData['viewerGroups']))
			$this->objectData['viewerGroups'] = [];
		if (isset ($this->objectData['viewerGroups'][$groupId]))
			return;

		$this->objectData['viewerGroups'][$groupId] = ['code' => $this->app()->ui()->composeTextLine($groupDef['title'])];
	}
}
