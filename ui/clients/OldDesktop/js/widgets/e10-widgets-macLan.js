e10client.prototype.widgets.macLan = {
	widgetTimer: 0,
	alertsTimer: 0,
	badgesTimer: 0,
	widgetId: '',
	devicesIPStates: []
};


e10client.prototype.widgets.macLan.init = function (elementId) {
	e10.widgets.macLan.widgetId = elementId;

	e10.widgets.macLan.reloadLive();
	e10.widgets.macLan.reloadAlerts();

	if (e10.widgets.macLan.badgesTimer) {
		clearTimeout(e10.widgets.macLan.badgesTimer);
	}

	e10.widgets.macLan.badgesTimer = setTimeout(e10.widgets.macLan.reloadBadges, 10000);
};


e10client.prototype.widgets.macLan.reloadLive = function ()
{
	if (e10.widgets.macLan.widgetTimer) {
		clearTimeout(e10.widgets.macLan.widgetTimer);
	}
	var widgetElement = $('#'+e10.widgets.macLan.widgetId);
	if (!widgetElement.length)
	{
		e10.widgets.macLan.widgetTimer = 0;
		return;
	}

	var urlPath = "/api/objects/call/mac-lan-info-download";
	e10.server.get (urlPath, function(data) {
		var cntSuccess = 0;
		for (var rr in data.lanInfo.ranges)
		{
			var range = data.lanInfo.ranges[rr];
			for (var ii in range) {
				var deviceStatus = range[ii];

				var deviceNdx = deviceStatus['d'];
				var deviceIP = deviceStatus['ip'];
				var up = deviceStatus['rts'][0]['up'];

				if (!e10.widgets.macLan.devicesIPStates.hasOwnProperty(deviceNdx))
					e10.widgets.macLan.devicesIPStates[deviceNdx] = [];

				e10.widgets.macLan.devicesIPStates[deviceNdx][deviceIP] = up;

				var ipId = rr+'-'+ii.split('.').join('-');
				if (e10.widgets.macLan.setDeviceStatus(ipId, deviceStatus))
					cntSuccess++;
			}
		}

		e10.widgets.macLan.setOverviewDevices();

		e10.widgets.macLan.widgetTimer = setTimeout(e10.widgets.macLan.reloadLive, 30000);
	});
};

e10client.prototype.widgets.macLan.setDeviceStatus = function (ipId, deviceStatus)
{
	var cntSuccess = 0;
	var allUp = 1;
	var allDown = 1;
	var parentRow = null;
	var widget = $('#'+e10.widgets.macLan.widgetId);

	var deviceIsUp = 0;

	var ipElement = $('#'+e10.widgets.macLan.widgetId+'-ip-'+ipId);
	ipElement.addClass('e10-error');
	if (!ipElement.length)
		return 0;

	var ifaceElement = ipElement.parent();

	for (var i = 0; i < deviceStatus.rts.length; i++)
	{
		var rts = deviceStatus.rts[i];

		if (i === 0 && rts.up)
			deviceIsUp = 1;

		var dataId = 'r' + ipId + '-' + i;
		var flagElement = ifaceElement.find('.e10-lans-rt-flags').find('span[data-rt-id="' + dataId + '"]');
		if (!flagElement.length) {
			continue;
		}

		if (rts.up) {
			if (i === 0)
				deviceIsUp = 1;

			allDown = 0;
			flagElement.html("<i class='fa fa-check fa-fw'></i>").prop('title', rts.title);
		}
		else {
			allUp = 0;
			flagElement.html("<i class='fa fa-times fa-fw'></i>").prop('title', rts.title);
		}

		parentRow = flagElement.parent().parent();
		if (i === 0) {
			parentRow.find('.e10-lans-rt-info').text(rts.title);
		}

		cntSuccess++;
	}

	if (parentRow)
	{
		if (allUp)
		{
			parentRow.find('td.ip').removeClass('e10-row-stop e10-row-pause').addClass('e10-row-play');
		} else
		if (allDown)
		{
			parentRow.find('td.ip').removeClass('e10-row-play e10-row-pause').addClass('e10-row-stop');
		}
		else
			parentRow.find('td.ip').removeClass('e10-row-play e10-row-stop').addClass('e10-row-pause');
	}

	var device = ipElement.parent().parent().parent();
	if (device.length && device.hasClass('e10-lans-device'))
	{
		if (deviceIsUp)
		{
			device.removeClass('e10-ld-off').addClass('e10-ld-on');
		} else
		{
			device.removeClass('e10-ld-on').addClass('e10-ld-off');
		}
	}

	return cntSuccess;
};

