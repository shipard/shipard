var googleMapsApi = 0;
const g_isMobile = window.matchMedia("(any-pointer:coarse)").matches;

function e10client () {

	this.appVersion = '2.0.1';
	this.CLICK_EVENT = 'click';
	this.g_formId = 1;
	this.openModals = [];
	this.progressCount = 0;
	this.viewerScroll = 0;
	this.pageType = 'home';
	this.disableKeyDown = 0;

	this.app = 0;
	this.clientType = 'mobile.browser';
	this.deviceId = null;
	this.httpServerRoot = '';
	this.userLogin = '';
	this.userSID = '';
	this.userPassword = '';
	this.userPin = '';
	this.standaloneApp = 0;
	this.oldBrowser = 0;

	this.appUrlRoot = '/mapp/';

	this.userInfo = null;

	/**
	 * action
	 */

	function e10Action(event, e) {
		var action = e.attr('data-action');

		if (action === 'form')
			return e10.form.open(e);

		if (action === 'form-close')
			return e10.form.close(e);

		if (action === 'form-done')
			return e10.form.done(e);

		if (action === 'app-options')
			return e10.options.openDialog();

		if (action === 'app-logout')
			return e10.appLogout();

		if (action === 'app-menu')
			return e10.options.openAppMenuDialog();

		if (action === 'app-about')
			return e10.options.appAbout(e);

		if (action === 'app-options-save')
			return e10.options.saveDialog(e);

		if (action === 'setInputValue')
		{
			$('#'+e.attr ('data-inputid')).val(e.text());
			return 0;
		}


		if (action === 'detail-add-photo')
			return e10.camera.takePhoto(e);
		if (action === 'detail-add-file')
			return e10.camera.takeFile(e);

		if (action === 'viewer-search')
			return e10.viewer.search(e);

		if (action === 'modal-close')
			return e10.closeModal();

		if (action === 'app-fs-plus')
			return e10.options.fontSize(e, 1);
		if (action === 'app-fs-minus')
			return e10.options.fontSize(e, -1);
		if (action === 'app-fs-reset')
			return e10.options.fontSize(e, 0);

		if (action === 'workspace-login')
			return e10.workspaceLogin(e);

		if (action === 'inline-action')
		{
			e10InlineAction (e);
			return;
		}
	}

	/**
	 * widgets
	 */

	function e10WidgetAction(event, e, widgetId) {
		if (e && e.attr ('data-call-function') !== undefined)
		{
			e10.executeFunctionByName (e.attr ('data-call-function'), e);
			return;
		}
		var widget = null;
		if (widgetId === undefined)
			widget = e10.searchObjectAttr(e, 'data-widget-class');
		else
			widget = $('#' + widgetId);

		var actionType = 'reload';
		if (e !== null)
			actionType = e.attr("data-action");

		var postData = {};
		if (widget.attr('data-collect') !== null) {
			var fn = window[widget.attr('data-collect')];
			if (typeof fn === "function")
				postData = fn(widget);

		}

		var fullCode = 0;
		if ((e && e.parent().hasClass('e10-wf-tabs')) || (widget.hasClass('e10-widget-viewer')))
			fullCode = 1;
		else if (!event && !e)
			fullCode = 1;

		if (e && e.parent().hasClass('e10-wf-tabs'))
		{
			var tabList = e.parent();
			var inputId = (tabList.hasClass('right')) ? 'e10-widget-topTab-value-right' : 'e10-widget-topTab-value';
			$('#'+inputId).val (e.attr('data-tabid'));
		}


		var className = widget.attr("data-widget-class");
		var widgetParams = widget.attr("data-widget-params");
		var oldWidgetId = widget.attr('id');
		var urlPath = "/api/widget/" + className + "/html?fullCode=" + fullCode + "&widgetAction=" + actionType + '&widgetId=' + oldWidgetId;
		if (widgetParams != '')
			urlPath += "&" + widgetParams;
		var params = e10.collectFormData(widget);
		if (params != '')
			urlPath += '&' + params;

		e10.server.post(urlPath, postData, function (data) {
			widget.find("*:first").remove();
			widget.html(data.object.mainCode);
			e10.widgetTabsInit();
			//if (className === 'e10.base.NotificationCentre')
			//	e10NCSetButton (data.object);
		});
	}

	function e10InlineAction (e)
	{
		if (e.attr('data-object-class-id') === undefined)
			return;

		var requestParams = {};
		requestParams['object-class-id'] = e.attr('data-object-class-id');
		requestParams['action-type'] = e.attr('data-action-type');
		elementPrefixedAttributes (e, 'data-action-param-', requestParams);
		if (e.attr('data-pk') !== undefined)
			requestParams['pk'] = e.attr('data-pk');

			e10.server.api(requestParams, function(data) {

			if (e.parent().hasClass('btn-group'))
			{
				e.parent().find('>button.active').removeClass('active');
				e.addClass('active');
			}
		});
	}

	function e10StaticTab (e, event)
	{
		e.parent().find ('li.active').removeClass ('active');
		e.addClass ('active');

		e.parent().parent().find('div.e10-static-tab-content>div.active').removeClass('active');
		$('#'+e.data('content-id')).addClass('active');

	}

	function e10FormsTabClick (e)
	{
		var pageid = e.attr ("id");

		if (pageid)
		{
			var activeTab = e.parent().find ("li.active").first();
			activeTab.removeClass ("active");

			$("#" + activeTab.attr ('id') + '-tc').hide();

			e.addClass ("active");
			$("#" + pageid + '-tc').show();
			//e10doSizeHints ($("#" + pageid + '-tc'));

			if (e.attr ('data-inputelement'))
			{
				$('#'+e.attr ('data-inputelement')+' input[name='+e.attr ('data-inputname')+']').val (e.attr ('data-inputvalue'));
			}

			return true;
		}

		return false;
	}

	function e10ReportChangeParam (e)
	{
		var param = e10.searchObjectAttr (e, 'data-paramid');
		//if (e.is ('BUTTON'))
		if (e.attr ('data-value'))
		{
			var value = e.attr('data-value');
			param.find('input').val(value).trigger('change');

			param.find('.active').removeClass('active');
			e.addClass ('active');

			if (!e.is ('BUTTON'))
			{
				var title = e.attr('data-title');
				param.find('>button>span.v').text(title);
			}
		}
		else
		{
			var value = e.parent().attr('data-value');
			var title = e.parent().attr('data-title');
			param.find('input').val(value).trigger('change');

			param.find('>button>span.v').text(title);
			param.find('.dropdown-menu .active').removeClass('active');
			e.parent().addClass ('active');
		}
	}

	function e10SensorToggle (event, e)
	{
		var sensorId = e.attr ('data-sensorid');
		var serveridx = parseInt (e.attr('data-serveridx'));
		var url = webSocketServers[serveridx].postUrl;
		url = url + '?callback=?&data=';
		//alert (serveridx)
		if (e.hasClass ('e10-sensor-on'))
		{
			var msg = {'deviceId': e10.deviceId, 'sensorId': sensorId, 'cmd': 'unlockSensor'};
			url += encodeURI (JSON.stringify (msg));
			$.getJSON(url, function(data){});
		}
		else
		{
			var msg = {'deviceId': e10.deviceId, 'sensorId': sensorId, 'cmd': 'lockSensor'};
			url += encodeURI (JSON.stringify (msg));
			$.getJSON(url, function(data){});
		}
		e.toggleClass ('e10-sensor-on');
	}

	this.e10LoadRemoteWidget = function (id)
	{
		var w = $('#'+id);
		var widgetClassId = w.attr ('data-widget-class');

		var url = "/api/widget/" + widgetClassId;
		if (w.attr ('data-widget-params'))
			url += '?' + w.attr ('data-widget-params');
		e10.server.get(url, function(data)
		{
			w.html (data.object.mainCode);
		});
	};

	/**
	 * viewer
	 */

	function e10viewerOpenDocument(e) {
		var pk = e.attr('data-pk');
		var path = httpOriginPath + '/' + pk;
		e10.loadPage(path);
	}


	/**
	 * upload files & camera support
	 */
	this.searchParentAttr = function (e, attr) {
		var p = e;
		while (p.length) {
			var attrValue = p.attr(attr);
			if (p.attr(attr))
				return p.attr(attr);

			p = p.parent();
			if (!p.length)
				break;
		}
		return null;
	};

	this.searchObjectAttr = function (e, attr) {
		var p = e;
		while (p.length) {
			if (p.attr(attr))
				return p;

			p = p.parent();
			if (!p.length)
				break;
		}

		return null;
	};


	this.e10AttWidgetFileSelected = function (input) {
		var infoPanel = $(input).parent().find('div.e10-att-input-files');

		var info = '<table>';
		for (var i = 0; i < input.files.length; i++) {
			var file = input.files[i];
			var fileSize = 0;
			if (file.size > 1024 * 1024)
				fileSize = (Math.round(file.size * 100 / (1024 * 1024)) / 100).toString() + 'MB';
			else
				fileSize = (Math.round(file.size * 100 / 1024) / 100).toString() + 'KB';
			info += '<tr>' + '<td>' + file.name + "</td><td class='number'>" + fileSize + '</td><td>-</td></tr>';
		}
		info += '</table>';
		infoPanel.html(info);
	};


	this.e10AttWidgetUploadFile = function (button) {
		var table = e10.searchParentAttr(button, 'data-table');
		if (table === null)
			table = '_tmp';

		var pk = e10.searchParentAttr(button, 'data-pk');

		var infoPanel = button.parent().parent().find('div.e10-att-input-files');
		var input = button.parent().parent().find('input:first').get(0);
		infoPanel.attr('data-fip', input.files.length);
		for (var i = 0; i < input.files.length; i++) {
			var file = input.files[i];
			var url = e10.httpServerRoot + "/upload/e10.base.attachments/" + table + '/' + pk + '/' + file.name;
			e10.e10AttWidgetUploadOneFile(url, file, infoPanel, i);
		}
	};


	this.e10AttWidgetUploadOneFile = function (url, file, infoPanel, idx) {
		var xhr = new XMLHttpRequest();
		xhr.upload.addEventListener("progress", function (e) {
			e10.e10AttWidgetUploadProgress(e, infoPanel, idx);
		}, false);
		//xhr.upload.addEventListener ("load", function (e) {e10AttWidgetUploadDone (e, infoPanel, idx);}, false);
		xhr.onload = function (e) {
			e10.e10AttWidgetUploadDone(e, infoPanel, idx);
		};
		xhr.open("POST", url);
		xhr.setRequestHeader("Cache-Control", "no-cache");
		xhr.setRequestHeader("Content-Type", "application/octet-stream");
		xhr.send(file);

		/*
		 req.upload.addEventListener("progress", updateProgress, false);
		 req.upload.addEventListener("load", transferComplete, false);
		 req.upload.addEventListener("error", transferFailed, false);
		 req.upload.addEventListener("abort", transferCanceled, false);
		 */

	};

	this.e10AttWidgetUploadDone = function (e, infoPanel, idx) {
		var cell = infoPanel.find('table tr:eq(' + idx + ') td:eq(2)');
		cell.css({"background-color": "green"}).attr('data-ufn', e.target.responseText);
		var fip = parseInt(infoPanel.attr('data-fip')) - 1;
		infoPanel.attr('data-fip', fip);

		if (fip == 0) {
		}

		var table = e10.searchParentAttr(infoPanel, 'data-table');
		if (table === null)
			table = '_tmp';

		var pk = e10.searchParentAttr(infoPanel, 'data-pk');

		e10.reloadDetail(table, pk);
	};

	this.e10AttWidgetUploadProgress = function (e, infoPanel, idx) {
		if (e.lengthComputable) {
			var percentage = Math.round((e.loaded * 100) / e.total);
			var cell = infoPanel.find('table tr:eq(' + idx + ') td:eq(2)');
			cell.text(percentage + ' % ');
		}
	};

	this.init = function () {
		//if ($.browser.chrome || $.browser.safari)
		//	e10.CLICK_EVENT = 'tap';

		$('body').on(e10.CLICK_EVENT, ".link", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.doLink($(this));
		});

		$('body').on(e10.CLICK_EVENT, "ul.e10-viewer-list >li.r", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.openDocument($(this));
		});

		$('body').on(e10.CLICK_EVENT, ".e10-document-trigger", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.openDocument($(this));
		});

		$('body').on(e10.CLICK_EVENT, "div.e10-page-end", function (event) {
			$('body').scrollTop(0);
		});

		$('body').on(e10.CLICK_EVENT, ".e10-trigger-action", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10Action(event, $(this));
		});

		$('body').on(e10.CLICK_EVENT, ".df2-action-trigger", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10Action(event, $(this));
		});

		$('body').on(e10.CLICK_EVENT, ".e10-trigger-gn", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.form.getNumberAction(event, $(this));
		});

		$('body').on(e10.CLICK_EVENT, ".e10-trigger-cv", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.form.comboViewerAction(event, $(this));
		});

		$('body').on ('search input', "input.e10-inc-search", function(event) {
			e10.viewer.incSearch ($(this), event);
		});

		$('body').on(e10.CLICK_EVENT, ".e10-widget-trigger, .df2-widget-trigger", function (event) {
			e10WidgetAction(event, $(this));
		});
		$("body").on ('change', "div.e10-widget-pane input, #e10dashboardWidget select", function(event) {
			e10WidgetAction(event, $(this));
		});
		$("body").on (e10.CLICK_EVENT, "div.e10-param .dropdown-menu a", function(event) {
			e10ReportChangeParam ($(this));
		});

		$("body").on (e10.CLICK_EVENT, "div.e10-param .e10-param-btn", function(event) {
			e10ReportChangeParam ($(this));
		});

		$('body').on (e10.CLICK_EVENT, "li.e10-sensor", function(event) {
			e10SensorToggle (event, $(this));
		});

		$('body').on(e10.CLICK_EVENT, "ul.e10-viewer-tabs>li", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.viewer.bottomTabsClick($(this));
		});

		$("body").on (e10.CLICK_EVENT, "ul.e10-widget-tabs>li", function(event) {
			e10FormsTabClick ($(this));
		});

		$("body").on (e10.CLICK_EVENT, "span.e10-sum-table-exp-icon", function(event) {
			e10SumTableExpandedCellClick ($(this), event);
		});

		$("body").on (e10.CLICK_EVENT, "li.e10-static-tab", function(event) {
			e10StaticTab ($(this), event);
		});

		$("body").on ('keydown', function(event) {
			e10.keyDown(event, $(this));
		});

		$(window).resize (e10.screenResize);

		this.pageTabsInit();
		this.widgetTabsInit();
	}
};




