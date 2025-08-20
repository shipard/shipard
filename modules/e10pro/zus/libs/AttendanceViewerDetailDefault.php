<?php

namespace e10pro\zus\libs;
use \Shipard\Viewer\TableViewDetail;


/**
 * class AttendanceViewerDetailDefault
 */
class AttendanceViewerDetailDefault extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.DCAttendance');
	}

	public function createToolbar ()
	{
		return [];
	}
}
