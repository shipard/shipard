<?php
namespace e10doc\cmnbkp\libs\imports;

use \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class ImportHelper
 */
class ImportHelper extends \Shipard\Base\Utility
{
  public function createImportFromDocument(int $docNdx)
  {
		$allAttachments = UtilsBase::loadAllRecAttachments($this->app(), 'e10doc.core.heads', $docNdx);
		$files = [];
		foreach ($allAttachments as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];
			$files[] = $srcFullFileName;
		}

    return $this->createImportFromAttachments($files);
  }

  public function createImportFromAttachments(array $uploadedFiles)
  {
    $enabledFileTypes = ['xml'];
    foreach ($uploadedFiles as $oneFile)
    {
      $path_parts = pathinfo ($oneFile);
      $baseFileName = $path_parts ['filename'];
      $fileType = $path_parts ['extension'];
      if (!in_array($fileType, $enabledFileTypes))
        continue;

      $textData = file_get_contents($oneFile);
      $o = $this->createImportObject($textData);
      if ($o)
        return $o;
    }

    return NULL;
  }

  function createImportObject ($textData)
  {
    $ffn = __SHPD_MODULES_DIR__ . 'e10doc/cmnbkp/libs/imports/formats.json';
    $formats = Utils::loadCfgFile($ffn);

    $formatCfg = NULL;
    forEach ($formats as $f)
    {
      if (isset ($f['checkRegExp']))
      {
        if (preg_match ($f['checkRegExp'], $textData) === 1)
        {
          $formatCfg = $f;
          break;
        }
      }
      if (isset ($f['checkRegExp2']))
      {
        if (preg_match ($f['checkRegExp2'], $textData) === 1)
        {
          $formatCfg = $f;
          break;
        }
      }
    }

    if ($formatCfg)
    {
      $classId = $formatCfg['classId'];
      return $this->app()->createObject($classId);
    }

    return NULL;
  }
}
