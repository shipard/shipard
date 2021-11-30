<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;

class DeviceMonitoringTemplate extends \E10\TemplateCore
{
	public function loadTemplate ($name, $templateFileName = 'page.mustache', $forceTemplateScript = NULL)
	{
		$fullTemplateName = __SHPD_MODULES_DIR__ . $name;

		if ($templateFileName !== FALSE)
			$this->template = file_get_contents ($fullTemplateName);
	}

	function netdataPageBegin ()
	{
		$c = '';

		$c .= "<!DOCTYPE html>\n";
		$c .= "<html lang='en'>\n";
		$c .= "<head>\n";
		$c .= "<title>Your dashboard</title>\n";

		$c .= "<meta http-equiv='Content-Type' content='text/html; charset=utf-8' />\n";
		$c .= "<meta charset='utf-8'>\n";
		$c .= "<meta http-equiv='X-UA-Compatible' content='IE=edge,chrome=1'>\n";
		$c .= "<meta name='viewport' content='width=device-width, initial-scale=1'>\n";
		$c .= "<meta name='apple-mobile-web-app-capable' content='yes'>\n";
		$c .= "<meta name='apple-mobile-web-app-status-bar-style' content='black-translucent'>\n";

		$c .= "<link href='https://cdn.shipard.com/font-awesome/4.7.0/style/font-awesome.min.css' rel='stylesheet'>\n";

		$c .= "<script type='text/javascript'>\n";
		$c .= "var netdataServer = '{$this->data['dataSourceUrl']}'\n;";
		$c .= "var netdataNoFontAwesome = true;\n";
		$c .= "</script>\n";
		$c .= "<script type='text/javascript' src='{$this->data['dataSourceUrl']}/dashboard.js'></script>";

		$c .= "<script>\n";
		$c .= "NETDATA.options.current.destroy_on_hide = true;\n";
		$c .= "NETDATA.options.current.eliminate_zero_dimensions = true;\n";
		$c .= "NETDATA.options.current.stop_updates_when_focus_is_lost = false;\n";
		$c .= "
NETDATA.icons = {
    left: '<i class=\"fa fa-backward\"></i>',
    reset: '<i class=\"fa fa-play\"></i>',
    right: '<i class=\"fa fa-forward\"></i>',
    zoomIn: '<i class=\"fa fa-plus\"></i>',
    zoomOut: '<i class=\"fa fa-minus\"></i>',
    resize: '<i class=\"fa fa-sort\"></i>',
    lineChart: '<i class=\"fa fa-chart-line\"></i>',
    areaChart: '<i class=\"fa fa-chart-area\"></i>',
    noChart: '<i class=\"fa fa-chart-area\"></i>',
    loading: '<i class=\"fa fa-sync-alt\"></i>',
    noData: '<i class=\"fa fa-exclamation-triangle\"></i>'
};		
		";

		$c .= "</script>\n";

		$c .= "<style>
.clear {clear: both;}
.number  {text-align: right !important; }
.nowrap  {white-space:pre;}
.weight  {width: 4em;}
.price  {width: 5em;}
.short  {width: 2em;}
.center  {text-align: center !important;}
.align-right  {text-align: right;}
.h1 {font-size: 133%; }
.h2 {font-size: 122%; padding-bottom: 2px;}
.h3 {font-size: 111%; padding-bottom: 2px;}

.e10-error {color: #cc2020 !important;}
img.e10-error {border: 4px solid #cc2020;}

input.e10-invalid {color: #cc2020 !important; border: 1px solid #AA0000 !important;}

.e10-success {color: #10c010 !important;}

.e10-state-off {color: #777 !important;}
.e10-state-on {color: #20b020 !important;}

.e10-me {color: #4078c0 !important;}
.e10-state-new {background-color: @conceptHandleBackgroundColor;}
.e10-state-done {background-color: @doneHandleBackgroundColor;}
.e10-state-confirmed {background-color: @confirmedHandleBackgroundColor;}

.e10-warning0 {background-color: #fff;}
.e10-warning1 {background-color: #fff0f0 !important;}
.e10-warning2 {background-color: #ffe0e0 !important;}
.e10-warning3 {background-color: #ffb0b0 !important;}
.e10-off {color: #777;}
.e10-small {color: #555; font-size: 85%;}
.e10-bold {font-weight: 600;}
.e10-em {font-style: italic;}
.e10-del {text-decoration: line-through;}
.block {display: block;}
.break:before {display: block; content:\"\";}

.e10-row-this {background-color: #eef !important;}
.e10-row-info {background-color: #ffe !important;}
.e10-row-plus {background-color: #efe !important;}
.e10-row-minus {background-color: #fee !important;}

.e10-row-play {background-color: #e8f5e9;}
.e10-row-stop {background-color: #fcc;}
.e10-row-pause {background-color: #ffcb6b;}

.e10-bg-t1 {background-color: #e1f7d5;}
.e10-bg-t2 {background-color: #ffcfab;}
.e10-bg-t3 {background-color: #ffe0e5;}
.e10-bg-t4 {background-color: #fbffb5;}
.e10-bg-t5 {background-color: #e0fffa;}
.e10-bg-t6 {background-color: #c5e0d6;}
.e10-bg-t7 {background-color: #9fe28f;}
.e10-bg-t8 {background-color: #ffefd5;}
.e10-bg-t9 {background-color: #eee;}
.e10-bg-none {background-color: inherit;}
.e10-bg-white {background-color: #fff;}

.e10-border-off-left {border-left: none !important;}
.e10-border-off-right {border-right: 1px solid transparent !important;}
.e10-border-off-top {border-top: none !important;}
.e10-border-off-bottom {border-bottom: none !important;}

.pre {opacity: .7; font-size: 85%; padding-right: 1ex;}
.suf {opacity: .7; font-size: 85%; padding-left: 1ex;}


</style>
		";

		$c .= "</head>\n";

		$c .= "<body>\n";

		return $c;
	}

	function netdataPageEnd ()
	{
		$c = '';

		$c .= "</body>\n";
		$c .= "</html>\n";

		return $c;
	}

	public function templateCode()
	{
		return $this->template;
	}
}
