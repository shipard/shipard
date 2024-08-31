<?php

namespace Shipard\Utils;
use \e10\utils;



class TableRenderer extends \Shipard\Base\BaseObject
{
	protected $columns;
	protected $data;
	protected $params;
	protected $header;

	protected $colClasses = array();
	protected $colTitles = array();
	protected $sums = array();

	protected $lineNumber = 1;
	protected $precision = 2;
	protected $minPrecision = -1;
	protected $disableZeros = 0;

	var $form = NULL;
	var $subColumnInfo = NULL;
	var $subColumnData = NULL;
	var $formColumnId = NULL;

	var $nls = '';

	private $rowSpan;

	public function __construct ($data, $columns, $params, $app)
	{
		parent::__construct($app);

		$this->columns = $columns;
		$this->data = $data;
		$this->params = $params;

		if (isset($params['header']))
			$this->header = $params['header'];

		if (isset($params['nls']))
			$this->nls = $params['nls'];

		if (isset($params['disableZeros']))
			$this->disableZeros = intval($params['disableZeros']);

		if (isset($params['subColumnInfo']))
			$this->subColumnInfo = $params['subColumnInfo'];
		if (isset($params['subColumnData']))
			$this->subColumnData = $params['subColumnData'];
	}

	public function init ()
	{
		foreach ($this->columns as $cn => $ch)
		{
			$this->colClasses [$cn] = '';

			if (!is_string($ch))
			{
				$this->colTitles [$cn] = $ch;
				continue;
			}

			if ($ch === '_')
				continue;
			if ($ch === '') {}
			else
			if ($ch [0] === '+')
			{
				$this->colTitles [$cn] = substr ($ch, 1);
				$this->sums [$cn] = 0;
				$this->colClasses [$cn] = 'number';
			}
			else if ($ch [0] === ' ')
			{
				$this->colTitles [$cn] = substr ($ch, 1);
				$this->colClasses [$cn] = 'number';
			}
			else if ($ch [0] === '_')
			{
				$this->colTitles [$cn] = substr ($ch, 1);
				$this->colClasses [$cn] = 'nowrap';
			}
			else if ($ch [0] === '|')
			{
				$this->colTitles [$cn] = substr ($ch, 1);
				$this->colClasses [$cn] = 'center';
			}
			else if ($ch [0] === '>')
			{
				$this->colTitles [$cn] = substr ($ch, 1);
				$this->colClasses [$cn] = 'level';
			}
			else if ($cn === '#')
			{
				$this->colTitles [$cn] = $ch;
				$this->colClasses [$cn] = 'number';
			}
			else
				$this->colTitles [$cn] = $ch;
		}

		if (!isset ($this->header))
		{
			$this->header = array ();
			$this->header[]= $this->colTitles;
		}

		if (isset ($this->params ['precision']))
			$this->precision = intval($this->params ['precision']);
		if (isset ($this->params ['minPrecision']))
			$this->minPrecision = intval($this->params ['minPrecision']);
	}

	protected function formatMoney ($money, $disableZeros)
	{
		if ($disableZeros && !$money)
			return '';

		if (isset ($this->params ['resultFormat']))
		{
			switch(strval($this->params['resultFormat']))
			{
				case	'1000': return utils::nf ($money, 0);
				case	'K': return utils::nf ($money / 1000, $this->precision);
				case	'M': return utils::nf ($money / 1000000, $this->precision);
			}
		}

		if ($this->minPrecision !== -1)
			return utils::nfu ($money, $this->minPrecision);

		return utils::nf ($money, $this->precision);
	}

	protected function renderRows ($rows, $tablePart, $td)
	{
		$c = '';
		$this->rowSpan = [];
		foreach ($rows as $r)
			$c .= $this->renderRow($r, $tablePart, $td).$this->nls;
		return $c;
	}

	public function renderAllRows()
	{
		return $this->renderRows($this->data, 0, 'td');
	}

