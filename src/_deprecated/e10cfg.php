<?php


namespace E10;

use \Shipard\Viewer\TableViewPanel;
use \Shipard\Form\TableForm;
use \Shipard\Form\FormSidebar;
use \Shipard\Utils\Json;



function wcardtest ($pattern, $string)
{
	return preg_match ("/^".strtr($pattern, array('*'=>"[a-zA-Z0-9\\-]*", '.' => '\\.'))."$/", $string);
}

function createCheckTranslateHints($nodePath)
{
	return function ($test) use ($nodePath) {return wcardtest ($test['mask'], $nodePath);};
}

function http_post ($url, $data)
{
	$data_len = strlen ($data);

	return array ('content'=>file_get_contents ($url, false, stream_context_create (array ('http'=>array ('method'=>'POST'
					, 'header'=>"Content-type: text/plain\r\nConnection: close\r\nContent-Length: $data_len\r\n"
					, 'content'=>$data, 'timeout' => 1
					))))
			, 'headers'=>$http_response_header
			);
}


function getRelativePath($from, $to)
{
    $from     = explode('/', $from);
    $to       = explode('/', $to);
    $relPath  = $to;

    foreach($from as $depth => $dir) {
        // find first non-matching dir
        if($dir === $to[$depth]) {
            // ignore this directory
            array_shift($relPath);
        } else {
            // get number of remaining dirs to $from
            $remaining = count($from) - $depth;
            if($remaining > 1) {
                // add traversals up to first matching dir
                $padLength = (count($relPath) + $remaining - 1) * -1;
                $relPath = array_pad($relPath, $padLength, '..');
                break;
            } else {
                $relPath[0] = './' . $relPath[0];
            }
        }
    }
    return implode('/', $relPath);
}


/**
 * Class CfgItemTable
 * @package E10
 */

/*
class CfgItemTable extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('_CfgItemTable', '_e10_cfg_item', 'Polžky nastavení');
	}

	public function getTableView ($viewId, $queryParams = NULL)
	{
		$v = new CfgItemViewer ($this, $viewId);
		$parts = explode (':', $viewId);
		$v->setCfgItem($parts[0], $parts[1]);
		$v->init ();
		$v->selectRows ();
		return $v;
	}

	public function loadDocsList ($recs)
	{
		return [];
	}
}
*/

/**
 * Class CfgItemViewer
 * @package E10
 */

/*
class CfgItemViewer extends TableView
{
	var $cfgItemPath;
	var $cfgItemTextKey;
	var $cfgItem;

	public function createToolbar()
	{
		return [];
	}

	public function createDetails()
	{
		return [];
	}

	public function setCfgItem ($cfgItemPath, $cfgItemTextKey)
	{
		$this->cfgItemPath = $cfgItemPath;
		$this->cfgItemTextKey = $cfgItemTextKey;
		$this->cfgItem = $this->app()->cfgItem ($cfgItemPath);
	}

	public function selectRows ()
	{
		$this->rowsPageSize = 500;
		$this->queryRows = [];
		$this->ok = 1;

		if ($this->rowsFirst > 0)
			return;

		$fts = $this->fullTextSearch();

		forEach ($this->cfgItem as $id => $c)
		{
			if ($fts != '' )
			{
				$nd = strtr($c [$this->cfgItemTextKey], utils::$transDiacritic);
				if (mb_stristr($c [$this->cfgItemTextKey], $fts, FALSE, 'UTF-8') === FALSE && mb_stristr($nd, $fts, FALSE, 'UTF-8') === FALSE)
					continue;
			}
			$this->queryRows [] = $c;
		}
	}

	public function renderRow ($item)
	{
		$listItem ['icon'] = 'icon-cog';
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item [$this->cfgItemTextKey];
		$listItem ['t2'] = '';

		return $listItem;
	}
}
*/

/*
 *  TblAppOptions
 */

