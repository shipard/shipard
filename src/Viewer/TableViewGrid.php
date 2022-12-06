<?php

namespace Shipard\Viewer;
use \Shipard\Utils\Utils;


class TableViewGrid extends \Shipard\Viewer\TableView
{
	public function __construct ($table, $viewId, $queryParams = NULL)
	{
		parent::__construct ($table, $viewId, $queryParams);
		$this->objectSubType = TableView::vsMini;

		$this->htmlRowsElement = 'table';
		$this->htmlRowElement = 'tr';
	}

	public function addEndMark ()
	{
		$colSpan = count ($this->gridStruct);
		if ($this->gridEditable)
			$colSpan++;
		$cls = ($this->rowsLoadNext) ? 'e10-viewer-list-endNext' : 'e10-viewer-list-endEnd';
		$txt = ($this->rowsLoadNext) ? 'Načítají se další řádky' : 'To je všechno';

		$c = "<tr class='$cls'>" .
					"<td colspan='$colSpan'>".
						Utils::es ($txt) .
						'</td></tr>';
		$this->addHtmlItem($c);
	}

	function createViewerBodyCode ()
	{
		$c = '';

		$tableClass = 'dataGrid';
		if ($this->gridEditable)
			$tableClass .= ' editable';
		else
			$tableClass .= ' static';
		if ($this->fullWidthToolbar)
			$c .= $this->createFullWidthToolbarCode();

		$c .= "<div class='e10-sv-body'>";
			if (!$this->fullWidthToolbar)
				$c .= $this->createTopMenuSearchCode();
			$c .= "<div class='df2-viewer-list e10-viewer-list dataGrid' id='{$this->vid}Items' data-rowspagenumber='0'"."data-viewer='$this->vid' data-rowelement='tr'>";
				$c .= "<table class='$tableClass main'>";
					$c .= $this->gridTableHeaderCode;
					$c .= $this->rows ();
				$c .= "</table>";
			$c .= "</div>";
			$c .= $this->createBottomTabsCode ();
		$c .= "</div>";

		$c .= $this->createRightPanelCode();

		return $c;
	}

	public function rowHtml ($listItem)
	{
		$class = isset ($listItem ['groupName']) ? 'g' : 'r';
		if (isset ($listItem['class']))
			$class .= " {$listItem['class']}";
		if ($this->gridEditable)
			$class .= ' e';
		$pk = isset ($listItem ['pk']) ? $listItem ['pk'] : '';
		$codeLine = '<'.$this->htmlRowElement." class='$class' data-pk='" . $pk . "'>";
		$codeLine .= $this->rowHtmlContent ($listItem);
		$codeLine .= '</'.$this->htmlRowElement.'>';

		return $codeLine;
	} // rowHtml


	public function rowHtmlContent ($listItem)
	{
		$c = '';

		$itemLevel = 0;
		if (isset ($listItem ['level']))
			$itemLevel = intval ($listItem ['level']);

		$r = $listItem;
		$colSpan = 0;

		if (isset ($listItem ['groupName']))
		{
			if ($this->gridEditable)
			{
				$c .= "<td class='e10-icon";
				if (isset ($listItem['class']))
					$c .= ' '.$listItem['class'];
				$c .= "'>";

				if ((isset ($listItem ['icon'])) && ($listItem ['icon'] !== ''))
				{
					$c .= $this->app()->ui()->icon($listItem ['icon']);
				}
				$c .= '</td>';
			}

			$c .= "<td class='g' colspan='".(count($this->gridStruct))."'>" . $this->app()->ui()->renderTextLine($listItem ['groupName']) . '</td>';
			return $c;
		}

		if ($this->gridEditable)
		{
			$c .= "<td class='e10-icon";
			if (isset ($listItem['class']))
				$c .= ' '.$listItem['class'];
			$c .= "'>";

			if ((isset ($listItem ['icon'])) && ($listItem ['icon'] !== ''))
			{
				$c .= $this->app()->ui()->icon($listItem ['icon']);
			}

			if (isset ($listItem['rowNtfBadge']))
				$c .= ' '.$listItem['rowNtfBadge'];

			$c .= '</td>';
		}

		foreach ($this->gridStruct as $cn => $ch)
		{
			if ($colSpan)
			{
				$colSpan--;
				continue;
			}
			if ($cn == '#')
				$cv = strval ($this->lineRowNumber + $this->rowsFirst).'.';
			else
				$cv = isset ($r[$cn]) ? $r[$cn] : '';

			if ($cv instanceof \DateTimeInterface)
				$ct = $cv->format ('d.m.Y');
			else if (is_double ($cv) || is_int ($cv))
			{
				if (isset ($sums [$cn]))
					$sums [$cn] += $cv;
				if (is_int ($cv))
					$ct = Utils::nf ($cv, 0);
				else
					$ct = Utils::nfu ($cv, 2);
			}
			else if (is_array($cv))
				$ct = $this->app()->ui()->composeTextLine ($cv);
			else
				$ct = $cv;

			$cellClass = $this->gridColClasses[$cn];
			if (isset ($r ['_options']) && isset ($r ['_options']['cellClasses'][$cn]))
				$cellClass .= ' '.$r ['_options']['cellClasses'][$cn];

			$cellCss = '';
			if (isset ($r ['_options']) && isset ($r ['_options']['cellCss'][$cn]))
				$cellCss .= " style='".$r ['_options']['cellCss'][$cn]."'";

			if (isset ($r ['_options']) && isset ($r ['_options']['colSpan'][$cn]))
			{
				$colSpan = $r ['_options']['colSpan'][$cn];
				$c .= "<td class='$cellClass'$cellCss colspan='$colSpan'>$ct</td>";
				$colSpan--;
			}
			else
				$c .= "<td class='$cellClass'$cellCss>$ct</td>";
		}

		return $c;
	} // rowHtmlContent

	function sumRow($item)
	{
		return NULL;
	}
}