e10client.prototype.pageTabsInit = function (hideActive) {
	if (hideActive !== undefined) {
		var activeTab = $('#e10-page-tabs>li.active');
		if (activeTab.length !== 0) {
			var activeTabId = activeTab.attr('id');
			$('#' + activeTabId + '-c').hide();
			activeTab.removeClass('active');
		}
	}

	var tabs = $('#e10-page-tabs');
	if (tabs.length === 0) {
		$('body').removeClass('pageTabs');
		return;
	}
	$('body').addClass('pageTabs');

	$('#e10-page-tabs').on(e10.CLICK_EVENT, "li", function (event) {
		event.stopPropagation();
		event.preventDefault();
		e10.pageTabsClick($(this));
	});

	var activeTabId = '';

	if (window.location.hash != '')
		activeTabId = 'e10-page-tab-' + window.location.hash.substr(1);
	else {
		var activeTab = $('#e10-page-tabs>li.active');
		if (activeTab.length === 0)
			activeTab = $('#e10-page-tabs>li:first');

		activeTabId = activeTab.attr('id');
	}

	$('#' + activeTabId).addClass('active');
	$('#' + activeTabId + '-c').show();
};

e10client.prototype.widgetTabsInit = function () {
	var w = $('#e10-page-body');
	w.find ('ul.e10-widget-tabs').each (function ()
	{
		var id = $(this).attr('id');
		$("#" + id + " >li").each (function () {
			var tabId = $(this).attr ("id");
			var tabContentId = tabId + '-tc';
			if ($(this).hasClass ('active'))
				$('#' + tabContentId).show ();
			else
				$('#' + tabContentId).hide ();
		});
	});
};

