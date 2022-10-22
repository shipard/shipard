<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;


class Params extends \Shipard\Base\BaseObject
{
	protected $params = [];
	public $inputClass = '';
	public $externalParamsValues = FALSE;

	public function setParamContent ($paramType, $paramId = FALSE, $options = NULL)
	{
		if ($paramId === FALSE)
			$paramId = $paramType;

		if ($paramType === 'switch')
		{
			$pc = array('id' => $paramId, 'type' => $paramType,
									'value' => 0, 'options' => $options, 'values' => []);
			if (isset($options['title']))
				$pc['title'] = $options['title'];
			$pc['radioBtn'] = isset($options['radioBtn']) ? $options['radioBtn'] : 0;
			$pc['list'] = isset($options['list']) ? $options['list'] : 0;

			$switchData = NULL;
			if (isset ($options['switch']))
				$switchData = $options['switch'];
			else
			if (isset ($options['cfg']))
				$switchData = $this->app->cfgItem($options['cfg'], NULL);

			if (isset ($options['enableAll']))
			{
				$pc['values'] += $options['enableAll'];
				$pc['defaultValue'] = key($options['enableAll']);
			}

			$titleKey = isset ($options['titleKey']) ? $options['titleKey'] : 0;
			foreach ($switchData as $valId => $valTitle)
			{
				if ($titleKey !== 0)
					$pc['values'][$valId] = ['title' => $valTitle[$titleKey]];
				else
					$pc['values'][$valId] = ['title' => $valTitle];

				if (!isset($pc['defaultValue']))
					$pc['defaultValue'] = $valId;
			}
		}
		else
		if ($paramType === 'checkboxes')
		{
			$pc = ['id' => $paramId, 'type' => $paramType, 'value' => 0, 'options' => $options, 'values' => []];
			if (isset($options['title']))
				$pc['title'] = $options['title'];

			foreach ($options['items'] as $valId => $val)
			{
				$pc['values'][$valId] = $val;
			}
		}
		else
		if ($paramType === 'date')
		{
			$pc = array('title' => $options['title'], 'id' => $paramId, 'type' => $paramType, 'value' => '', 'defaultValue' => '', 'options' => $options);
		}
		else
		if ($paramType === 'string')
		{
			$pc = array('title' => $options['title'], 'id' => $paramId, 'type' => $paramType, 'value' => '', 'defaultValue' => '', 'options' => $options);
		}
		else
		if ($paramType === 'float')
		{
			$pc = array('title' => $options['title'], 'id' => $paramId, 'type' => $paramType, 'value' => '', 'defaultValue' => '', 'options' => $options);
		}
		else
		if ($paramType === 'calendarMonth')
		{
			$pc = [
				'title' => isset($options['title']) ? $options['title'] : 'Období',
				'id' => $paramId, 'type' => $paramType, 'options' => $options
			];
			if (!isset($pc['defaultValue']))
			{
				//$pc['defaultValue'] = $valId;
			}
			$this->createCalendarMonthParam ($pc);
		}
		else
		if ($paramType === 'hidden')
		{
			$pc = ['id' => $paramId, 'type' => $paramType, 'defaultValue' => ''];
		}

		if (isset ($pc))
		{
			if (isset ($options['defaultValue']))
				$pc['defaultValue'] = $options['defaultValue'];
			if (isset ($options['groupTitle']))
				$pc['groupTitle'] = $options['groupTitle'];
			$this->params [$paramId] = $pc;
		}
	}

	public function addParam ($paramType, $paramId = FALSE, $options = NULL)
	{
		$this->setParamContent ($paramType, $paramId, $options);
	}

	public function setParams ($params)
	{
		$pl = explode (' ', $params);
		forEach ($pl as $p)
			$this->setParamContent ($p);
	}

	public function createCode ()
	{
		$c = '';
		forEach ($this->params as $paramId => $paramContent)
			$c .= $this->createParamCode($paramId);
		return $c;
	}

	function createParamCode ($paramId)
	{
		if (!isset($this->params [$paramId]))
			return '';
		$p = $this->params [$paramId];
		if ($p['type'] === 'switch')
			return $this->createParamComboCode ($paramId);
		else
		if ($p['type'] === 'checkboxes')
			return $this->createParamCheckboxesCode ($paramId);
		else
		if ($p['type'] === 'date')
			return $this->createParamDateCode ($paramId);
		else
		if ($p['type'] === 'string')
			return $this->createParamStringCode ($paramId);
		else
		if ($p['type'] === 'float')
			return $this->createParamFloatCode ($paramId);
		else
		if ($p['type'] === 'calendarMonth')
			return $this->createCalendarMonthParamCode ($paramId);
		else
		if ($p['type'] === 'hidden')
			return $this->createParamHiddenCode ($paramId);

		return $this->createParamComboCode ($paramId);
	}

