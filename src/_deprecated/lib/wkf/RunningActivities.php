<?php


namespace lib\wkf;

use e10\ContentRenderer, e10\uiutils, \e10\widgetBoard, e10\utils, e10\Utility;


/**
 * Class RunningActivities
 * @package lib\wkf
 */
class RunningActivities extends Utility
{
	var $worksRecs = [];
	var $content = [];

	public function init()
	{
	}

	function loadWorksRecs()
	{
		$q [] = 'SELECT wr.*';
		array_push ($q, ' FROM [e10pro_wkf_worksRecs] AS wr');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wr.[author] = %i', $this->app()->userNdx());
		array_push ($q, ' AND wr.[docState] = %i', 1200);
		array_push ($q, ' ORDER BY [dateBegin]');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$a = $r->toArray();
			$this->worksRecs[] = $a;
		}
	}

	function load()
	{
		$this->loadWorksRecs();
	}

	public function run ()
	{
		$this->init();
		$this->load();
		$this->createContent();
	}

	public function createContent()
	{
		if (!count($this->worksRecs))
			return;

		$now = new \DateTime;

		$commentsTitle = [['value' => [['text' => 'Probíhající práce', 'icon' => 'icon-paw', 'class' => 'h2']]]];
		$list = ['rows' => [], 'title' => $commentsTitle, 'table' => 'e10mnf.core.workRecs'];
		foreach ($this->worksRecs as $r)
		{
			$wrNdx = $r['ndx'];

			$row = ['ndx' => $wrNdx];
			$tt = [];
			$tt[] = ['text' => $r['subject'], 'icon' => 'system/iconUser', 'class' => 'e10-off'];

			$tt[] = ['text' => utils::datef($r['dateBegin'], '%D, %T'), 'icon' => 'system/actionPlay', 'class' => 'break e10-small'];
			$tt[] = ['text' => utils::dateDiffShort($r['dateBegin'], $now), 'icon' => 'icon-clock-o', 'class' => 'e10-small'];

			$row['title'] = $tt;

			$list['rows'][] = $row;
		}
		$this->content[] = ['list' => $list, 'pane' => 'e10-pane'];
	}

	public function createCode()
	{
		$cr = new ContentRenderer($this->app());
		$cr->content = $this->content;
		$c = $cr->createCode();

		return $c;
	}
}
