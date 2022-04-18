<?php

namespace hosting\core\libs;
use \Shipard\UI\Core\WidgetBoard;
use \Shipard\Utils\Utils;


/**
 * @class WidgetHostingOverview
 */
class WidgetHostingOverview extends WidgetBoard
{
	public function createContent ()
	{
    $this->panelStyle = self::psNone;

		$o = new \hosting\core\libs\HostingOverview($this->app);
		$o->run();

		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $o->code]);

    $c = "<script>
    function hostingDahboardReloadBadges ()
    {
      var overviewElement = $('#e10dashboardWidget');
      if (!overviewElement.length)
        return;
      overviewElement.find ('img.e10-auto-reload').each (function () {
        var url = $(this).attr('data-src') + '?xyz='+Date.now();
        $(this).attr('src', url);
      });

      setTimeout(hostingDahboardReloadBadges, 20000);
    };
    setTimeout(hostingDahboardReloadBadges, 20000);
    </script>";
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function title()
	{
		return FALSE;
	}
}
