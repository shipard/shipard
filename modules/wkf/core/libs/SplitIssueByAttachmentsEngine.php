<?php
namespace wkf\core\libs;

use \Shipard\Base\Utility;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Str;


/**
 * class SplitIssueByAttachmentsEngine
 */
class SplitIssueByAttachmentsEngine extends Utility
{
  /** @var \wkf\core\TableIssues $tableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;
  var $atts = NULL;


  public function setIssueNdx($issueNdx)
  {
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->issueNdx = $issueNdx;
		$this->issueRecData = $this->tableIssues->loadItem($this->issueNdx);


		$this->atts = UtilsBase::loadAttachments ($this->app(), [$this->issueNdx], 'wkf.core.issues');
  }

  protected function doOne($att, $idx)
  {
    $subjectSuffix = ' ['.$idx.']';
    $issueRecData = [
      'section' => $this->issueRecData['section'],
      'issueKind' => $this->issueRecData['issueKind'],
      'issueType' => $this->issueRecData['issueType'],
      'subject' => Str::upToLen($this->issueRecData['subject'], 99 - strlen($subjectSuffix)).$subjectSuffix,
      'source' => 0,
      'docState' => 1001, 'docStateMain' => 0
    ];

    $this->tableIssues->checkNewRec ($issueRecData);
    $newIssueNdx = $this->tableIssues->dbInsertRec ($issueRecData);
    $issueRecData = $this->tableIssues->loadItem($newIssueNdx);
    $this->tableIssues->checkAfterSave2($issueRecData);

    $this->db()->query('UPDATE [e10_attachments_files] SET [recid] = %i', $newIssueNdx, ' WHERE [ndx] = %i', $att['ndx']);
    $this->tableIssues->docsLog ($newIssueNdx);
  }

  public function run()
  {
    $cnt = 0;
		$attachments = $this->atts[$this->issueNdx];
		if (isset($attachments['images']))
		{
			foreach ($attachments['images'] as $a)
			{
        $cnt++;
        if ($cnt === 1)
          continue;
        $this->doOne($a, $cnt);
			}
		}
		if (isset($attachments['files']))
		{
			foreach ($attachments['files'] as $a)
			{
        $cnt++;
        if ($cnt === 1)
          continue;
        $this->doOne($a, $cnt);
      }
		}
  }
}
