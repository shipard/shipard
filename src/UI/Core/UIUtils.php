<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;
use \Shipard\Form\TableForm;

class UIUtils
{
	static function createReportContentHeader ($app, $info)
	{
		if (count ($info) === 0)
			return '';

		$printTimeStamp = new \DateTime();
		$ownerName = $app->cfgItem ('options.core.ownerShortName');

		if (isset($nfo['icontxt']))
			$icon = $info['icontxt'];
		else
		{
			$icon = $app->ui()->icon($info['icon'] ?? 'system/iconFile');
		}

		$c = "<div class='e10-reportHeader'>";
		$c .= "<span class='info'>";
		$c .= "<span class='title'>".$icon.' '.utils::es($info['title']).'</span><br/>';
		if (isset($info['param']))
		{
			foreach ($info['param'] as $paramName => $paramValue)
				$c .= "<span><b>" . utils::es($paramName) . ':</b> ' . utils::es($paramValue) . '</span> ';
		}
		$c .= '</span>';

		$c .= "<span class='owner'>";
		$c .= "<span>".$app->ui()->icon('system/iconOwner').' '.utils::es($ownerName).'</span><br/>';
		$c .= "<span>".$app->ui()->icon('system/iconUser').' '.utils::es($app->user()->data ('name')).'</span><br/>';
		$c .= "<span>".$app->ui()->icon('system/actionPrint').' '.utils::es(utils::datef($printTimeStamp, '%d, %T')).'</span>';
		$c .= '</span>';
		$c .= '</div>';

		return $c;
	}

	static function detectParamValue ($paramId, $defaultValue = '')
	{
		$pv = $defaultValue;
		if (isset ($_GET [$paramId]))
			$pv = $_GET [$paramId];
		else if (isset ($_POST [$paramId]))
			$pv = $_POST [$paramId];

		return $pv;
	}

	static function renderContentPart ($app, $cp)
	{
		$cr = new ContentRenderer($app);
		$cr->content = [$cp];
		return $cr->createCode();
	}

	static function renderSubColumns ($app, $data, $sci, $returnData = FALSE)
	{
		$content = [];
		if (isset ($sci['groups']))
		{
			$t = [];
			$h = ['txt' => 'Název', 'val' => ' Hodnota'];
			foreach ($sci['groups'] as $group)
			{
				if (isset($group['title']))
				{
					$t[] = ['txt' => $group['title'],
						'_options' => [
							'colSpan' => ['txt' => 2], 'class' => 'subheader'
						]
					];
				}
				foreach ($sci['columns'] as $col)
				{
					if (!isset($col['group']) || $col['group'] !== $group['id'])
						continue;

					if (uiutils::subColumnEnabled ($col, $data) === FALSE)
						continue;

					$t[] = ['txt' => $col['name'], 'val' => $app->subColumnValue ($col, $data[$col['id']] ?? '')];
				}
			}
			$params = ['hideHeader' => 1];
			$content[] = ['type' => 'table', 'table' => $t, 'header' => $h, 'params' => $params];
		}
		else
		if (isset ($sci['layout']))
		{
			foreach ($sci['layout'] as $layoutItem)
			{
				if (isset($layoutItem['columns']))
				{
					$t = [];
					$h = ['txt' => 'Název', 'val' => ' Hodnota'];
					foreach ($layoutItem['columns'] as $subColumnId)
					{
						$col = utils::searchArray($sci['columns'], 'id', $subColumnId);
						$t[] = ['txt' => $col['name'], 'val' => $app->subColumnValue ($col, $data[$subColumnId])];
					}
					$content[] = ['type' => 'table', 'table' => $t, 'header' => $h];
				}
				elseif (isset($layoutItem['table']))
				{
					$params = ['subColumnInfo' => $sci, 'subColumnData' => $data];
					if (isset($layoutItem['table']['params']))
						$params = array_merge($params, $layoutItem['table']['params']);
					if (isset($layoutItem['table']['header']))
						$params['header'] = $layoutItem['table']['header'];
					if (!isset($layoutItem['table']['params']) || !isset($layoutItem['table']['params']['tableClass']))
						$params['tableClass'] = 'e10-dataSet-table';

					$cp = [
						'type' => 'table', 'table' => $layoutItem['table']['rows'], 'header' => $layoutItem['table']['cols'],
						'params' => $params
					];

					if (isset($layoutItem['table']['title']))
					{
						$cp['title'] = $layoutItem['table']['title'];
					}

					$content[] = $cp;
				}
				elseif (isset($layoutItem['recordSetTable']))
				{
					$params = [];
					if (isset($layoutItem['recordSetTable']['params']))
						$params = array_merge($params, $layoutItem['recordSetTable']['params']);
					if (isset($layoutItem['recordSetTable']['header']))
						$params['header'] = $layoutItem['recordSetTable']['header'];
					if (!isset($layoutItem['recordSetTable']['params']) || !isset($layoutItem['recordSetTable']['params']['tableClass']))
						$params['tableClass'] = 'e10-dataSet-table';

					$mainCol = utils::searchArray($sci['columns'], 'id', $layoutItem['recordSetTable']['recordSetColumn']);
					$rows = [];
					foreach ($data[$layoutItem['recordSetTable']['recordSetColumn']] as $rowId => $row)
					{
						$nr = [];
						foreach ($row as $key => $value)
						{
							$col = utils::searchArray($mainCol['columns'], 'id', $key);
							$nr[$key] = $app->subColumnValue ($col, $value);
						}
						$rows[$rowId] = $nr;
					}

					$cp = [
						'type' => 'table', 'table' => $rows, 'header' => $layoutItem['recordSetTable']['cols'],
						'params' => $params
					];

					if (isset($layoutItem['recordSetTable']['title']))
					{
						if (is_array($layoutItem['recordSetTable']['title']))
							$cp['title'] = $layoutItem['recordSetTable']['title'];
						else
							$cp['title'] = ['text' => $layoutItem['recordSetTable']['title'], 'class' => 'e10-bold'];
					}

					$content[] = $cp;
				}
			}
		}
		else
		{
			$t = [];
			$h = ['txt' => 'Název', 'val' => ' Hodnota'];
			foreach ($sci['columns'] as $col)
			{
				if (isset($data[$col['id']]))
					$t[] = ['txt' => $col['name'], 'val' => $app->subColumnValue ($col, $data[$col['id']])];
			}
			$content[] = ['type' => 'table', 'table' => $t, 'header' => $h];
		}

		if ($returnData)
		{
			return $content;
		}

		$cr = new ContentRenderer($app);
		$cr->content = $content;
		$code = $cr->createCode();
		return $code;
	}