e10client.prototype.pageTabsClick = function (e) {
	var activeTab = $('#e10-page-tabs>li.active');
	var activeTabId = activeTab.attr('id');
	$('#' + activeTabId + '-c').hide();
	activeTab.removeClass('active');

	var newTabId = e.attr('id');
	$('#' + newTabId + '-c').show();
	e.addClass('active');

	window.location.hash = '#' + newTabId.substr(newTabId.lastIndexOf('-') + 1);
};


e10client.prototype.loadPage = function (dataPath, successFunction, errorFunction) {
	var url = e10.appUrlRoot;
	if (dataPath[0] === '#')
		url += '?app=1';
	else
		url += dataPath + '?app=1';

	if (typeof g_initDataPath !== 'undefined' && window['g_UserInfo'] !== undefined && g_initDataPath !== '')
		url += '&embeddMode=1';

	if (e10.standaloneApp)
		url += '&standaloneApp='+e10.standaloneApp;

	e10.setProgress(1);

	e10.server.get (url, function (data) {
		$("#e10-page-body *").off();
		$("#e10-page-body").empty();
		$("#e10-page-body").html(data.object.htmlCode);
		$("#e10-page-body").attr('data-reload-path', dataPath);
		$("#e10-page-body").attr('data-page-type', data.object.pageInfo.pageType);
		e10.pageType = data.object.pageInfo.pageType;

		window.scrollTo(0, 0);

		e10.userSID = data.object.pageInfo.sessionId;
		httpOriginPath = data.object.pageInfo.httpOriginPath;
		g_uiTheme = data.object.pageInfo.guiTheme;

		if (window['webSocketServers'] === undefined)
			webSocketServers = data.object.pageInfo.wss;

		if (data.object.pageInfo.viewerScroll)
		{
			window.onscroll = function (ev) {
				e10.viewer.loadNextData(ev)
			};
			e10.viewerScroll = 1;
		}
		else
		{
			e10.viewerScroll = 0;
			window.onscroll = null;
		}
		if (data.object.pageInfo.userInfo)
			e10.userInfo = data.object.pageInfo.userInfo;

		if (successFunction !== undefined)
			successFunction ();

		e10.pageTabsInit();
		e10.refreshLayout();

		e10.setProgress(0);
	}, errorFunction);
};

