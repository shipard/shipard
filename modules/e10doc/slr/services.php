<?php
namespace e10doc\slr;


/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2024-06-30', 'sql' => "UPDATE e10doc_slr_imports SET importType = 'cz-perm' WHERE importType = ''"];

		$this->doSqlScripts ($s);
	}

	public function cliRunImport()
	{
		$paramImportNdx = intval($this->app->arg('importNdx'));
		if (!$paramImportNdx)
		{
			echo "ERROR: missing param `--importNdx`\n";
			return FALSE;
		}

		/** @var \e10doc\slr\TableImports */
		$tableImports = $this->app()->table('e10doc.slr.imports');
		$e = $tableImports->importEngine ($paramImportNdx);

		if (!$e)
		{
			echo "ERROR: invalid param `--importNdx` or bad import type\n";
			return FALSE;
		}

		$e->setImportNdx($paramImportNdx);
		$e->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'run-import': return $this->cliRunImport();
		}

		parent::onCliAction($actionId);
	}
}


