e10client.prototype.wss = {
	g_camerasBarTimer: 0
};


e10client.prototype.wss.init = function () {
	for (var i in webSocketServers)
	{
		e10.wss.start (i);
	}

	e10.wss.reloadCams ();
};


e10client.prototype.wss.start = function (serverIndex)
{
	var ws = webSocketServers[serverIndex];

	ws.server = null;
	ws.retryTimer = 0;

	if ("WebSocket" in window)
		ws.server = new WebSocket(ws.wsUrl);
	else
	if ("MozWebSocket" in window)
		ws.server = new MozWebSocket(ws.wsUrl);

	if (ws.server === null)
		return;

	ws.server.onopen = function() {e10.wss.setState (serverIndex, 'open');};
	ws.server.onerror = function(evt) {
		//console.log ('wss #'+serverIndex+' failed...');
		e10.wss.setState (serverIndex, 'error');
	};
	ws.server.onclose = function() {
		//console.log ('wss #'+serverIndex+' closed...');
		e10.wss.setState (serverIndex, 'close');
		ws.retryTimer = setTimeout (function (){e10.wss.start(serverIndex);}, 10000);
	};
	ws.server.onmessage = function(e) {e10.wss.onMessage (serverIndex, e.data);};
};


e10client.prototype.wss.setState = function (serverIndex, socketState)
{
	var ws = webSocketServers[serverIndex];
	var serverIcon = $('#wss-'+ws.id);
	serverIcon.attr('class','e10-wss e10-wss-'+socketState);
};


e10client.prototype.wss.onMessage = function (serverIndex, stringData)
{
	var data =  eval('(' + stringData + ')');

	var sensorId = data.sensorId;
	var elid = 'wss-' + webSocketServers[serverIndex].ndx + '-' + sensorId;
	var deviceBtn = $('#'+elid);
	if (!deviceBtn.length)
		return;

	if (data.cmd)
	{
		if (data.deviceId == e10.deviceId)
			return;
		if (data.cmd == 'lockSensor')
			deviceBtn.removeClass ('e10-sensor-on');
		if (data.cmd == 'unlockSensor')
			deviceBtn.addClass ('e10-sensor-on');
		return;
	}

	if (!deviceBtn.hasClass ('e10-sensor-on') && !deviceBtn.hasClass ('allwaysOn'))
		return;

	if (deviceBtn.attr ('data-call-function') !== undefined)
	{
		e10.executeFunctionByName (deviceBtn.attr ('data-call-function'), deviceBtn, data);
		return;
	}

	if (data.sensorClass == 'number')
	{
		var value = data.value;
		$('#e10-sensordisplay-'+sensorId).text (value);


		var form = $('body>div.e10-form');
		if (form.length != 0)
		{
			var receiveSensors = form.attr ('data_receivesensors');
			if (receiveSensors !== undefined)
			{
				var sids = receiveSensors.split (' ');
				for(i = 0; i < sids.length; i++)
					$('#'+sids[i]).text (value);
			}
		}

		if (e10.wss.g_camerasBarTimer !== 0)
		{
			clearTimeout (e10.wss.g_camerasBarTimer);
			e10.wss.g_camerasBarTimer = 0;
			e10.wss.reloadCams();
		}
	}
};


e10client.prototype.wss.reloadCams = function ()
{
	for (var si in webSocketServers)
	{
		var ws = webSocketServers[si];
		var camsList = ws.camList;
		var camUrl = ws.camerasURL;
		var urlPath = ws.camerasURL + "cams"+ "?callback=?";
		var jqxhr = $.getJSON(urlPath, function(data) {
			for (var ii in data)
			{
				var picFileName = camUrl + 'imgs/-w960/-q70/' + ii + "/" + data[ii];
				var origPicFileName = camUrl + '/imgs/' + ii + "/" + data[ii];

				$('#e10-cam-' + ii + '-right').attr ("src", picFileName).parent().attr ("data-pict", origPicFileName).attr ("data-pict-thumb", picFileName);
			}
			e10.wss.g_camerasBarTimer = setTimeout (e10.wss.reloadCams, 60000);
		});
	}
};