e10client.prototype.refreshLayout = function () {
	var hh = 0;
	var pageHeader = $('#e10-page-header');
	if (pageHeader.length)
		hh = pageHeader.outerHeight();

	var fh = 0;
	var pageFooter = $('#e10-page-footer');
	if (pageFooter.length)
		fh = pageFooter.outerHeight();

	$('body').css('margin-top', hh);
	$('body').css('margin-bottom', fh);

	e10.widgetTabsInit();
};

e10client.prototype.doLink = function (e) {
	e.css("background-color", "rgba(255,0,0,.3)");

	if (e.attr('data-path') === '#')
		return;

	var url = '';
	if (e.attr('data-path') !== undefined) {
		this.loadPage(e.attr('data-path'));
		return;
	}
	else if (e.attr('data-file-url') !== undefined)
	{
		url = e.attr('data-file-url');
		var mimeType = e.attr('data-mime-type');
		e10.openFile (url, mimeType);
		return;
	}
	else if (e.attr('data-url') !== undefined)
	{
		url = e.attr('data-url');
	}
	else if (e.attr('data-oid') !== undefined) {
		url = e10.httpServerRoot + e10.appUrlRoot + e.attr('data-oid');
		url += '?op=' + httpOriginPath;
	}
	window.location.href = url;
};

