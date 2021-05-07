e10client.prototype.widgets.lans = {
	widgetTimer: 0,
	widgetId: ''
};


e10client.prototype.widgets.lans.init = function (elementId) {
	e10.widgets.lans.widgetId = elementId;

	e10.widgets.lans.reloadLive();
};


e10client.prototype.widgets.lans.reloadLive = function ()
{
	if (e10.widgets.lans.widgetTimer) {
		clearTimeout(e10.widgets.lans.widgetTimer);
	}
	var widgetElement = $('#'+e10.widgets.lans.widgetId);
	if (!widgetElement.length)
	{
		e10.widgets.lans.widgetTimer = 0;
		return;
	}

	var urlPath = "/api/objects/call/lan-info-download";
	e10.server.get (urlPath, function(data) {
		var cntSuccess = 0;

		for (var ii in data.lanInfo.devices) {
			var deviceStatus = data.lanInfo.devices[ii];
			if (e10.widgets.lans.setDeviceStatus(ii, deviceStatus))
				cntSuccess++;
		}
		e10.widgets.lans.widgetTimer = setTimeout(e10.widgets.lans.reloadLive, 30000);
	});
};

e10client.prototype.widgets.lans.setDeviceStatus = function (deviceNdx, deviceStatus)
{
	var cntSuccess = 0;
	var allUp = 1;
	var allDown = 1;
	var parentRow = null;
	var widget = $('#'+e10.widgets.lans.widgetId);

	var deviceIsUp = 0;
	var device = $('#'+e10.widgets.lans.widgetId+'-'+deviceNdx);

	for (var a in deviceStatus.addr)
	{
		var addr = deviceStatus.addr[a];

		for (var i = 0; i < addr.rts.length; i++)
		{
			var rts = addr.rts[i];

			if (i === 0 && rts.up)
				deviceIsUp = 1;

			var dataId = 'd'+deviceNdx + '-' + addr.ip + '-' + i;
			var statusElement = widget.find('span[data-rt-id="' + dataId + '"]');
			if (!statusElement.length)
				continue;

			if (rts.up) {
				if (i === 0)
					deviceIsUp = 1;

				allDown = 0;
				statusElement.html("<i class='fa fa-check fa-fw'></i>").prop('title', rts.title);
			}
			else {
				allUp = 0;
				statusElement.html("<i class='fa fa-times fa-fw'></i>").prop('title', rts.title);
			}

			parentRow = statusElement.parent().parent();
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
	}

	if (device.length)
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