	public function renderRow ($r, $tablePart, $td, $ignoreSum = FALSE)
	{
		$c = '';
		$cntColumns = count($this->columns);

		if (isset ($r ['_options']) && isset ($r ['_options']['beforeSeparator']))
			$c .= "<tr class='{$r ['_options']['beforeSeparator']}'><td colspan='$cntColumns'></td></tr>";
		$rowClasses = '';
		if (isset ($r ['_options']) && isset ($r ['_options']['class']))
			$rowClasses = $r['_options']['class'];
		$rowParams = '';
		if (isset ($r['_options']) && isset ($r['_options']['expandable']))
		{
			$level = $r['_options']['expandable']['level'];
			$rowClasses .= ' e10-sum-table-exp-row';
			$rowClasses .= ' e10-sum-table-exp-row-'.$level;
			if (isset($this->params['bgLevels'][$level]))
				$rowClasses .= ' '.$this->params['bgLevels'][$level];

			if (isset($r['_options']['expandable']['exp-this-id']))
				$rowParams .= " data-exp-this-id='".$r['_options']['expandable']['exp-this-id']."'";
			if (isset($r['_options']['expandable']['exp-parent-id']))
				$rowParams .= " data-exp-parent-id='".$r['_options']['expandable']['exp-parent-id']."'";
		}
		if (isset ($r['_options']) && isset ($r['_options']['selectable']))
		{
			$rowClasses .= ' e10-sum-table-row-selectable';
			$rowParams .= " data-selectable-row-id='".$r['_options']['selectable']."'";
		}

		$c .= '<tr';
		if ($rowClasses !== '')
			$c .= " class='$rowClasses'";
		$c .= $rowParams.'>';

		$disableZeros = $this->disableZeros;
		if (isset ($r ['_options']) && isset ($r ['_options']['disableZeros']))
			$disableZeros = $r ['_options']['disableZeros'];

		//$cntCols = count ($r);
		$colSpan = 0;
		foreach ($this->columns as $cn => $ch)
		{
			if ($cn === '_options')
				continue;
			$cid = 0;
			if ($colSpan)
			{
				$colSpan--;
				$cid = 1;
			}

			if (isset ($this->rowSpan[$cn]) && $this->rowSpan[$cn])
			{
				$this->rowSpan[$cn]--;
				$cid = 1;
			}

			if ($cn == '#')
			{
				if (!isset ($r ['_options']['class']) ||
						($r ['_options']['class'] != 'subheader' && $r ['_options']['class'] != 'subtotal' && (substr($r ['_options']['class'], 0, 3) != 'sum') && !isset($r['_options']['noIncRowNum'])))
							$cv = ($tablePart === 0) ? $this->lineNumber.'.' : $ch;
				else
					$cv = isset($r[$cn]) ? $r[$cn] :'';
			}
			else if ($ch == '_')
				$cv = "<input type='checkbox' value='{$r[$cn]}'>";
			else
				$cv = isset ($r[$cn]) ? $r[$cn] : '';

			$ct = '';
			if ($cv instanceof \DateTimeInterface)
				$ct = $cv->format ('d.m.Y');
			else if (is_double ($cv) || is_int ($cv))
			{
				if ($tablePart === 0 && isset ($this->sums [$cn]) && !$ignoreSum)
					$this->sums [$cn] += $cv;
				if (is_int ($cv))
				{
					if ($disableZeros && !$cv)
						$ct = '';
					else
						$ct = utils::nf($cv, 0);
				}
				else
					$ct = $this->formatMoney($cv, $disableZeros);
			}
			else if (is_array($cv))
			{
				if (isset($cv['scLabel']))
				{
					//$colDef = utils::searchArray($this->subColumnInfo['columns'], 'id', $cv['scLabel']);
					$colDef = utils::searchArray($this->subColumnInfo['columns'], 'id', $cv['scLabel']);
					if ($colDef)
					{
						$label = $colDef['name'];
						$ct = $label;
					}
					else
						$ct = '!'.$cv['scLabel'].'_'.json_encode($this->subColumnInfo);
				}
				elseif (isset($cv['scInput']))
				{
					if ($this->form)
					{
						$colDef = utils::searchArray($this->subColumnInfo['columns'], 'id', $cv['scInput']);
						$sco = \Shipard\UI\Core\UIUtils::subColumnEnabled ($colDef, $this->subColumnData[$cv['scInput']]);
						if ($sco !== FALSE)
						{
							$this->form->addColumnInput($cv['scInput'], $sco, FALSE, $this->formColumnId);
							$ct = $this->form->lastInputCode;
						}
					}
					elseif ($this->subColumnData)
					{
						if (isset($this->subColumnData[$cv['scInput']]))
						{
							$colDef = utils::searchArray($this->subColumnInfo['columns'], 'id', $cv['scInput']);
							if ($this->app)
								$ct = $this->app->subColumnValue ($colDef, $this->subColumnData[$cv['scInput']]);
							else
								$ct = strval($this->subColumnData[$cv['scInput']]);
						}
					}
				}
				elseif (isset($cv['scInputValue']))
				{
					$colDef = utils::searchArray($this->subColumnInfo['columns'], 'id', $cv['scInputValue']);
					$ct = $this->app->subColumnValue ($colDef, $this->subColumnData[$cv['scInputValue']]);
				}
				else
					$ct = $this->app()->ui()->composeTextLine($cv, '');
			}
			else
				$ct = utils::es(strval($cv));

			if (isset($r ['_options']['cellExtension'][$cn]))
				$ct .= '<div>'.$this->app()->ui()->composeTextLine ($r ['_options']['cellExtension'][$cn], '').'</div>';

			$cellClasses = $this->colClasses[$cn];
			if ($td === 'th' && isset($this->params['header']))
				$cellClasses = '';
			if (isset ($r ['_options']) && isset ($r ['_options']['cellClasses'][$cn]))
			{
				if (count($this->header) > 1)
					$cellClasses = ' '.$r ['_options']['cellClasses'][$cn];
				else $cellClasses .= ' '.$r ['_options']['cellClasses'][$cn];
			}

			if (!isset ($r[$cn]))
				$cellClasses .= ' unusedCell';

			if (isset ($this->params['colClasses']) && isset ($this->params['colClasses'][$cn]))
				$cellClasses .= ' '.$this->params['colClasses'][$cn];

			if (isset ($r['_options']) && isset ($r['_options']['expandable']) && $r['_options']['expandable']['column'] === $cn)
			{
				$cellClasses .= ' e10-sum-table-exp-cell';
				$cellClasses .= ' e10-sum-table-exp-cell-'.$r['_options']['expandable']['level'];
				$expParams = " data-next-level='".($r['_options']['expandable']['level']+1)."'";
				if (isset($r['_options']['expandable']['query-params']))
				{
					foreach ($r['_options']['expandable']['query-params'] as $qpk => $qpv)
						$expParams .= ' data-query-'.$qpk."='".utils::es($qpv)."'";
				}
				$expCode = "<span class='e10-sum-table-exp-icon expandable' data-exp-parent-id='{$r['_options']['expandable']['exp-parent-id']}'$expParams>".$this->app->ui()->icon('system/actionExpandOpen')."</span>&nbsp;";

				$ct = $expCode.$ct;
			}

			$ccp = '';
			if ($cellClasses !== '')
				$ccp = " class='$cellClasses'";

			if (isset ($r ['_options']) && isset ($r ['_options']['colSpan'][$cn]))
			{
				$colSpan = $r ['_options']['colSpan'][$cn];
				$ccp .= " colspan='$colSpan'";
				$colSpan--;
			}

			if (isset ($r ['_options']) && isset ($r ['_options']['rowSpan'][$cn]))
			{
				$this->rowSpan[$cn] = $r ['_options']['rowSpan'][$cn];
				$ccp .= " rowspan='{$this->rowSpan[$cn]}'";
				$this->rowSpan[$cn]--;
			}

			if (isset ($r ['_options']) && isset ($r ['_options']['cellTitles'][$cn]))
				$ccp .= " title=\"".utils::es($r ['_options']['cellTitles'][$cn])."\"";

			$cellStyle = '';
			if (isset ($r ['_options']) && isset ($r ['_options']['cellCss'][$cn]))
				$cellStyle = $r ['_options']['cellCss'][$cn];

			if (isset ($this->params['colCss']) && isset ($this->params['colCss'][$cn]))
				$cellStyle .= ' '.$this->params['colCss'][$cn];

			if ($cellStyle !== '')
				$ccp .= ' style="'.$cellStyle.'"';

			if (isset ($r ['_options']) && isset ($r ['_options']['cellData'][$cn]))
			{
				foreach ($r['_options']['cellData'][$cn] as $cdKey => $cdValue)
				{
					$ccp .= ' data-'.$cdKey."=\"".utils::es($cdValue)."\"";
				}
			}

			if (!$cid)
				$c .= "<$td$ccp>$ct</$td>".$this->nls;
		}
		if ($tablePart === 0 &&
			(!isset ($r ['_options']['class']) ||
				($r ['_options']['class'] != 'subheader' && $r ['_options']['class'] != 'subtotal' && $r ['_options']['class'] != 'sumtotal' && $r ['_options']['class'] != 'sum' && !isset($r['_options']['noIncRowNum']))))
					$this->lineNumber++;
		$c .= "</tr>";

		if (isset ($r ['_options']) && isset ($r ['_options']['afterSeparator']))
			$c .= "<tr class='{$r ['_options']['afterSeparator']}'><td colspan='$cntColumns'></td></tr>".$this->nls;

		return $c;
	}

