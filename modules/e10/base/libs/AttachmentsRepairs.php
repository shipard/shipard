<?php
namespace e10\base\libs;
use \Shipard\Base\Utility;


/**
 * Class AttachmentsRepairs
 */
class AttachmentsRepairs extends Utility
{
  public function repairFileSize($maxCount = 100)
  {
		$q = [];
		array_push($q, 'SELECT atts.*');
		array_push($q, ' FROM [e10_attachments_files] AS atts');
		array_push($q, ' WHERE 1');
    array_push($q, ' AND fileSize = %i', 0);
		array_push($q, ' ORDER BY ndx');
    array_push($q, ' LIMIT %i', $maxCount);

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $ffn = 'att/'.$r['path'].$r['filename'];
      $fs = @filesize($ffn);
      if ($fs === FALSE)
        $fs = -1;
      if ($fs === 0)
        $fs = -2;
      $this->db()->query('UPDATE e10_attachments_files SET fileSize = %i', $fs, ' WHERE ndx = %i', $r['ndx']);

      if ($this->app()->debug)
        echo sprintf('%05d', $cnt).": $ffn --> $fs\n";

      $cnt++;
    }
  }
}