class TblAppOptions extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("_TblAppOptions", "_e10_app_options", "Nastavení aplikace");
	}


	public function appOptionFileName ($ndx, $option)
	{
		if (isset ($option ['type']) && $option ['type'] == 'yamlBlob')
			$fileExt = 'yaml';
		else
			$fileExt = 'json';
		return __APP_DIR__ . "/config/appOptions.$ndx.$fileExt";
	}

	public function getDetailData ($viewId, $detailId, $pk)
	{
		$detailData = new ViewDetailAppOptions ($this, $viewId, $detailId);
		$detailData->item = $this->loadItem ($pk);
		$detailData->ok = 1;
		return $detailData;
	}

	public function getTableForm ($formOp, $pkParam, $columnValues = NULL)
	{
		$pk = $pkParam;
		$recData = array();
		switch ($formOp)
		{
			case 'new':
						$this->checkNewRec ($recData);
						break;
			case 'edit':
			case 'sidebar':
						if ($pk != "")
							$recData = $this->loadItem ($pk);
						break;
			case 'save':
						$data = $this->app()->testGetData();
						$saveData = json_decode ($data, TRUE);
						$recData = $saveData ['recData'];
						break;
		}

		$formId = $this->formId ($recData);

		$f = new FormAppOptions ($this, $formId, $formOp);

		$f->recData = $recData;
		if ($pk != "")
		{
			$f->documentPhase = "update";
		}
		return $f;
	}

	public function getTableView ($viewId, $queryParams = NULL, $requestParams = NULL)
	{
		$v = new ViewAppOptions ($this, $viewId);
		$v->init ();
		$v->selectRows ();
		return $v;
	}

	public function loadItem ($ndx, $table = NULL)
	{
		$appOptions = $this->app()->appOptions();
		$option = $appOptions [$ndx];

		$item = array ();
		$item ['ndx'] = $ndx;
		$item ['option'] = $option;
		if ($option ['type'] === 'viewer')
			return $item;

		$text = '';
		$fileName = $this->appOptionFileName($ndx, $option);
		if (is_file($fileName))
			$text = file_get_contents ($fileName);

		if ($option ['type'] === 'yamlBlob')
			$item ['yamlBlob'] = $text;
		else
		{
			$loadedItem = json_decode ($text, TRUE);

			forEach ($option ['options'] as $o)
			{
				$k = $o['cfgKey'];
				if (isset ($loadedItem [$k]))
					$item [$k] = $loadedItem [$k];
			}
		}

		return $item;
	}

	public function saveFormData (TableForm &$formData, $saveData = NULL)
	{
		$data = $this->app()->testGetData();
		$saveData = json_decode ($data, true);
		$ndx = $saveData ['recData']['ndx'];

		$appOptions = $this->app()->appOptions();
		$option = $appOptions [$ndx];
		$fileName = $this->appOptionFileName($ndx, $option);

		if ($option ['type'] == 'yamlBlob')
		{
			file_put_contents ($fileName, $saveData['recData']['yamlBlob']);

			require_once '3rd/spyc/spyc.php';
			$test = \Spyc::YAMLLoad ($saveData['recData']['yamlBlob']);
		}
		else
		{
			$item = array ();
			forEach ($option ['options'] as $o)
			{
				$k = $o['cfgKey'];
				if (isset ($saveData['recData'][$k]))
					$item[$k] = $saveData['recData'][$k];
			}

			file_put_contents ($fileName, Json::lint ($item));
		}

		$formData->recData = $this->loadItem ($ndx);
		compileConfig();
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = isset ($recData ['option']['icon']) ? $recData ['option']['icon'] : 'x-cog';
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['option']['name']];

		return $hdr;
	}
}


/*
 * ViewAppOptions
 *
 */

class ViewAppOptions extends TableView
{
	var $groupsParam;

