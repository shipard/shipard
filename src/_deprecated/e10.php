<?php

namespace e10;

/**
 * @deprecated
 */
class Application extends \Shipard\Application\Application {}
class AppLog extends \Shipard\Application\AppLog {}
class CfgManager extends \Shipard\Base\CfgManager {}
class ContentRenderer extends \Shipard\UI\Core\ContentRenderer {}
class DataModel extends \Shipard\Application\DataModel {}
class DbTable extends \Shipard\Table\DbTable {}
class DocumentAction extends \Shipard\Base\DocumentAction{}
class DocumentCard extends \Shipard\Base\DocumentCard {}
class E10ApiObject extends \Shipard\Base\ApiObject{}
class E10Object extends \Shipard\Base\BaseObject{}
class FormReport extends \Shipard\Report\FormReport {}
class GlobalReport extends \Shipard\Report\GlobalReport {}
class Params extends \Shipard\UI\Core\Params {}
class Response extends \Shipard\Application\Response {}
class TableForm extends \Shipard\Form\TableForm {}
class TableView extends \Shipard\Viewer\TableView {}
class TableViewDetail extends \Shipard\Viewer\TableViewDetail{}
class TableViewGrid extends \Shipard\Viewer\TableViewGrid {}
class TableViewPanel extends \Shipard\Viewer\TableViewPanel {}
class TableViewWidget extends \Shipard\Viewer\TableViewWidget {}
class uiutils extends \Shipard\UI\Core\UIUtils {}
class Utility extends \Shipard\Base\Utility {}
class utils extends \Shipard\Utils\Utils{}
class Widget extends \Shipard\UI\Core\Widget {}
class widgetDashboard extends \Shipard\UI\Core\WidgetDashboard {}
class Wizard extends \Shipard\Form\Wizard {}
class world extends \Shipard\Utils\World {}
class str extends \Shipard\Utils\Str {}
class json extends \Shipard\Utils\Json {}
class TemplateCore extends \Shipard\Utils\TemplateCore {}
class widgetReports extends \Shipard\Report\WidgetReports {}

/**
 * @deprecated
 */
interface IDocumentList
{
	function init ();
	function loadData ();
	function saveData ($listData);
	function setRecord ($listId, \Shipard\Form\TableForm $formData);
	function setRecData ($table, $listId, $recData);
	function createHtmlCode ($options = 0);
}

/**
 * Summary of e10\renderTableFromArray
 * @param mixed $rows
 * @param mixed $columns
 * @param mixed $params
 * @param mixed $app
 * @return string
 * @deprecated
 */

 function renderTableFromArray ($rows, $columns, $params = [], $app = NULL)
{
	$tr = new \Shipard\Utils\TableRenderer($rows, $columns, $params, $app);
	return $tr->render();
}


/**
 * NF - number format
 *
 * @access	public
 * @param	double
 * @return	string	formátované číslo
 * @deprecated
 */
function nf($number, $decimals=0)
{
	return number_format(round($number, $decimals), $decimals, ',', ' ');
}

/**
 * @deprecated
 */
function df ($d, $format = '%D, %T')
{
	return utils::datef ($d, $format);
}


/**
 * @deprecated
 */
function es ($s)
{
	return htmlspecialchars ($s);
}


/**
 * @deprecated
 */
function searchArray ($array, $searchBy, $searchWhat)
{
	forEach ($array as &$item)
	{
		if (isset ($item [$searchBy]) && $item [$searchBy] == $searchWhat)
			return $item;
	}

	return NULL;
} // searchArray

/**
 * @deprecated
 */
function sortByOneKey(array $array, $key, $dict = false, $asc = true, $requierdKey = FALSE)
{
    $result = array();

    $values = array();
    foreach ($array as $id => $value)
		{
			if ($requierdKey && !$value[$requierdKey])
				continue;
			$values[$id] = isset($value[$key]) ? $value[$key] : 0;
    }

    if ($asc) {
        asort($values);
    }
    else {
        arsort($values);
    }

		if ($dict)
			foreach ($values as $key => $value)
				$result[$key] = $array[$key];
		else
			foreach ($values as $key => $value)
				$result[] = $array[$key];

    return $result;
}

/**
 * @deprecated
 */

function searchParam ($params, $key, $defaultValue)
{
	if (isset ($params[$key]))
	{
		if (is_int ($defaultValue))
			return intval ($params[$key]);
		return $params[$key];
	}
	return $defaultValue;
}

