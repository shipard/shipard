<?php

namespace demo\documents\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10\str, \e10\utils, wkf\core\TableIssues;


/**
 * Class InboxDocument
 * @package lib\demo\documents\docs
 */
class InboxDocument extends \demo\core\libs\Task
{
	var $inboxPackage = NULL;
	var $inboxNdx = 0;

	public function init ($taskDef, $taskTypeDef)
	{
		$tableIssues = $this->app->table ('wkf.core.issues');
		$issueKind = $tableIssues->defaultSystemKind (51, TRUE);
		$section = $tableIssues->defaultSection (51);

		parent::init($taskDef, $taskTypeDef);

		$tomorrow = utils::today();
		$tomorrow->sub (new \DateInterval('P3D'));

		$q[] = 'SELECT * FROM [wkf_core_issues] WHERE 1';
		array_push ($q, ' AND [issueType] = %i', TableIssues::mtInbox);
		array_push ($q, ' AND [issueKind] = %i', $issueKind);
		array_push ($q, ' AND [docState] = %i', 1200);
		array_push ($q, ' AND [dateIncoming] < %d', $tomorrow);
		array_push ($q, ' ORDER BY [dateCreate] DESC, [ndx] DESC');
		array_push ($q, ' LIMIT 1');

		$r = $this->db()->query ($q)->fetch();
		if (!$r)
			return;

		$this->inboxNdx = $r['ndx'];
		$fn = __APP_DIR__.'/tmp/inbox-document-'.$this->inboxNdx.'.json';
		$this->inboxPackage = $this->loadCfgFile($fn);

		if (!$this->inboxPackage)
			return;

		$this->inboxPackage['datasets'][0]['defaultValues']['docState'] = 4000;
		$this->inboxPackage['datasets'][0]['defaultValues']['docStateMain'] = 2;
	}

	public function save()
	{
		if (!$this->inboxPackage)
			return;

		// -- save document
		$installer = new \lib\DataPackageInstaller ($this->app());
		$installer->installPackage($this->inboxPackage);
		$srcDocNdx = $installer->datasetPrimaryKeys[0];

		// -- create inbox doclink
		$newLink = [
			'linkId' => 'e10docs-inbox',
			'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $srcDocNdx,
			'dstTableId' => 'wkf.core.issues', 'dstRecId' => $this->inboxNdx
		];
		$this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);

		// -- set inbox message as resolved
		$inboxState = ['docState' => 4000, 'docStateMain' => 2, 'dateTouch' => utils::now()];
		$this->db()->query ('UPDATE [wkf_core_issues] SET', $inboxState, ' WHERE ndx = %i', $this->inboxNdx);

		$tableIssues = $this->app->table ('wkf.core.issues');
		$tableIssues->docsLog ($this->inboxNdx);
	}

	public function run()
	{
		$this->save();
		return TRUE;
	}
}