	public function init ()
	{
		$this->usePanelLeft = TRUE;

		$enum = [];
		$groups = \E10\sortByOneKey($this->app()->cfgItem ('e10.appOptions.groups', []), 'order', TRUE);
		foreach ($groups as $groupId => $group)
		{
			if (!utils::enabledCfgItem ($this->app(), $group))
				continue;
			if (!$this->cfgGroupEnabled($groupId))
				continue;
			$enum[$groupId] = $group['title'];
		}
		$enum['all'] = 'Vše';

		$this->groupsParam = new \E10\Params ($this->app);
		$this->groupsParam->addParam('switch', 'appOptionsGroup', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->groupsParam->detectValues();

		parent::init();
	}

	function cfgGroupEnabled($groupId)
	{
		$allAppOptions = $this->table->app()->appOptions();
		foreach ($allAppOptions as $c)
		{
			if ($c['group'] !== $groupId)
				continue;

			if ($c ['type'] === 'viewer')
			{
				$c['object'] = 'viewer';
				if ($this->app()->checkAccess($c) !== 0)
					return 1;
			}
			else
			{
				if ($this->app()->hasRole('admin'))
					return 1;
			}
		}

		return 0;
	}

	public function createToolbar ()
	{
		return array ();
	} // createToolbar

	public function createDetails ()
	{
		return array ();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{

		$qry = [];

		$qry[] = ['style' => 'params', 'params' => $this->groupsParam];

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function selectRows ()
	{
		$group = $this->groupsParam->detectValues()['appOptionsGroup']['value'];

		$this->rowsPageSize = 500;
		$this->queryRows = array ();
		$this->ok = 1;

		if ($this->rowsFirst > 0)
			return;

		$fts = $this->fullTextSearch();

		$appOptions = sortByOneKey($this->table->app()->appOptions(), 'order', TRUE);
		forEach ($appOptions as $id => $c)
		{
			if (!utils::enabledCfgItem ($this->app(), $c))
				continue;

			if ($c ['type'] === 'viewer')
			{
				$c['object'] = 'viewer';
				if ($this->app()->checkAccess ($c) === 0)
					continue;
			}
			else
			{
				if (!$this->app()->hasRole ('admin'))
					continue;
			}

			$fullFileName = '';
			if ($c ['type'] == 'cfgFile' || $c ['type'] == 'yamlBlob')
			{
				$fullFileName = $this->table->appOptionFileName($id, $c);
			}

			if ($group !== 'all' && $c['group'] !== $group)
				continue;

			if ($fts != '')
			{
				$nd = strtr($c ['name'], utils::$transDiacritic);
				if (mb_stristr($c ['name'], $fts, FALSE, 'UTF-8') === FALSE && mb_stristr($nd, $fts, FALSE, 'UTF-8') === FALSE)
					continue;
			}

			$icon = isset ($c ['icon']) ? $c ['icon'] : '';
			$help = '';
			if ($c ['type'] === 'viewer')
			{
				/** @var \e10\DbTable $table */
				$table = $this->app()->table($c['table']);
				if (!$table)
				{
					error_log("Invalid table `{$c['table']}`");
					continue;
				}
				$vd = $table->viewDefinition($c['viewer']);
				if (!$vd)
					$vd = $table->viewDefinition ('default');
				if ($vd && isset($vd['help']))
					$help = $vd ['help'];
				if ($icon === '')
					$icon = $table->tableIcon([]);
			}
			elseif (isset($c['help']))
				$help = $c['help'];

			if ($icon === '')
				$icon = 'system/iconFile';

			$this->queryRows [] = [
				'ndx' => $id, 'name' => $c ['name'], 'ffn' => $fullFileName,
				'icon' => $icon,
				'help' => $help,
			];
		}
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['icon'] = $item['icon'];
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item ['name'];

		if ($item ['help'] !== '')
		{
			$listItem ['i1'] =[
				'type' => 'action', 'action' => 'open-popup', 'text' => '',
				'icon' => 'system/iconHelp', 'style' => 'cancel', 'side' => 1,
				'data-popup-url' => 'https://shipard.org/'.$item['help'],
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'title' => 'Nápověda'//DictSystem::text(DictSystem::diBtn_Help)
			];
		}

		$listItem ['t2code'] = '&nbsp;';

		return $listItem;
	}
} // class ViewAppOptions


/**
 * Class ViewDetailAppOptions
 * @package E10
 */
class ViewDetailAppOptions extends TableViewDetail
{
	public function createDetailContent ()
	{
		$item = $this->item;
		if ($item ['option']['type'] == 'cfgFile')
			$this->createDetailContentFile ();
		else
		if ($item ['option']['type'] == 'yamlBlob')
			$this->createDetailContentYamlBlob ();
		else
		if ($item ['option']['type'] == 'viewer')
			$this->createDetailViewer ();
	}

	public function createDetailContentFile ()
	{
		$item = $this->item;
		$c = '';

		$props = array ();
		forEach ($item ['option']['options'] as $o)
		{
			if (isset ($o['hidden']))
				continue;
			if (!isset($item[$o ['cfgKey']]))
			{
				$props[] = ['p' => $o ['cfgName'], 'v' => ''];
				continue;
			}

			if (isset ($o ['cfgItem']))
			{
				$cfgItem = $this->app()->cfgItem ($o ['cfgItem'], []);
				$nameKey = 'name';
				$cfgValue = $cfgItem[$item [$o ['cfgKey']]];
				if (is_string($cfgValue))
					$props[] = array ('p' => $o ['cfgName'], 'v' => $cfgValue);
				else
					$props[] = array ('p' => $o ['cfgName'], 'v' => $cfgValue[$nameKey] ?? $cfgValue['fullName']);
			}
			else
			if (isset($o['options']) && isset($o['options'][$item [$o ['cfgKey']]]))
				$props[] = array ('p' => $o ['cfgName'], 'v' => $o['options'][$item [$o ['cfgKey']]]);
			else
				$props[] = array ('p' => $o ['cfgName'], 'v' => $item [$o ['cfgKey']]);
		}

		$this->addContent([
				'pane' => 'e10-pane e10-pane-table', 'table' => $props,
				'header' => ['p' => ' Nastavení', 'v' => 'Hodnota'], 'params' => ['hideHeader' => 1]]);
	}

	public function createDetailContentYamlBlob ()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => $this->item['yamlBlob']));
	}