/**
 * @deprecated version
 * @param mixed $app
 * @return null
 */
function getImage ($app)
{
	$resizer = new \Shipard\Base\ImageResizer ($app);
	$resizer->run ();

	return NULL;
}








function createDetailResponse ($app, \Shipard\Viewer\TableViewDetail &$data)
{
	$data->doIt ();
	$app->response->add ("objectType", "detail");
	$app->response->add ("object", $data->objectData);
	return $app->response;
}

function createViewerPanelResponse ($app, \Shipard\Viewer\TableViewPanel $panel)
{
	$panel->doIt ();
	$app->response->add ("objectType", "viewerpanel");
	$app->response->add ("object", $panel->objectData);
	return $app->response;
}



function createFormResponse ($app, \Shipard\Form\TableForm $data, $format)
{
	$app->response->add ("mainCode", $data->finalCode ());
	$app->response->add ("htmlHeader", $data->createHeaderCode ());
	$app->response->add ("buttonsCode", $data->createToolbarCode ());
	$app->response->add ("sidebarCode", $data->sidebar);
	if ($data->infoPanel !== NULL)
		$app->response->add ("infoPanelCode", $data->infoPanel);

	$app->response->add ("htmlCodeTopMenuSearch", $data->htmlCodeTopMenuSearch);
	//$r->add ("htmlCodeToolbarViewer", $data->createToolbarCode ());

	$app->response->add ("recData", $data->recData);

	$scd = $data->table->createSubColumnsData ($data->recData);
	if ($scd !== FALSE)
		$app->response->add ('subColumns', $scd);

	$app->response->add ("lists", $data->lists);
	$app->response->add ("infoItems", $data->infoItems);
	$app->response->add ("flags", $data->flags);
	$app->response->add ("documentPhase", $data->documentPhase);
	if (isset ($data->saveResult) && count($data->saveResult))
		$app->response->add ("saveResult", $data->saveResult);
	return $app->response;
}

function createWizardResponse ($app, \Shipard\Form\Wizard $data)
{
	$app->response->add ("mainCode", $data->finalCode ());
	$app->response->add ("htmlHeader", $data->createHeaderCode ());
	$app->response->add ("buttonsCode", $data->createToolbarCode ());
	$app->response->add ("sidebarCode", $data->sidebar);

	$app->response->add ("htmlCodeTopMenuSearch", $data->htmlCodeTopMenuSearch);

	$app->response->add ("recData", $data->recData);
	$app->response->add ("lists", $data->lists);
	$app->response->add ("infoItems", $data->infoItems);
	$app->response->add ("flags", $data->flags);
	$app->response->add ("stepResult", $data->stepResult);

	return $app->response;
}

function createWindowResponse ($app, \Shipard\Form\Window $data)
{
	$app->response->add ("mainCode", $data->finalCode ());
	$app->response->add ("htmlHeader", $data->createHeaderCode ());
	$app->response->add ("buttonsCode", $data->createToolbarCode ());
	$app->response->add ("sidebarCode", $data->sidebar);

	$app->response->add ("htmlCodeTopMenuSearch", $data->htmlCodeTopMenuSearch);

	$app->response->add ("recData", $data->recData);
	$app->response->add ("lists", $data->lists);
	$app->response->add ("infoItems", $data->infoItems);
	$app->response->add ("flags", $data->flags);
	$app->response->add ("stepResult", $data->stepResult);

	return $app->response;
}

function createListResponse ($app, \Shipard\Base\ListData &$data)
{
	$app->response->add ("objectType", "list");
	$app->response->add ("object", $data->objectData);
	return $app->response;
}

function createReportResponse ($app, \Shipard\Report\Report $data)
{
	if ($data->format == 'widget')
	{
/*		if (0)
		{
			$this->objectData ['dataContent'] = $data->content;
		}
		else*/
		{
			$data->objectData ['htmlCodeToolbarViewer'] = $data->createToolbarCode ();
			$data->objectData ['htmlCodeReportPanel'] = $data->createPanelCode ();
			$data->objectData ['htmlCodeDetails'] = $data->createTabsCode ();
		}

		$app->response->add ("objectType", "widget");
		$app->response->add ("object", $data->objectData);
	}
	else
	{
		$print = $app->testGetParam ('print');
		if ($print !== '')
		{
			$printer = $app->testGetParam('printer');
			$printCfg = [];
			$data->printReport($printCfg, 1, $printer);
		}
		else
		{
			$app->response->setFile ($data->fullFileName, $data->mimeType, $data->saveFileName, ($data->saveAs !== FALSE) ? 'attachment' : 'inline');
		}
	}
	return $app->response;
}

