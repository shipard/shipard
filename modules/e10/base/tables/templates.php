<?php

namespace e10\base;

include_once __DIR__ . '/../base.php';

use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\DbTable;


/**
 * Class TableTemplates
 * @package e10\base
 */
class TableTemplates extends DbTable
{
	var $stdTemplates = [];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.templates", "e10_base_templates", "Šablony");
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);
		$this->rebuildTemplate ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (!isset ($recData['sn']) || $recData['sn'] === '')
		{
			$md5 = md5 (json_encode ($recData).time().mt_rand(0, 999999999).$this->app()->cfgItem ('dsid', time()));
			$recData['sn'] = substr($md5, 0, 8).'-'.substr($md5, 8, 8).'-'.substr($md5, 16, 8).'-'.substr($md5, 24, 8);
		}
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		if ($pk < 100000)
			return parent::columnRefInputTitle ($form, $srcColumnId, $inputPrefix);

		$pk = strval($pk);

		$webTemplates = $this->stdTemplates(0);

		$wt = isset($webTemplates[$pk]) ? $webTemplates[$pk] : ['name' => '!!! Neznámá šablona'];
		$refTitle = ['text' => $wt ['name']];

		return $refTitle;
	}

	public function templateName($type, $ndx)
	{
		if ($ndx < 100000)
		{
			$item = $this->loadItem($ndx);
			if (isset($item['name']))
				return $item['name'];

			return 'Neznámá šablona #'.$ndx;
		}

		$stdTemplates = $this->stdTemplates($type);
		if (isset ($stdTemplates[$ndx]))
			return $stdTemplates[$ndx]['name'];

		return 'Neznámá šablona #'.$ndx;
	}

	public function templateId ($templateType, $templateNdx)
	{
		//
		if ($templateNdx < 100000)
		{
			$template = $this->loadItem ($templateNdx);
			if ($template)
				return $template['sn'];
		}
		elseif ($templateNdx == 699999)
		{
			return 'e10pro.hosting.server.templates.me';
		}
		else
		{
			$stdTemplates = $this->stdTemplates ($templateType);
			if (isset($stdTemplates[$templateNdx]))
			{
				$stdTemplate = $stdTemplates[$templateNdx];
				return 'e10templates.web.'.$stdTemplate['id'];
			}
		}

		return '';
	}

	public function stdTemplates ($type)
	{
		if (isset($this->stdTemplates[$type]))
			return $this->stdTemplates[$type];


		$stdTemplates = NULL;
		if ($type == 0)
		{ // -- web
			$allTemplates = utils::loadCfgFile(__APP_DIR__.'/e10-modules/e10templates/web/templates.json');
			$stdTemplates = [];
			foreach ($allTemplates as $oneTemplateId => $oneTemplate)
			{
				if (isset($oneTemplate['checkModule']) && $this->app()->dataModel->module($oneTemplate['checkModule']) === FALSE)
					continue;
				$stdTemplates[$oneTemplateId] = $oneTemplate;
			}

			if ($this->app()->dataModel->module('e10pro.hosting.server') !== FALSE)
				$stdTemplates['699999'] = ['id' => 'e10pro.hosting.server.templates.me', 'name' => 'Portál'];
		}

		if ($stdTemplates)
		{
			$this->stdTemplates[$type] = $stdTemplates;
			return $this->stdTemplates[$type];
		}

		return [];
	}

	public function getTemplateDir ($recData)
	{
		$templateRoot = __APP_DIR__ . '/templates/'.$recData['sn'] . '/';

		if (!is_dir($templateRoot))
			mkdir ($templateRoot, 0770, TRUE);

		return $templateRoot;
	}

	public function saveFile ($recData, $fileName, $fileContent, $deleted = FALSE)
	{
		if ($fileName == '')
			return '';

		$templateDir = $this->getTemplateDir($recData);

		$destFileName = $templateDir.$fileName;
		$path_parts = pathinfo ($destFileName);

		if ($deleted)
		{
			if (is_file($destFileName))
				unlink($destFileName);
			return '';
		}

		if (!is_dir($path_parts['dirname']))
			mkdir ($path_parts['dirname'], 0770, TRUE);

		file_put_contents($destFileName, $fileContent);

		if ($path_parts['extension'] === 'less' || $path_parts['extension'] === 'scss')
		{
			return $this->compileFile ($recData, $templateDir, 1, $fileName);
		}

		return '';
	}

	public function symlinkFile ($attFileName, $destFileName)
	{
		$path_parts = pathinfo ($destFileName);

		if (!is_dir($path_parts['dirname']))
			mkdir ($path_parts['dirname'], 0770, TRUE);

		if (is_file($destFileName))
			unlink($destFileName);

		symlink($attFileName, $destFileName);
	}

	public function createAttachments ($recData)
	{
		$templateDir = $this->getTemplateDir($recData);

		$sql = "SELECT * FROM [e10_attachments_files] where [tableid] = %s AND [recid] = %i AND [deleted] = 0";
		$query = $this->app()->db->query ($sql, 'e10.base.templates', $recData['ndx']);
		foreach ($query as $row)
		{
			$attFileName = __APP_DIR__.'/att/'.$row['path'].$row['filename'];
			$fileName = $templateDir.$row['name'];
			$this->symlinkFile($attFileName, $fileName);
		}
	}

	public function createFiles ($recData)
	{
		$sql = "SELECT * FROM [e10_base_subtemplates] where [template] = %i ORDER BY docStateMain DESC, [type]";
		$query = $this->app()->db->query ($sql, $recData['ndx']);
		foreach ($query as $row)
		{
			$this->saveFile ($recData, $row['fileName'], $row['code'], ($row['docState'] === 9800));
		}
	}

	public function compileFile ($recData, $templateDir, $fileType, $fileName)
	{
		$sql = "SELECT * FROM [e10_base_subtemplates] where [template] = %i AND [type] = %i AND fileName = %s";
		$query = $this->app()->db->query ($sql, $recData['ndx'], $fileType, $fileName);
		foreach ($query as $row)
		{
			$lessFileName = $templateDir.'/'.$row['fileName'];
			$path_parts = pathinfo ($lessFileName);
			$cssFileName = $path_parts['dirname'].'/'.$path_parts['filename'].'.css';

			if ($path_parts['extension'] === 'scss')
				$cmd = "sass $lessFileName $cssFileName 2>&1";
			else
				$cmd = "/bin/lessc --no-color -x $lessFileName $cssFileName 2>&1";

			$output = '';
			$fp = popen($cmd, 'r');
			while(!feof($fp))
				$output .= fread($fp, 1024);
			fclose($fp);

			$output = preg_replace ("/(in \\/.+\\/)/i", 'in ', $output);
			return $output;
		}

		return '';
	}

	public function rebuildTemplate ($recData, $setOwner = FALSE)
	{
		parent::checkAfterSave2 ($recData);

		$templateDir = $this->getTemplateDir ($recData);
		exec ("rm -rf {$templateDir}/*");

		if ($recData['type'] == 0) // web
		{
			$templateInfo = array ('name' => $recData['name']);
			$this->saveFile ($recData, 'template.json', json_encode ($templateInfo));
		}

		$this->createAttachments ($recData);
		$this->createFiles ($recData);

		if ($setOwner)
			exec ('chgrp -R '.utils::wwwGroup()." {$templateDir}/*");
	}
} // class TableTemplates


