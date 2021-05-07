<?php

namespace e10pro\reports\persons;


class ViewDetailPersonSummary extends \E10\TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->app()->hasRole('finance'))
		{
			$as = new \e10doc\debs\AccountsSummary($this->app());
			$as->setPerson($this->item['ndx']);
			$as->run();

			$title = ['text' => 'Ekonomické vyhodnocení', 'class' => 'h2'];
			if (count($as->all))
				$this->addContent([
						'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => ['accountId' => 'Účet', 'text' => 'Text', 'money' => ' Částka'],
						'table' => $as->all, 'title' => $title,
				]);
			else
				$this->addContent([
						'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => [$title, ['text' => 'Nejsou k dipozici žádná data', 'class' => 'block']],
						'title' => $title,
				]);
		}
	}
}


