
function initLanAdminReloadDashboard()
{
	let mbc = $('#mainBrowserContent');
	if (!mbc.length)
		return;

	if (mbc.attr('data-lan-admin-dashboard') !== undefined)
		return;

	mbc.attr('data-lan-admin-dashboard', '1');
	lanAdminReloadDashboard();
}

function lanAdminReloadDashboard()
{
	for(let dsNdx in lanAdminDataSources)
	{
		let ds = lanAdminDataSources[dsNdx];
		let dsX = dsNdx;
		lanAdminSetLoading(dsX);
		var urlPath = ds['dsUrl']+"/api/objects/call/mac-lan-alerts-download";
		e10.server.get (urlPath, function(data) {lanAdminSetDSBadges(dsX, data);}, function (jqXHR, textStatus, errorThrown){lanAdminSetError(dsX, textStatus);});
	}

	setTimeout(lanAdminReloadDashboard, 60000);
}

function lanAdminSetDSBadges(dsNdx, data)
{
	let tabElement = $('#e10-lanadmin-dstab-'+dsNdx);
	tabElement.find('>div.badges>span.badges').html(data['globalBadges']);
	tabElement.find('>div.badges>span.status').addClass('e10-success').removeClass('e10-error').removeClass('e10-me');
}

function lanAdminSetError(dsNdx, textStatus)
{
	let tabElement = $('#e10-lanadmin-dstab-'+dsNdx);
	tabElement.find('>div.badges>span.badges').text(textStatus);
	tabElement.find('>div.badges>span.status').addClass('e10-error').removeClass('e10-success').removeClass('e10-me');
}

function lanAdminSetLoading(dsNdx)
{
	let tabElement = $('#e10-lanadmin-dstab-'+dsNdx);
	tabElement.find('>div.badges>span.status').removeClass('e10-error').removeClass('e10-success').addClass('e10-me');
}


initLanAdminReloadDashboard();

