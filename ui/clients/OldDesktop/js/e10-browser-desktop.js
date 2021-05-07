var e10 = null;


e10client.prototype.systemInfo = function (withIds) {

	if (withIds)
	{
		var info = {};
		info['appVersion'] = e10.appVersion;
		info['userAgent'] = navigator.userAgent;
		return info;
	}

	var info = [];
	info.push({title: 'ID zařízení', value: e10.deviceId});
	info.push({title: 'Prohlížeč', value: navigator.userAgent});

	return info;
};

$(function () {
	e10 = new e10client();
	e10.clientType = 'browser.desktop.html5';
	e10.server.setHttpServerRoot(httpApiRootPath);

	e10jsinit ();

	var mainBrowser = $("#mainBrowser");
	if (mainBrowser.length)
		mainBrowserInit ('mainBrowser');
	/*
	e10.init ();
	e10.wss.init();
	*/
});