e10client.prototype.openFile = function (url, mimeType)
{
	window.location.href = url;
};

e10client.prototype.setProgress = function (progress) {
	if (progress)
		e10.progressCount++;
	else
		e10.progressCount--;

	if (e10.progressCount)
		$('#e10-status-progress').addClass('active');
	else
		$('#e10-status-progress').removeClass('active');
};


e10client.prototype.collectFormData = function (form, isNextBlock) { // DEPRECATED
	var frmData = '';
	if (isNextBlock === undefined)
		frmData = "frmId=myForm&";
	else
		frmData = '&';

	var data = {};

	var formElements = form.find('input');
	for (var i = 0; i < formElements.length; i++) {
		var element = formElements [i];
		var type = element.type;
		if (type == "checkbox" || type == "radio") {
			if (element.checked)
				data[element.name] = element.value;
			continue;
		}
		data[element.name] = element.value;
	}
	formElements = form.find('select');
	for (var i = 0; i < formElements.length; i++) {
		var element = formElements [i];
		data[element.name] = element.value;
	}

	formElements = form.find('textarea');
	for (i = 0; i < formElements.length; i++) {
		var element = formElements [i];
		data[element.name] = element.value;
	}

	frmData += $.param(data);
	return frmData;
};


e10client.prototype.initPage = function () {
};