	function createParamComboCode ($paramId)
	{
		$p = $this->params [$paramId];

		$activeValue = '';
		if (isset($p['value']))
			$activeValue = $p['value'];

		if (!isset($p['values'][$activeValue]) && isset($p['options']['defaultValue']))
			$activeValue = $p['options']['defaultValue'];

		$activeTitle = $p['values'][$activeValue]['title'];
		$inputClass = $this->paramClass($paramId);

		$c = '';

		if (isset($p['radioBtn']) && $p['radioBtn'] === 1)
		{
			$justified = isset($p['options']['justified']) ? $p['options']['justified'] : 0;
			$grpClass = 'btn-group e10-param';
			if ($justified)
				$grpClass .= ' btn-group-justified';

			$c .= "<div class='$grpClass' data-paramid='$paramId'>";
			if (isset ($p['title']))
				$c .= "<span class='btn btn-default'><b>" . Utils::es($p['title']) . ':</b></span>';
			$first = TRUE;
			forEach ($p['values'] as $pid => $pc)
			{
				$t = is_string($pc['title']) ? Utils::es($pc['title']) : Utils::es($pc['title']['text']);

				$class = ($pid == $activeValue) ? 'active ': '';
				$class .= 'btn btn-default e10-param-btn';

				if ($justified)
					$c .= "<div class='btn-group' role='group'>";
				$c .= "<button data-value='$pid' data-title='$t' class='$class'>" . $this->app()->ui()->composeTextLine($pc['title']);
				if ($first)
				{
					$c .= "<input name='$paramId' type='hidden' class='$inputClass' value='$activeValue' style='display: none;'>";
					$first = FALSE;
				}
				$c .= '</button>';
				if ($justified)
					$c .= '</div>';
			}
			$c .= '</div>';
		}
		elseif (isset($p['radioBtn']) && $p['radioBtn'] === 2)
		{ // table
			$cntCols = 4;

			$c .= "<div class='e10-param e10-pane e10-pane-table' data-paramid='$paramId'>";

			if (isset ($p['title']))
				$c .= "<span class='padd5'><b>" . Utils::es($p['title']) . ':</b></span>';
			$c .= "<input name='$paramId' type='hidden' class='$inputClass' value='$activeValue' style='display: none;'>";
			$c .= "<div>";
			$c .= "<table class='e10-cal-small stripped fullWidth'>";

			$cellIndex = 0;
			forEach ($p['values'] as $pid => $pc)
			{
				if ($cellIndex % $cntCols === 0)
				{
					if ($cellIndex !== 0)
						$c .= "</tr>";
					$c .= "<tr>";
				}
				$t = Utils::es($pc['title']);

				$class = ($pid == $activeValue) ? 'active ': '';
				$class .= ' e10-param-btn hour';

				$c .= "<td data-value='$pid' data-title='$t' class='$class'><span class='padd5'>" . Utils::es($t) . '</span></td>';

				$cellIndex++;
			}
			$c .= "</tr>";
			$c .= '</table>';
			$c .= '</div>';

			$c .= '</div>';
		}
		else
		if (isset($p['list']) && $p['list'])
		{
			$c .= "<div class='e10-param-list e10-param' data-paramid='$paramId'>";
			if (isset ($p['title']))
				$c .= "<div class='title'>" . $this->app()->ui()->composeTextLine($p['title']) . '</div>';
			$c .= "<input name='$paramId' type='hidden' class='$inputClass' value='$activeValue'>";
			$c .= $this->createParamComboCode_treeCode(0, $activeValue, $p['values']);
			$c .= '</div>';
		}
		else
		{
			$c .= "<div class='btn-group e10-param' data-paramid='$paramId'>";
			$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>";

			if (isset ($p['title']))
				$c .= '<b>'.Utils::es ($p['title']).':</b> ';

			$c .=	"<span class='v'>".Utils::es($activeTitle).'</span>'.
						" <span class='caret'></span>".
						'</button>';
			$c .= "<input name='$paramId' type='hidden' class='$inputClass' value='$activeValue'>";
			$c .= "<ul class='dropdown-menu' role='menu'>";

			forEach ($p['values'] as $pid => $pc)
			{
				$t = Utils::es($pc['title']);
				$class = (strval($pid) === strval($activeValue)) ? " class='active'": '';
				$c .= "<li data-value='$pid' data-title='$t'$class><a href='#'>" . Utils::es($pc['title']) . '</a></li>';
			}

			$c .= '</ul></div> ';
		}
		return $c;
	}

