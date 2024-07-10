<?php
namespace e10doc\cmnbkp\libs\imports;
use \e10\base\libs\UtilsBase;


/**
 * class ImportBase
 */
class ImportBase extends \Shipard\Base\Utility
{
  var $docNdx = 0;
  var $files = [];

  public function setDocument($docNdx)
  {
    $this->docNdx = $docNdx;

		$allAttachments = UtilsBase::loadAllRecAttachments($this->app(), 'e10doc.core.heads', $docNdx);
		$this->files = [];
		foreach ($allAttachments as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];
			$this->files[] = $srcFullFileName;
		}
  }

  public function doImport()
  {
  }

  public function title()
  {
    return "INVALID IMPORT";
  }
}