e10client.prototype.widgets.macLan.setOverviewDevices = function () {
	for (var deviceNdx in e10.widgets.macLan.devicesIPStates)
	{
		var deviceInfo = e10.widgets.macLan.devicesIPStates[deviceNdx];
		var isUp = 0;
		for (var ipIdx in deviceInfo)
		{
			var ipIsUp = deviceInfo[ipIdx];
			isUp += ipIsUp;
		}

		var indicatorId = '#e10-lan-do-'+deviceNdx;
		var indicatorElement = $(indicatorId);

		if (!indicatorElement.length) {
			continue;
		}
		if (isUp)
			indicatorElement.removeClass('e10-error').addClass('e10-success');
		else
			indicatorElement.removeClass('e10-success').addClass('e10-error');

		indicatorElement.parent().parent().attr('data-device-state', isUp);
	}

	e10.widgets.macLan.setOverviewBadges();
};

e10client.prototype.widgets.macLan.setOverviewBadges = function () {
	var overviewElement = $('#e10-lan-overview');
	if (!overviewElement.length)
		return;

	var tabs = overviewElement.find('ul.e10-static-tabs');
	var content = overviewElement.find('div.e10-static-tab-content');

	tabs.find ('>li').each (function () {
		var cntErrors = e10client.prototype.widgets.macLan.setOverviewBadgesGroup($(this).attr('data-content-id'));
		$(this).attr ("data-cnt-errors", cntErrors);
		var badge = $(this).find('>span.e10-ntf-badge');
		if (cntErrors)
		{
			badge.text(cntErrors).show();
		}
		else
		{
			badge.hide();
		}

	});
};

e10client.prototype.widgets.macLan.setOverviewBadgesGroup = function (groupId) {
	var cntErrors = 0;
	var contentElement = $('#'+groupId);
	contentElement.find ('>table>tbody>tr').each (function () {
		var state = $(this).attr ("data-device-state");
		if (state !== undefined)
		{
			if (parseInt(state) === 0)
				cntErrors++;
		}
	});

	return cntErrors;
};

e10client.prototype.widgets.macLan.reloadBadges = function () {
	var overviewElement = $('#e10-lan-overview');
	if (!overviewElement.length)
		return;

	overviewElement.find ('div>table>tbody>tr>td img.e10-auto-reload').each (function () {
		var idd = new Date().valueOf();
		var url = $(this).attr('data-src') + '?xyz='+idd;
		$(this).attr('src', url);
	});

	var alertsElement = $('#e10-lan-alerts');
	alertsElement.find ('>div img.e10-auto-reload').each (function () {
		var idd = new Date().valueOf();
		var url = $(this).attr('data-src') + '?xyz='+idd;
		$(this).attr('src', url);
	});

	e10.widgets.macLan.badgesTimer = setTimeout(e10.widgets.macLan.reloadBadges, 20000);
};

e10client.prototype.widgets.macLan.reloadAlerts = function () {
	if (e10.widgets.macLan.alertsTimer) {
		clearTimeout(e10.widgets.macLan.alertsTimer);
	}

	var urlPath = "/api/objects/call/mac-lan-alerts-download";
	e10.server.get (urlPath, function(data) {
		e10.widgets.macLan.setAlerts(data);
	});

	e10.widgets.macLan.alertsTimer = setTimeout(e10.widgets.macLan.reloadAlerts, 65000);
};

e10client.prototype.widgets.macLan.setAlerts = function (data) {
	var alertsElement = $('#e10-lan-alerts');

	alertsElement.find('>div').each (function () {
		var scopeId = $(this).attr('data-scope-id');

		var scopeElement = $('#e10-lan-alerts-'+scopeId);
		var scopeContentElement = scopeElement.find('details>div.content');
		var scopeBadgesElement = scopeElement.find('details>summary');

		if (!data.hasOwnProperty('lanAlerts') || data['lanAlerts']['scopes'] == null || data['lanAlerts']['scopes'][scopeId] === undefined)
		{
			scopeBadgesElement.html('');
			scopeContentElement.html('');
			scopeBadgesElement.parent().hide();
			return;
		}

		var scope = data.lanAlerts.scopes[scopeId];
		scopeBadgesElement.html(scope['badges']);
		scopeContentElement.html(scope['content']);
		scopeBadgesElement.parent().show();
	});
};