	function createParamComboCode_treeCode($level, $activeValue, $items)
	{
		$c = "<ul class='level-{$level}'>";
		forEach ($items as $pid => $pc)
		{
			$class = ($pid == $activeValue) ? 'active ': '';
			$class .= ' title';

			$addParamsList = NULL;
			if (!$level && isset ($pc['title']['addParams']))
				$addParamsList = $pc['title']['addParams'];
			elseif (!$level && isset ($pc['title'][0]['addParams']))
				$addParamsList = $pc['title'][0]['addParams'];
			elseif ($level && isset($pc[0]['addParams']))
				$addParamsList = $pc[0]['addParams'];
			$addParams = '';

			if ($addParamsList)
			{
				$addParams = '';
				forEach ($addParamsList as $apCol => $apValue)
				{
					if ($addParams != '')
						$addParams .= '&';
					$addParams .= "__$apCol=$apValue";
				}
			}

			if (isset($pc['title'][0]['unselectable']) && $pc['title'][0]['unselectable'])
				$c .= '<li>';
			else
				$c .= "<li class='selectable'>";
			$c .= "<div data-value='$pid' data-addparams='$addParams' class='$class'>";
			if ($level)
				$c .= $this->app()->ui()->composeTextLine($pc);
			else
				$c .= $this->app()->ui()->composeTextLine($pc['title']);
			$c .= '</div>';
			if (isset($pc['title'][0]['subItems']))
				$c .= $this->createParamComboCode_treeCode($level + 1, $activeValue, $pc['title'][0]['subItems']);
			elseif (isset($pc[0]['subItems']))
				$c .= $this->createParamComboCode_treeCode($level + 1, $activeValue, $pc[0]['subItems']);
			$c .= '</li>';
		}
		$c .= '</ul>';

		return $c;
	}

	function createParamCheckboxesCode ($paramId)
	{
		$p = $this->params [$paramId];
		$c = '';
		$chgiid = str_replace('.', '-', 'prm'.$paramId).'-'.mt_rand(10000, 999999999);

		$c .= "<div class='cbxs'>";
		if (isset($p['title']))
			$c .= "<h3>".$this->app()->ui()->composeTextLine($p['title']).'</h3>';
		forEach ($p['values'] as $pid => $pc)
		{
			$inputName = $paramId.'.'.$pid;
			$inputId = $chgiid.'-'.$pid;
			$css = '';
			$checked = '';
			if (isset($pc['css']))
				$css = "style='{$pc['css']}'";
			if (isset($pc['value']) && $pc['value'] !== '0')
				$checked = " checked='checked'";

			$c .= "<span class='cbx label label-default'$css><input type='checkbox' name='$inputName' id='$inputId'$checked> <label for='$inputId'>".$this->app()->ui()->composeTextLine($pc['title'])."</label></span> ";
		}
		$c .= '</div>';

		return $c;
	}

	function createParamDateCode ($paramId)
	{
		$p = $this->params [$paramId];
		$inputClass = $this->paramClass($paramId, 'e10-inputDate');

		$value = '';
		if (isset($p['value']) && $p['value'] != '')
			$value = Utils::es($p['value']);

		$c = '';

		$c .= "<span style='display: inline-block; border-radius: 4px; padding: 4px; margin-right: 1ex; border: 1px solid rgba(0,0,0,.35); background-color: whitesmoke;'>";
		if (isset($p['title']))
			$c .= "<b>".$this->app()->ui()->composeTextLine($p['title']).': </b>';

		$pch = '';//Utils::es($p['title']);
		if (1)
		{
			$c .= "<input type='text' name='$paramId' class='$inputClass' placeholder='$pch' value='$value'> ";
			$c .= "<script>$('input.e10-inputDate').datepicker ({duration: 50});</script>";
		}
		else
		{
			$c .= "<input type='text' name='$paramId' class='$inputClass' placeholder='$pch'> ";
			$c .= "<script>$('input.e10-inputDate').datepicker ({duration: 50});</script>";
		}

		$c .= '</span>';

		return $c;
	}

	function createParamStringCode ($paramId)
	{
		$p = $this->params [$paramId];
		$inputClass = $this->paramClass($paramId, 'e10-inputString');

		$pch = Utils::es($p['title']);
		$c = '';

		if (isset($p['groupTitle']))
			$c .= "<h3>".$this->app()->ui()->composeTextLine($p['groupTitle']).'</h3>';
		$c .= "<input type='text' class='$inputClass' name='$paramId' placeholder='$pch'";
		if (isset($p['value']) && $p['value'] != '')
			$c .= " value=\"".Utils::es($p['value'])."\"";
		$c .= '> ';

		return $c;
	}