/**
 * Class ViewTemplates
 * @package E10\Base
 */
class ViewTemplates extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['replaceId'];

		$fileTypes = $this->table->columnInfoEnum ('type', 'cfgText');
		$listItem ['i2'] = $fileTypes [$item ['type']];

		//$listItem ['itemType'] = $item ['type'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10_base_templates] WHERE 1";

		$this->defaultQuery ($q);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([name] LIKE %s OR [sn] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [name], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
} // class ViewTemplates


/**
 * Základní detail Šablony
 *
 */

class ViewDetailTemplate extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = $item ['sn'];
		return $this->defaultHedearCode ($this->table->tableIcon ($item), $item ['name'], $info);
	}

	public function createDetailContent ()
	{
		$this->addContentViewer ('e10.base.subtemplates', 'e10.base.ViewSubTemplates',
														 array ('template' => $this->item ['ndx']));
	}
} // ViewDetailTemplate


/*
 * FormTemplate
 *
 */

class FormTemplate extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();

			$tabs ['tabs'][] = array ('text' => 'Kód', 'icon' => 'x-content');
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-image');
			$this->openTabs ($tabs);

				$this->openTab ();
					$this->addColumnInput ("type");
					$this->addColumnInput ("name");
					$this->addColumnInput ("replaceId");
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';
		return $this->defaultHedearCode ('x-properties', $item ['name'], $info);
	}
} // class FormTemplate


/**
 * Class ViewTemplatesWeb
 * @package E10\Base
 */
class ViewTemplatesWeb extends ViewTemplates
{
	public function defaultQuery (&$q)
	{
		array_push ($q, ' AND [type] = %i', 0);
	}
}


/**
 * Class ViewTemplatesWebCombo
 * @package E10\Base
 */
class ViewTemplatesWebCombo extends ViewTemplatesWeb
{
	public function renderRow_TMP ($item)
	{
		$listItem = parent::renderRow($item);
		$listItem ['data-cc']['template'] = $item['sn'];

		return $listItem;
	}

	public function selectRows ()
	{
		$this->rowsPageSize = 500;
		//parent::selectRows();
		$fts = '';

		// -- user defined templates
		$q [] = "SELECT * from [e10_base_templates]";

		array_push ($q, ' WHERE [type] = 0 AND [docStateMain] < 4');
		array_push ($q, ' ORDER BY [name], [ndx] ' . $this->sqlLimit ());
		if ($fts != '')
			array_push ($q, ' AND ([name] LIKE %s OR [sn] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$rows = $this->db->query ($q);
		foreach ($rows as $r)
		{
			$this->queryRows [] = $r->toArray();
		}

		// -- standard templates
		$webTemplates = $this->table->stdTemplates(0);
		if (!$webTemplates)
			return;

		foreach ($webTemplates as $wtNdx => $wt)
		{
			$ii = ['ndx' => intval($wtNdx), 'name' => $wt['name'], 'type' => 0];
			$this->queryRows [] = $ii;
		}
	}
}