e10client.prototype.escapeHtml = function (str) {
	var div = document.createElement('div');
	div.appendChild(document.createTextNode(str));
	return div.innerHTML;
};


e10client.prototype.openDocument = function (e) {
	var pk = e.attr('data-pk');

	// -- check action
	var action = e10.searchParentAttr(e, 'data-rowaction');
	if (action)
	{
		var actionClass = e10.searchParentAttr(e, 'data-rowactionclass');

		if (action === 'call')
		{
			e10.executeFunctionByName (actionClass, e);
			return;
		}

		e10.setProgress(1);

		var url = e10.appUrlRoot;
		url += action;
		if (actionClass)
			url += '/' + actionClass;

		url += '/' + pk;
		url += '?app=1';

		e10.openModal();

		e10.server.get (url, function (data) {
			$("#e10-page-body").html(data.object.htmlCode);
			e10.refreshLayout();
			e10.setProgress(0);
		});

		return;
	}

	// -- default open mode
	e10.setProgress(1);
	e10.openModal();

	var table = e10.searchParentAttr(e, 'data-table');

	var url = '/api/d/'+table+'/'+pk;
	e10.g_formId++;
	var newElementId = "docPopup" + e10.g_formId;

	e10.server.get (url, function (data) {
		e10.createDocument(data, newElementId);
		e10.setProgress(0);
	});
};

e10client.prototype.reloadDetail = function (table, pk) {
	e10.closeModal();

	e10.setProgress(1);
	e10.openModal();

	var url = '/api/d/'+table+'/'+pk;
	e10.g_formId++;
	var newElementId = "docPopup" + e10.g_formId;

	e10.server.get (url, function (data) {
		e10.createDocument(data, newElementId);
		e10.setProgress(0);
	});
};

e10client.prototype.createDocument = function (data, id) {
	var c = '';

	c += "<div id='e10-page-header' class='e10mui pageHeader docHeader'></div>";

	c += "<div class='e10-doc' id='" + id + "' data-object='document'>";
	c += "</div>";

	$("#e10-page-body").html(c);

	var tc = "";
	tc += "<span class='lmb e10-trigger-action' data-action='modal-close' id='e10-back-button'><i class='fa fa-times'></i></span>" +
			"<div class='pageTitle'><h1>"+data.codeTitle+"</h1>";
	if (data.codeSubTitle !== '')
		tc += "<span class='subTitle'>"+data.codeSubTitle+"</span>";

	tc += "</div>";

	if (e10.app) {
		tc += "<ul class='rb'>";
		tc += "<li class='e10-trigger-action' data-action='detail-add-photo' data-table='" + data.table + "' data-pk='" + data.pk + "'><i class='fa fa-camera'></i></li>";
		tc += "</ul>";
	}

	var toolbar = $('#e10-page-header');
	var form = $('#' + id);

	toolbar.html(tc);
	form.html(data.codeBody + data.codeFooter);

	e10.refreshLayout();

	if (window.MSApp)
	    $('img.e10-img-loading').each(function () { e10.imgLoaded($(this)) });

	return 0;
};


e10client.prototype.openModal = function () {
	var modalInfo = {
		scrollTop: $("body").scrollTop(),
		htmlCode: $("#e10-page-body").html(),
		viewerScroll: e10.viewerScroll
	};
	$("#e10-page-body *").off();
	$("#e10-page-body").empty();

	e10.viewerScroll = 0;
	window.onscroll = null;

	e10.openModals.push (modalInfo);
};


e10client.prototype.closeModal = function () {
	if (e10.openModals.length === 0)
		return;

	var modalInfo = e10.openModals.pop();

	$("#e10-page-body *").off();
	$("#e10-page-body").empty();

	$("#e10-page-body").html (modalInfo.htmlCode);

	e10.viewerScroll = modalInfo.viewerScroll;
	if (e10.viewerScroll)
	{
		window.onscroll = function (ev) {
			e10.viewer.loadNextData(ev)
		};
	}
	else
		window.onscroll = null;

	e10.refreshLayout();
	$("body").scrollTop(modalInfo.scrollTop);
};


e10client.prototype.imgLoaded = function (e) {
	e.removeClass ('e10-img-loading').prev().text('');
};

e10client.prototype.b64DecodeUnicode = function (str) {
	return decodeURIComponent(Array.prototype.map.call(atob(str), function(c) {
		return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
	}).join(''));
};