	public function createDetailViewer ()
	{
		$item = $this->item;
		$this->addContentViewer ($item ['option']['table'], $item ['option']['viewer'], array ());
	}

	public function createToolbar ()
	{
		if ($this->item ['option']['type'] == 'cfgFile' || $this->item ['option']['type'] == 'yamlBlob')
			return [['type' => 'action', 'action' => 'editform', 'text' => 'Otevřít', 'data-table' => $this->tableId(), 'data-pk' => $this->item['ndx']]];
		return [];
	} // createToolbar
}


/*
 * FormAppOptions
 *
 */

class FormAppOptions extends TableForm
{
	public function renderForm ()
	{
		$item = $this->recData;
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		if ($item ['option']['type'] == 'yamlBlob')
			$this->setFlag ('maximize', 1);

		$this->openForm ();
			if ($item ['option']['type'] == 'yamlBlob')
			{
				$this->addInputMemo ("yamlBlob", "Text", TableForm::coFullSizeY);
			}
			else
			if (isset ($item ['option']['options']))
			{
				forEach ($item ['option']['options'] as $o)
					self::addCfgInput ($this, $o);
			}
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		return $this->defaultHedearCode ("e10-server-config", $this->recData['option']['name'], '');
	}


	public function renderSidebar ($type, $table, $id)
	{
		$allDataStr = $this->app()->testGetData();
		$allData = json_decode ($allDataStr, TRUE);
		$item = $allData ['recData'];
		$srcColumnName = $this->app()->testGetParam ('columnName');


		if ($type == 'column')
		{ // TODO: simplify code
			$colnameParts = explode ('.', $srcColumnName);
			if (count($colnameParts) > 1)
			{
				$item = $allData;
				array_pop ($colnameParts);
				forEach ($colnameParts as $p)
					$item = $item [$p];
			}
			$srcTable = $this->table->app()->table ($table);

			$srcColDef = utils::searchArray ($this->recData['option']['options'], 'cfgKey', $srcColumnName);

			$comboParams = $this->comboParams ($table, $id, $allData ['recData'], $item);

			$browseTable = $this->table->app()->table ($srcColDef ['reference']);
			$viewer = $browseTable->getTableView ("default", $comboParams);
			$viewer->objectSubType = TableView::vsMini;
			$viewer->comboSettings = array ('column' => $id);

			$viewer->renderViewerData ("html");
			$c = $viewer->createViewerCode ("html", "fullCode");

			$sideBar = new FormSidebar ($this->app());
			$sideBar->addTab('t1', $srcTable->tableName());
			$sideBar->setTabContent('t1', $c);

			$this->sidebar = $sideBar->createHtmlCode();
			return;
		}
	}