function createViewerResponse ($app, \Shipard\Viewer\TableView &$data, $format, $fullCode)
{
	$app->response->setMimeType('application/json');
	$data->objectData ['id'] = $data->vid;

	if ($fullCode == "")
	{
		unset ($data->objectData ['dataItems']);
		$data->addChangedPanels ();
	}
	else
	{
		$data->objectData ['mainCode'] = $data->createViewerCode ($format, $fullCode);
		$data->objectData ['htmlCodeTopMenuSearch'] = '';
		$data->objectData ['htmlCodeToolbarViewer'] = $data->createToolbarCode ();
		$data->objectData ['htmlCodeToolbarTools'] = $data->createViewerToolsCode ();
		$data->objectData ['htmlCodeDetails'] = $data->createDetailsCode ();

		if ($data->mode !== 'panes')
			unset ($data->objectData ['htmlItems']);

		unset ($data->objectData ['dataItems']);
	}

	$flowParams = $data->flowParams();
	if ($flowParams)
		$data->objectData ['flowParams'] = base64_encode(json_encode($flowParams));

	$app->response->add ("objectType", "viewer");
	$app->response->add ("object", $data->objectData);
	return $app->response;
}

function createWidgetResponse ($app, $data)
{
	$data->objectData ['fullCode'] = intval($app->testGetParam('fullCode'));
	$data->objectData ['mainCode'] = $data->createMainCode ();

/*	if (0)
	{
		unset ($data->objectData ['mainCode']);
	}
	else*/
	{
		unset ($data->objectData ['widgetData']);
		$data->objectData [/*'htmlCodeTopMenuSearch'*/'htmlCodeToolbarViewer'] = $data->createToolbarCode ();
		$data->objectData ['htmlCodeDetails'] = $data->createTabsCode ();
	}

	if ($data->forceFullCode)
		$data->objectData ['fullCode'] = 1;

	$app->response->add ("object", $data->objectData);
	$app->response->add ("objectType", "widget");
	return $app->response;
}


function trDict ($app, $params)
{
	$idName = \E10\searchParam($params, 'id', '');
	if ($idName === '')
		return '#ERR_MISSING_ID';

	$idParts = explode('.', $idName);
	$textId = array_pop($idParts);
	$className = "\\translation\\dicts\\".implode("\\", $idParts);
	$func = $className.'::text';
	$fullTextId = $className.'::'.$textId;
	if (defined($fullTextId))
	{
		$rcc = new \ReflectionClassConstant($className, $textId);
		$textIdValue = $rcc->getValue();
		$txt = $func($textIdValue);

		return $txt;
	}

	$txt = '#ERR_WRONG_ID';

	return $txt;
}

function thisWorkplace ($app, $params)
{
	unset ($params ['owner']->data['thisWorkplace']);

	$workplace = FALSE;
	$deviceId = $app->testCookie ('e10-deviceId');

	if ($deviceId != '')
		$workplace = $app->searchWorkplace ($deviceId);

	if (!$workplace)
		return '';

	$params ['owner']->data['thisWorkplace'] = $workplace;
	$params ['owner']->data['thisDeviceId'] = $deviceId;

	$searchUsers = \E10\searchParam ($params, 'searchUsers', 0);
	if (!$searchUsers || !isset($workplace['users']) || !count($workplace['users']))
		return '';

	$q[] = 'SELECT persons.fullName, persons.firstName, persons.lastName, persons.login FROM e10_persons_persons AS persons';
	array_push ($q, ' WHERE persons.ndx IN %in', $workplace['users']);
	array_push ($q, ' ORDER BY persons.fullName', " LIMIT 0, 10");

	$rows = $app->db()->query ($q);
	forEach ($rows as $r)
	{
		$params ['owner']->data['thisWorkplace']['loginUsersList'][] = $r->toArray();
	}

	if (isset($params ['owner']->data['thisWorkplace']['loginUsersList']) && count($params ['owner']->data['thisWorkplace']['loginUsersList']))
		$params ['owner']->data['thisWorkplace']['loginUsers'] = 1;

	return '';
}