	static function subColumnEnabled ($col, $data)
	{
		if (isset($col['enabled']))
		{
			foreach ($col['enabled'] as $key => $value)
			{
				$dataValue = (isset($data[$key])) ? $data[$key] : NULL;
				if ($dataValue === NULL || (!is_array($value) && $dataValue != $value) || (is_array($value) && !in_array($dataValue, $value)))
					return FALSE;
			}
		}

		if (isset($col['disabled']))
		{
			foreach ($col['disabled'] as $key => $value)
			{
				$dataValue = (isset($data[$key])) ? $data[$key] : NULL;
				if ($dataValue === NULL || (!is_array($value) && $dataValue == $value) || (is_array($value) && in_array($dataValue, $value)))
					return FALSE;
			}
		}

		$tco = 0;
		if (isset($col['readOnly']))
		{
			if (is_numeric($col['readOnly']) && intval($col['readOnly']) === 1)
				$tco |= TableForm::coReadOnly;
			elseif (is_array($col['readOnly']))
			{
				foreach ($col['readOnly'] as $key => $value)
				{
					$dataValue = (isset($data[$key])) ? $data[$key] : NULL;
					if ((!is_array($value) && $dataValue == $value) || (is_array($value) && in_array($dataValue, $value)))
						return 1;
				}
			}
		}

		if (isset($col['coReadOnly']) && intval($col['coReadOnly']))
			$tco |= TableForm::coReadOnly;
		if (isset($col['coHidden']) && intval($col['coHidden']))
			$tco |= TableForm::coHidden;
		if (isset($col['coDisabled']) && intval($col['coDisabled']))
			$tco |= TableForm::coDisabled;

		if (!$tco)
			return TRUE;

		return $tco;
	}

	static function subColumnInputParams ($col, $data)
	{
		$params = [];

		if (isset($col['defaultValue']))
			$params['value'] = $col['defaultValue'];

		return $params;
	}

	static function addScanToDocumentInputCode ($tableId, $recId)
	{
		$widgetId = 'scanToDoc_'.mt_rand(100000, 9999999);
		$c = '';
		$c .= "<div id='$widgetId' class='e10-scanToDocument e10-widget-pane' data-table='{$tableId}' data-pk='{$recId}' data-widget-class='lib.ui.ScanToDocumentWidget'>";
		$c .= "<div>".'čeká se na skener...'.'</div>';
		$c .= '</div>';

		$c .= "<script>e10ScanToDocumentReload ('$widgetId', 'init');</script>";

		return $c;
	}

}