	static function addCfgInput ($form, $o, $prefixKey = '', $hideHidden = TRUE)
	{
		$columnOptions = 0;
		if ($hideHidden && isset ($o['hidden']))
			$columnOptions = TableForm::coHidden;

		if (isset($o['preHeader']))
		{
			$form->addStatic($o['preHeader']);
		}

		if (isset ($o ['cfgItem']))
		{
			$options = [];
			$cfgItem = $form->app()->cfgItem ($o ['cfgItem'], []);
			$nameKey = 'name';
			foreach ($cfgItem as $cfgId => $cfgValue)
			{
				if (is_string($cfgValue))
					$options[$cfgId] = $cfgValue;
				else
					$options[$cfgId] = $cfgValue[$nameKey] ?? $cfgValue['fullName'];
			}
			$form->addInputEnum2 ($prefixKey.$o ['cfgKey'], $o ['cfgName'], $options, $style = self::INPUT_STYLE_OPTION);
		}
		elseif (isset ($o ['options']))
			$form->addInputEnum2 ($prefixKey.$o ['cfgKey'], $o ['cfgName'], $o ['options'], $style = self::INPUT_STYLE_OPTION);
		elseif (isset ($o ['reference']))
			$form->addInputIntRef ($prefixKey.$o ['cfgKey'], $o ['reference'], $o ['cfgName']);
		elseif (isset ($o ['subtype']) && $o ['subtype'] === 'color')
			$form->addInput ($prefixKey.$o ['cfgKey'], $o ['cfgName'], TableForm::INPUT_STYLE_STRING_COLOR, $columnOptions, 100, FALSE, utils::cfgItem($o, 'placeholder', ''));
		else
			$form->addInput ($prefixKey.$o ['cfgKey'], $o ['cfgName'], TableForm::INPUT_STYLE_STRING, $columnOptions, 100, FALSE, utils::cfgItem($o, 'placeholder', ''));
	}
} // class FormAppOptions


function compileConfig ()
{
	$manager = new \E10\CfgManager();
	$manager->load ();
	$manager->appCompileConfig ();

	$dirtyFileName = __APP_DIR__ . '/config/configIsDirty.txt';
	if (is_file ($dirtyFileName))
		unlink ($dirtyFileName);
}


function updateConfiguration ($app, $params = 0)
{
	// -- generate cfg files from all tables
	forEach ($app->model()->tables () as $tableId => $tblDef)
	{
		if ($tblDef ['options'] & DataModel::toConfigSource)
		{
			$table = $app->table ($tableId);
			$table->saveConfig ();
		}
	}

	// -- compile
	compileConfig ();


	$objectData ['message'] = 'Nastavení bylo přegenerováno.';
	$objectData ['finalAction'] = 'reloadPanel';

	$r = new \E10\Response ($app);
	$r->add ("objectType", "panelAction");
	$r->add ("object", $objectData);

	return $r;
}


class widgetServerInfo extends Widget
{
	public function create ()
	{
		$wid = $this->app()->testGetParam ('newElementId');
		if ($wid == '')
			$wid = 'wid' . time ();

		$c = "";
		$c .= "<div id='$wid'>";
		$c .= "...";
		$c .= "</div>";

		$this->html = $c;
	}


}
