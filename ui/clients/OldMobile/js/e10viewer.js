e10client.prototype.viewer = {};

e10client.prototype.viewer.refresh = function (viewer) {
	e10.viewer.appendRows(viewer, 0);
};


e10client.prototype.viewer.loadNextData = function (event) {
	var viewerId = $('body div.e10-viewer').attr('id');
	var viewer = $('#' + viewerId);

	if (viewer.attr('data-loadonprogress') && viewer.attr('data-loadonprogress') != 0)
		return;

	var scrollTop = (document.documentElement && document.documentElement.scrollTop) || document.body.scrollTop;
	var scrolledToBottom = (scrollTop + window.innerHeight) >= document.body.scrollHeight - 300;

	if (scrolledToBottom) {
		viewer.attr('data-loadonprogress', 1);
		e10.viewer.appendRows(viewer, 1);
	}
};

e10client.prototype.viewer.loadNextDataCombo = function (event) {
	var viewerForm = $('#' + e10.form.cvId);
	var viewerContent = $('#e10-form-combo-viewer-content');
	var viewer = viewerContent.find('>div.e10-viewer');
	var viewerList = viewer.find ('>ul.e10-viewer-list');

	if (viewer.attr ('data-loadonprogress') && viewer.attr ('data-loadonprogress') != 0)
		return;

	var heightToEnd = viewerList[0].scrollHeight - (viewerList.scrollTop() + viewerList.height ());
	if (heightToEnd <= 150)
	{
		viewer.attr ('data-loadonprogress', 1);
		e10.viewer.appendRows(viewer, 1);
	}
};

e10client.prototype.viewer.appendRows = function (viewer, appendLines) {
	//var appendLines = 1;

	var tableName = viewer.attr("data-table");
	if (!tableName)
		return;

	var viewerOptions = viewer.attr("data-viewer-view-id");

	var urlPath = '';
	var rowsPageNumber = 0;
	if (appendLines)
		rowsPageNumber = parseInt(viewer.attr('data-rowspagenumber')) + 1;
	else
		viewer.attr('data-rowspagenumber', 0);

	urlPath = '/api/viewer/' + tableName + '/' + viewerOptions + '/html' + "?mobile=1&rowsPageNumber=" + rowsPageNumber;
	var queryParams = viewer.attr("data-queryparams");
	if (queryParams)
		urlPath += '&' + queryParams;

	e10.viewer.fillRows(viewer, urlPath, appendLines);
};


e10client.prototype.viewer.fillRows = function (viewer, url, appendLines) {
	var viewerId = viewer.attr('id');

	viewer.attr('data-loadonprogress', 1);
	e10.setProgress(1);

	var formPostData = e10.collectFormData (viewer);

	e10.server.postForm(url, formPostData, function (data) {
		var viewerLines = $('#' + viewerId + 'Items');

		var rowElement = 'li';//viewerLines.attr ('data-rowelement');
		if (appendLines) {
			if (rowElement === 'tr') {
				viewerLines.find(">table tbody tr:last-child").detach();
				viewerLines.find('>table tbody').append(data.object.htmlItems);
				viewerLines.find('>table.dataGrid.main').floatThead('reflow');
			}
			else {
				viewerLines.find('>' + rowElement + ":last-child").detach();
				var currCnt = viewerLines.find(rowElement).length;
				viewerLines.append(data.object.htmlItems);
			}
		}
		else {
			if (rowElement === 'tr') {
				viewerLines.find('>table tbody').html(data.object.htmlItems);
				viewerLines.find('>table.dataGrid.main').floatThead('reflow');
			}
			else {
				viewerLines.empty();
				viewerLines.html(data.object.htmlItems);
			}
			//viewerLines.scrollTop (0);
		}
		viewer.attr('data-rowspagenumber', data.object.rowsPageNumber);
		viewer.attr('data-loadonprogress', 0);

		e10.setProgress(0);
	});//.error(function() {alert("error TTTRRR22: content not loaded (" + url + ")");});
};


var g_incSearchTimer = 0;

e10client.prototype.viewer.incSearch = function (input, event) {

	var viewer = null;
	if (input.attr ('data-combo'))
	{
		var viewerForm = $('#' + e10.form.cvId);
		var viewerContent = $('#e10-form-combo-viewer-content');
		viewer = viewerContent.find('>div.e10-viewer');
	}
	else
		viewer = input.parent().parent();

	var thisVal = input.val();

	if (input.attr ('data-lastvalue') && input.attr ('data-lastvalue') == thisVal)
		return;

	if (event && event.type == 'keyup')
	{
		if (!input.attr ('data-lastvalue') && thisVal == '')
			return;

		if (event.keyCode == 38 || event.keyCode == 40 || event.keyCode == 13)
			return;
	}

	if (viewer.attr ('data-loadonprogress') && viewer.attr ('data-loadonprogress') != 0)
	{
		g_incSearchTimer = setTimeout (function () {e10.viewer.incSearch (input)}, 100);
		return;
	}

	if (g_incSearchTimer)
	{
		clearTimeout (g_incSearchTimer);
		g_incSearchTimer = 0;
	}

	input.attr ('data-lastvalue', thisVal);
	viewer.attr ('data-loadonprogress', 1);
	e10.viewer.refresh (viewer);
};


e10client.prototype.viewer.search = function (e) {
	var viewer = $('#e10-page-body>div.e10-viewer');
	var searchBox = viewer.find ('>div.e10-viewer-search');
	var input = searchBox.find ('>input.e10-inc-search');

	if (searchBox.hasClass('off'))
	{
		e.addClass('active');
		var hh = $('#e10-page-header').outerHeight()|0;
		searchBox.removeClass('off').addClass('on');
		searchBox.css('top', hh+'px');
		input.focus();
		$('body').css('margin-top', hh+searchBox.outerHeight()|0);
	}
	else
	{
		e.removeClass('active');
		input.val('');
		searchBox.removeClass('on').addClass('off');
		$('body').css('margin-top', $('#e10-page-header').height());
	}
};

e10client.prototype.viewer.bottomTabsClick = function (e) {
	var tabs = e.parent();
	var activeTab = tabs.find('>li.active');
	activeTab.removeClass('active');

	e.addClass('active');
	var viewer = $('#e10-page-body>div.e10-viewer');
	var input = viewer.find (">div.e10-viewer-search>input[name=bottomTab]");
	input.val (e.attr('data-id'));
	e10.viewer.refresh (viewer);
};
