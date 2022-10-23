var e10 = null;

$(function () {
	e10 = new e10client();
	e10.init ();

	e10.refreshLayout();

	if (window['g_UserInfo'] !== undefined)
		e10.userInfo = g_UserInfo;

	e10.deviceId = e10.options.get ('deviceId', null);
	if (e10.deviceId === null)
	{
		for(var c = ''; c.length < 32;) c += Math.random().toString(36).substr(2, 1);
		e10.deviceId = c;
		e10.options.set ('deviceId', e10.deviceId);
	}

	e10.options.loadAppSettings();
	e10.server.setHttpServerRoot(httpApiRootPath);

	if (typeof g_initDataPath !== 'undefined' && window['g_UserInfo'] !== undefined && g_initDataPath !== '')
	{
		e10.loadPage(g_initDataPath);
	}

	e10.wss.init();

	if ('serviceWorker' in navigator && e10ServiceWorkerURL !== undefined) {
		navigator.serviceWorker.register(e10ServiceWorkerURL)
			.then(function(reg){
			}).catch(function(err) {
			console.log("Service worker registration error: ", err)
		});
	}
});


e10client.prototype.appLogout = function (dataPath, successFunction) {
	var url = e10.httpServerRoot+'/user/logout-check?m=1';
	window.location = url;
};


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

e10client.prototype.form.uploadFiles = function (form, table, pk) {
};



e10client.prototype.camera = {
	addPhotoTable: '',
	addPhotoPK: '',
	addPhotoInputId: ''
};

e10client.prototype.camera.takePhoto = function (e) {
};

e10client.prototype.camera.takeFile = function (e) {
};