	public function renderHeader ()
	{
		$tableClass = 'default fullWidth';
		if (isset ($this->params ['forceTableClass']))
			$tableClass = $this->params ['forceTableClass'];
		else
			if (isset ($this->params ['tableClass']))
				$tableClass .= ' ' . $this->params ['tableClass'];

		if ($this->app()->printMode)
		{
			if (isset ($this->params ['forceTableClassPrint']))
				$tableClass = $this->params ['forceTableClassPrint'];
		}

		$c = "<table class='$tableClass'";
		if (isset ($this->params ['tableCss']))
			$c .= ' style="' . $this->params ['tableCss'] . '"';

		$c .='>';

		$showHeader = !isset ($this->params['hideHeader']);
		if ($showHeader)
		{
			$c .= '<thead>';
			$c .= $this->renderRows($this->header, 1, 'th');
			$cntColumns = count($this->columns);
			$c .= '</thead>';
		}

		return $c;
	}

	public function renderFooter ()
	{
		$c = '';
		if (count ($this->sums))
		{
			$c .= "<tr class='separator'><td colspan=".count($this->columns)."></td></tr>";
			$c .= "<tr class='sumtotal'>";
			foreach ($this->columns as $cn => $ch)
			{
				//$colClasses [$cn] = 'number';
				$ct = "";
				if (isset ($this->sums [$cn]))
				{
					if (is_int ($this->sums [$cn]))
						$ct = Utils::nf ($this->sums [$cn], 0);
					else
						$ct = $this->formatMoney($this->sums [$cn], $this->disableZeros);
				}
				$c .= "<td class='number'>$ct</td>";
			}
			$c .= '</tr>';
		}
		$c .= '</table>';
		return $c;
	}

	public function render ()
	{
		if (!isset($this->columns))
			return '';

		$this->init ();

		$c = $this->renderHeader();

		$c .= '<tbody>';
		if (is_string($this->data))
			$c .= $this->data;
		else
			$c .= $this->renderRows($this->data, 0, 'td');
		$c .= '</tbody>';

		$c .= $this->renderFooter();

		return $c;
	}
}