e10client.prototype.nf = function(n, c){
	var
			c = isNaN(c = Math.abs(c)) ? 2 : c,
			d = '.',
			t = ' ',
			s = n < 0 ? "-" : "",
			i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "",
			j = (j = i.length) > 3 ? j % 3 : 0;
	return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
};

e10client.prototype.parseFloat = function(n) {
	var str = n.replace (',', '.');
	return parseFloat(str);
};

e10client.prototype.round = function (value, decimals) {
	return Number(Math.round(value+'e'+decimals)+'e-'+decimals);
};

e10client.prototype.isPortrait = function() {
	return window.innerHeight > window.innerWidth;
};


e10client.prototype.screenResize = function () {
	if (e10.pageType === 'terminal')
		e10.terminal.refreshLayout();
};


e10client.prototype.keyDown = function (event, e) {
	if (e10.pageType === 'terminal' && !e10.disableKeyDown)
		return e10.terminal.keyDown(event, e);

	// -- disable backspace
	var element = event.target.nodeName.toLowerCase();
	if (element != 'input' && element != 'textarea') {
		if (event.keyCode === 8) {
			event.stopPropagation();
			event.preventDefault();
			return false;
		}
	}
};


e10client.prototype.workspaceLogin = function (e) {
	e10.form.getNumber (
			{
				title: 'Zadejte přístupový kód',
				srcElement: e,
				success: e10.workspaceLoginDoIt
			}
	);
};

e10client.prototype.workspaceLoginDoIt = function () {
	if (e10.app)
	{
		localStorage.setItem("userLogin", e10.form.gnOptions.srcElement.attr('data-login'));
		localStorage.setItem("userPin", e10.form.gnValue);

		var terminalURL = e10.options.get('terminalURL', '');
		var url = (terminalURL.substr(0, 6) === 'https:') ? terminalURL : 'https://' + terminalURL;

		document.location = "datasource.html?ds="+encodeURI(url)+'&standalone=0';
		return;
	}

	$('#e10-login-user').val (e10.form.gnOptions.srcElement.attr('data-login'));
	$('#e10-login-pin').val (e10.form.gnValue);
	document.forms['e10-mui-login-form'].submit();

	//alert ("NAZDAAAR: "+e10.form.gnOptions.srcElement.attr('data-login') + ' - ' + e10.form.gnValue);
};

e10client.prototype.executeFunctionByName = function (functionName/*, args */) {
	var context = window;
	var args = [].slice.call(arguments).splice(1);
	var namespaces = functionName.split(".");
	var func = namespaces.pop();
	for(var i = 0; i < namespaces.length; i++) {
		context = context[namespaces[i]];
	}
	return context[func].apply(this, args);
};


function e10nf (n, c){
	var
			c = isNaN(c = Math.abs(c)) ? 0 : c,
			d = ',',
			t = ' ',
			s = n < 0 ? "-" : "",
			i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "",
			j = (j = i.length) > 3 ? j % 3 : 0;
	return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
}

function e10sleep(milliseconds) {
	var start = new Date().getTime();
	for (var i = 0; i < 1e7; i++) {
		if ((new Date().getTime() - start) > milliseconds){
			break;
		}
	}
}

function e10WidgetInit (id)
{
	var w = $('#'+id);

	w.find ('div.e10-widget-iframe').each (function ()
	{
		$(this).parent().parent().height($(this).parent().parent().parent().height());
		$(this).height($(this).parent().parent().parent().height());
	});



	w.find ('div.e10-remote-widget').each (function ()
	{
		var id = $(this).attr ('id');
		e10.e10LoadRemoteWidget(id);
	});
}


function searchObjectAttr (e, attr)
{
	var p = e;
	while (p.length)
	{
		if (p.attr (attr))
			return p;

		p = p.parent ();
		if (!p.length)
			break;
	}

	return null;
}

function elementPrefixedAttributes (e, prefix, data)
{
	var iel = e.get(0);
	for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
	{
		var attrName = attrs.item(i).nodeName;
		if (attrName.substring(0, prefix.length) !== prefix)
			continue;
		var attrNameShort = attrName.substring(prefix.length);
		var val = attrs.item(i).nodeValue;
		data[attrNameShort] = val;
	}
}