	function createParamHiddenCode ($paramId)
	{
		$p = $this->params [$paramId];
		$c = '';
		$c .= "<input type='hidden' name='$paramId'>";

		return $c;
	}

	function createParamFloatCode ($paramId)
	{
		$p = $this->params [$paramId];
		$inputClass = $this->paramClass($paramId, 'e10-inputFloat');

		$pch = Utils::es($p['title']);
		$c = '';
		$c .= "<input type='text' class='$inputClass' name='$paramId' placeholder='$pch'> ";

		return $c;
	}

	public function detectValues ()
	{
		forEach ($this->params as $paramId => &$paramContent)
		{
			if ($paramContent['type'] === 'checkboxes')
			{
				forEach ($paramContent['values'] as $pid => &$pc)
				{
					$inputId = str_replace('.', '_', $paramId).'_'.$pid;

					$dv = '0';
					if (isset ($paramContent['options']['defaultChecked']) && in_array($pid, $paramContent['options']['defaultChecked']))
						$dv = '1';
					$pv = UIUtils::detectParamValue($inputId, $dv);
					$pc['value'] = $pv;
				}
			}
			else
				$this->detectParamValue($paramId);
		}
		return $this->params;
	}

	public function detectParamValue ($paramId)
	{
		if ($this->externalParamsValues !== FALSE && isset($this->externalParamsValues[$paramId]))
			$pv = $this->externalParamsValues[$paramId];
		else
			$pv = UIUtils::detectParamValue($paramId, '');

		if ($pv == '' || (isset ($this->params [$paramId]['values']) && !isset($this->params [$paramId]['values'][$pv])))
			$pv = isset($this->params [$paramId]['defaultValue']) ? $this->params [$paramId]['defaultValue'] : '';
		$this->params [$paramId]['value'] = $pv;

		$this->params [$paramId]['activeTitle'] = isset ($this->params [$paramId]['values'][$pv]['title']) ? $this->params [$paramId]['values'][$pv]['title'] : '';

		return $pv;
	}

	public function hasFlag ($p, $flag)
	{
		if (isset ($p['options']['flags']) && in_array($flag, $p['options']['flags']))
			return TRUE;

		return FALSE;
	}

	public function getParams() {return $this->params;}

	function createCalendarMonthParam (&$p)
	{
		if ($this->hasFlag ($p, 'enableAll'))
			$p['values']['0'] = ['title' => 'Vše', 'calendarYear' => 0, 'calendarMonth' => 0, 'dateBegin' => '', 'dateEnd' => ''];
		if (isset($p['options']['years']))
			$years = $p['options']['years'];
		else
			$years = Utils::calendarMonths($this->app);
		foreach ($years as $year)
		{
			$p['values']['Y'.$year] = ['title' => $year, 'calendarYear' => 'Y'.$year, 'calendarMonth' => 0];
			for ($month = 1; $month < 13; $month++)
			{
				$startDateStr = sprintf ('%04d-%02d-01', $year, $month);
				$startDate = new \DateTime ($startDateStr);
				$endDateStr = $startDate->format ('Y-m-t');

				$id = $year.$month;
				$p['values'][$id] = ['title' => $year.' / ' . $month, 'calendarYear' => $year, 'calendarMonth' => strval($month),
														 'dateBegin' => $startDateStr, 'dateEnd' => $endDateStr];
			}

			if ($this->hasFlag ($p, 'quarters'))
			{
				for ($q = 1; $q < 5; $q++)
				{
					$startDateStr = sprintf ('%04d-%02d-01', $year, ($q - 1) * 3 + 1);

					$tmpDateStr = sprintf ('%04d-%02d-01', $year, ($q - 1) * 3 + 1 + 2);
					$endDate = new \DateTime ($tmpDateStr);
					$endDateStr = $endDate->format ('Y-m-t');

					$id = $year.'Q'.$q;
					$p['values'][$id] = ['title' => $year.' / ' . $q.'Q', 'calendarYear' => $year, 'calendarMonth' => 'Q'.$q,
															 'dateBegin' => $startDateStr, 'dateEnd' => $endDateStr];
				}
			}

			if ($this->hasFlag ($p, 'halfs'))
			{
				$p['values'][$year.'H1'] = ['title' => $year.' / ' . '1|2', 'calendarYear' => $year, 'calendarMonth' => 'H1',
																		'dateBegin' => "$year-01-01", 'dateEnd' => "$year-06-30"];
				$p['values'][$year.'H2'] = ['title' => $year.' / ' . '2|2', 'calendarYear' => $year, 'calendarMonth' => 'H2',
																		'dateBegin' => "$year-07-01", 'dateEnd' => "$year-12-31"];
			}
		}

		if (isset($p['options']['defaultValue']))
		{
			$p['defaultValue'] = $p['options']['defaultValue'];
		}
		else
		{
			if ($this->hasFlag ($p, 'enableAll'))
				$p['defaultValue'] = '0';
			else
			{
				$today = new \DateTime();
				$p['defaultValue'] = $today->format('Yn');
			}
		}
		$p['value'] = $p['defaultValue'];
	}

