
e10client.prototype.login = {
	//hostingDomain: 'sebik-my.shipard.pro',
	hostingDomain: 'me.shipard.com',
	portals: null
};


e10client.prototype.login.init = function () {
	e10.login.loadPortals();
};


e10client.prototype.login.loadPortals = function ()
{
	var fullUrl = 'https:/'+e10.login.hostingDomain+'/info-portals';
	var options = {
		type: "GET",
		url: fullUrl,
		success: function (data) {
			e10.login.portals = data;
			e10.initPage ();
		},
		error: function (data) {
			console.log ("ERROR");
		}
	};

	$.ajax(options);
};


e10client.prototype.login.resetLoginScreen = function () {
	var c = '';

	// -- tabs
	var portalNdx;
	c += "<ul id='e10-login-tabs' class='e10-page-tabs'>";
	for (portalNdx in e10.login.portals.portals)
	{
		var portal = e10.login.portals.portals[portalNdx];
		if (portal['portalLoginTabTitle'] === '')
			continue;
		c += "<li id='e10-login-tab-"+portal['ndx']+"'>"+e10.escapeHtml(portal['portalLoginTabTitle'])+"</li>";
	}

	c += "<li id='e10-login-tab-help'>"+e10.escapeHtml('Nápověda')+"</li>";
	c += "</ul>";

	// -- contents
	for (portalNdx in e10.login.portals.portals)
	{
		var portal = e10.login.portals.portals[portalNdx];
		if (portal['portalLoginTabTitle'] === '')
			continue;

		c += "<div class='e10app-login-tab-content' id='e10-login-tab-"+portal['ndx']+"-tab' style='display: none;'>";

		if (portal.hasLoginUsers)
		{
			c += "<h3 style='padding-bottom: .7rem;'>"+e10.escapeHtml("Vyberte si uživatele, pod kterým se chcete přihlásit")+"</h3>";
			for (var i = 0; i < portal.loginUsers.length; i++)
			{
				var user = portal.loginUsers[i];
				var userImgUrl = 'https://'+portal.portalDomain+'/imgs/-h128/att/' + user.img.fileName;

				c += "<button type='submit' class='e10app-login-user-button btn btn-default' "+
					"data-portal-id='"+portalNdx+"'" + "data-user-login='"+e10.escapeHtml(user.login)+"'"+">";

				c += "<img style='width: 4rem; float: left; padding-right: 1rem;' src="+userImgUrl+">";

				c += "<h3>"+e10.escapeHtml(user.fullName)+"</h3>";

				var userDesc = '';
				for (var x = 0; x < user.properties['e10demo-user']['e10demo-user-desc'].length; x++)
					userDesc += user.properties['e10demo-user']['e10demo-user-desc'][x]['value'] + ' ';
				c += "<span>"+e10.escapeHtml(userDesc)+"</span>";

				c += "</button>";
			}
		}
		else
		{
			c += "<div class='loginMessage' style='display: none;'></div>";

			c += "<label for='userLogin-"+portalNdx+"'>E-mail</label>";
			c += "<input type='email' class='e10app-login-email form-control col-xs-10' id='userLogin-"+portalNdx+"' name='login-"+portalNdx+"' value='' autofocus='autofocus'/>";
			c += "<label for='userPassword-"+portalNdx+"'>Heslo</label>";
			c += "<input type='password' class='e10app-login-password form-control col-xs-10' id='userPassword-"+portalNdx+"'	name='password-"+portalNdx+"' value=''/>";

			c += "<div class='e10app-login-do-buttons'>";
			c += "<button type='submit' id='loginButton-"+portalNdx+"' class='e10app-login-do-button btn btn-primary' " +
					"data-portal-id='"+portalNdx+"'" +
					"name='loginButton-"+portalNdx+"'>Přihlásit</button>";
			c += "</div>";
		}

		c += "</div>";
	}

	// -- help
 	c += "<div class='e10app-login-tab-content' id='e10-login-tab-help-tab' style='display: none;'>";
	c += "<b>Pro aktivaci nové databáze nás prosím kontaktujte:</b>";
	c += "<ul>";
	c += "<li>telefonicky na čísle <a href='tel:+420777070787'>+420&nbsp;777&nbsp;070&nbsp;787</a></li>";
	c += "<li>emailem: <a href='mailto:podpora@shipard.cz'>podpora@shipard.cz</a></li>";
	c += "<li>webovým formulářem na <a href='https://shipard.cz/kontakty' target='_new'>shipard.cz/kontakty</a></li>";
	c += "</ul>";
	c += "<br>Více informací o EET najdete na <a href='https://shipard.cz/eet'>shipard.cz/eet</a>.";
	c += "<br><br><b>Děkujeme.</b>";
	c += "<a class='imglink' href='https://shipard.cz' target='_blank'><img class='shipard' src='https://shipard.com/att/2017/02/26/e10pro.wkf.documents/shipardlogocolor-10w1m28.svg'/></a>";
	c += "<a class='imglink' href='https://uctarna.online' target='_blank'><img class='uctarna' src='https://shipard.com/att/2017/02/27/e10pro.wkf.documents/uctarnalogocolorfull-vddbgd.svg'/></a>";
 	c += "</div>";

	$('#loginForm').html(c);


	$('#loginPageTitleContainer').hide();

	e10.login.activateTab($('#e10-login-tabs>li:first'));

	$('body').css('margin-top', 0);
	$('#e10-page-header').addClass('nonfixed');
	$('#loginPageTitle').text ('Přihlášení');
	$('#loginForm').show();

	$('#e10-login-tabs').on(e10.CLICK_EVENT, "li", function (event) {
		e10.login.activateTab($(this));
	});

	$("button.e10app-login-do-button, button.e10app-login-user-button").on(e10.CLICK_EVENT, function (event) {
		e10.login.login($(this));
	});

	$('body').css('margin-top', 0);
};

e10client.prototype.login.activateTab = function (e) {
	var activeTab = $('#e10-login-tabs>li.active');
	if (activeTab.length)
	{
		var activeTabId = activeTab.attr('id');
		$('#'+activeTabId+'-tab').hide();
		activeTab.removeClass ('active');
	}

	var activeTabId = e.attr('id');
	$('#'+activeTabId+'-tab').show();
	e.addClass ('active');
};

e10client.prototype.login.login = function (e) {
	var userLogin = '';
	var userPassword = '';

	var portalId = e.attr('data-portal-id');

	var portal = e10.login.portals.portals[portalId];
	localStorage.setItem("portalInfo", JSON.stringify(portal));

	if (e.attr('data-user-login')) {
		userLogin = e.attr('data-user-login');
		userPassword = atob ('dGFqbsOpIGhlc2xvIGNvIG5pa2RvIG5lem7DoQ==');
	}
	else
	{
		userLogin = $('#userLogin-' + portalId).val();
		userPassword = $('#userPassword-' + portalId).val();
	}

	loadDataSources (userLogin, userPassword);
};
