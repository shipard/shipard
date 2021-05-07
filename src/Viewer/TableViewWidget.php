<?php

namespace Shipard\Viewer;

class TableViewWidget extends TableView
{
	public $mainWidgetClass;

	public function __construct ($table, $viewId, $queryParams = NULL)
	{
		parent::__construct ($table, $viewId, $queryParams);
		$this->objectSubType = TableView::vsMini;

		$this->htmlRowsElement = 'div';
		$this->htmlRowElement = 'div';
	}

	public function createItemMenuCode ($item, $class = 'e10-tvw-item-menu right')
	{
		$deleted = false;
		$trash = $this->table->app()->model()->tableProperty ($this, 'trash');
		if ($trash != FALSE)
		{
			$trashColumn = $trash ['column'];

			if (isset ($trash ['value']))
				$trashValue = $trash ['value'];
			else
				$trashValue = 1;

			if ($item [$trashColumn] == $trashValue)
				$deleted = true;
		}

		$c  = "<span class='$class'>";

		if (!$deleted)
		{
			$c .= "<button class='btn btn-primary df2-action-trigger' data-formId='default' data-action='editform'>Opravit</button> ";
		}

		if ($trash != FALSE)
		{
			if ($deleted)
				$c .= "<button class='btn btn-success df2-action-trigger' data-action='undeleteform'>Vzít z koše zpět</button> ";
			else
				$c .= "<button class='btn btn-danger df2-action-trigger' data-action='deleteform'>Smazat</button> ";
		}

		$c .= "</span>";
		return $c;
	}
}