	function createCalendarMonthParamCode ($paramId)
	{
		$p = $this->params [$paramId];
		$activeValue = $p['value'];
		$activeTitle = $p['values'][$activeValue]['title'];

		$cntCols = 13;
		if ($this->hasFlag ($p, 'quarters'))
			$cntCols += 4;
		if ($this->hasFlag ($p, 'halfs'))
			$cntCols += 2;

		$inputClass = ($this->inputClass === '') ? '' : " class='$this->inputClass'";
		$c = '';
		$c .= "<div class='btn-group e10-param' data-paramid='$paramId'>";
		$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>".
			'<b>'.Utils::es ($p['title']).":</b> <span class='v'>".Utils::es($activeTitle).'</span>'.
			" <span class='caret'></span>".
			'</button>';
		$c .= "<input name='$paramId' type='hidden'$inputClass value='$activeValue'>";
		$c .= "<div class='dropdown-menu' role='menu'>";

		$c .= "<table class='e10-param-calper'>";

		if ($this->hasFlag ($p, 'enableAll'))
		{
			$class = 'all';
			if ($activeValue === 0) $class .= ' active';
			$c .= "<tr class='all'><td colspan='$cntCols' data-value='0' data-title='Vše' class='$class'><a href='#'>Vše</a></td></tr>";
		}

		if (isset($p['options']['years']))
			$years = $p['options']['years'];
		else
			$years = Utils::calendarMonths($this->app);
		foreach ($years as $year)
		{
			$c .= "<tr>";
			if ($this->hasFlag ($p, 'years'))
			{
				$mid = 'Y'.$year;
				$class = ($mid === $activeValue) ? " class='active y'": "class='y'";
				$t = Utils::es($year);
				$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>".$year. '</a></td>';
			}
			else
				$c .= "<td class='x y'>".$year.'</td>';

			for ($month = 1; $month < 13; $month++)
			{
				$mid = $year.$month;
				$class = (strval($mid) == $activeValue) ? " class='active m'": "class='m'";
				$t = $year.' / '.$month;
				$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>".$month.'</a></td>';
			}


			if ($this->hasFlag ($p, 'quarters'))
			{
				$quarters = ['Q1' => '1Q', 'Q2' => '2Q', 'Q3' => '3Q', 'Q4' => '4Q'];
				forEach ($quarters as $qId => $qName)
				{
					$class = ($year.$qId == $activeValue) ? " class='active q'": "class='q'";
					$t = Utils::es ($year.' / '.$qName);
					$c .= "<td data-value='{$year}$qId' data-title='$t'$class><a href='#'>" . Utils::es($qName) . '</a></td>';
				}
			}

			if ($this->hasFlag ($p, 'halfs'))
			{
				$class = ($year.'H1' == $activeValue) ? " class='active h'": "class='h'";
				$c .= "<td data-value='{$year}H1' data-title='$year - 1|2'$class><a href='#'>" . '1|2' . '</a></td>';
				$class = ($year.'H2' == $activeValue) ? " class='active h'": "class='h'";
				$c .= "<td data-value='{$year}H2' data-title='$year - 2|2'$class><a href='#'>" . '2|2' . '</a></td>';
			}

			$c .= '</tr>';
		}
		$c .= '</table>';
		$c .= '</div></div> ';

		return $c;
	}

	protected function paramClass ($paramId, $inputClass = '')
	{
		$p = $this->params [$paramId];

		$classes = [];
		if ($inputClass !== '')
			$classes[] = $inputClass;

		if ($this->inputClass !== '')
			$classes[] = $this->inputClass;

		if (isset ($p['options']['colWidth']))
			$classes[] = 'e10-gl-col'.intval ($p['options']['colWidth']);

		return implode (' ', $classes);
	}
}

