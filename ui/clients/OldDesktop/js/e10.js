
/**
 * jQuery.ScrollTo - Easy element scrolling using jQuery.
 * Copyright (c) 2007-2009 Ariel Flesler - aflesler(at)gmail(dot)com | http://flesler.blogspot.com
 * Dual licensed under MIT and GPL.
 * Date: 5/25/2009
 * @author Ariel Flesler
 * @version 1.4.2
 *
 * http://flesler.blogspot.com/2007/10/jqueryscrollto.html
 */
;(function(d){var k=d.scrollTo=function(a,i,e){d(window).scrollTo(a,i,e)};k.defaults={axis:'xy',duration:parseFloat(d.fn.jquery)>=1.3?0:1};k.window=function(a){return d(window)._scrollable()};d.fn._scrollable=function(){return this.map(function(){var a=this,i=!a.nodeName||d.inArray(a.nodeName.toLowerCase(),['iframe','#document','html','body'])!=-1;if(!i)return a;var e=(a.contentWindow||a).document||a.ownerDocument||a;return d.browser.safari||e.compatMode=='BackCompat'?e.body:e.documentElement})};d.fn.scrollTo=function(n,j,b){if(typeof j=='object'){b=j;j=0}if(typeof b=='function')b={onAfter:b};if(n=='max')n=9e9;b=d.extend({},k.defaults,b);j=j||b.speed||b.duration;b.queue=b.queue&&b.axis.length>1;if(b.queue)j/=2;b.offset=p(b.offset);b.over=p(b.over);return this._scrollable().each(function(){var q=this,r=d(q),f=n,s,g={},u=r.is('html,body');switch(typeof f){case'number':case'string':if(/^([+-]=)?\d+(\.\d+)?(px|%)?$/.test(f)){f=p(f);break}f=d(f,this);case'object':if(f.is||f.style)s=(f=d(f)).offset()}d.each(b.axis.split(''),function(a,i){var e=i=='x'?'Left':'Top',h=e.toLowerCase(),c='scroll'+e,l=q[c],m=k.max(q,i);if(s){g[c]=s[h]+(u?0:l-r.offset()[h]);if(b.margin){g[c]-=parseInt(f.css('margin'+e))||0;g[c]-=parseInt(f.css('border'+e+'Width'))||0}g[c]+=b.offset[h]||0;if(b.over[h])g[c]+=f[i=='x'?'width':'height']()*b.over[h]}else{var o=f[h];g[c]=o.slice&&o.slice(-1)=='%'?parseFloat(o)/100*m:o}if(/^\d+$/.test(g[c]))g[c]=g[c]<=0?0:Math.min(g[c],m);if(!a&&b.queue){if(l!=g[c])t(b.onAfterFirst);delete g[c]}});t(b.onAfter);function t(a){r.animate(g,j,b.easing,a&&function(){a.call(this,n,b)})}}).end()};k.max=function(a,i){var e=i=='x'?'Width':'Height',h='scroll'+e;if(!d(a).is('html,body'))return a[h]-d(a)[e.toLowerCase()]();var c='client'+e,l=a.ownerDocument.documentElement,m=a.ownerDocument.body;return Math.max(l[h],m[h])-Math.min(l[c],m[c])};function p(a){return typeof a=='object'?a:{top:a,left:a}}})(jQuery);


// http://jqueryminute.com/set-focus-to-the-next-input-field-with-jquery/
$.fn.focusNextInputField = function() {
    return this.each(function() {
        var fields = $(this).parents('form:eq(0),body').find('button,input,textarea,select');
        var index = fields.index( this );
        if ( index > -1 && ( index + 1 ) < fields.length ) {
            fields.eq( index + 1 ).focus();
        }
        return false;
    });
};

var CLICK_EVENT = 'click';
var googleMapsApi = 0;

// var httpApiRootPath = "/some/path"; - define in page <head> section

jQuery.fn.selText = function() {
	var doc = document, text = this[0], range, selection;
	if (doc.body.createTextRange) { //ms
		range = doc.body.createTextRange();
		range.moveToElementText(text);
		range.select();
	} else if (window.getSelection) { //all others
		selection = window.getSelection();
		range = doc.createRange();
		range.selectNodeContents(text);
		selection.removeAllRanges();
		selection.addRange(range);
	}
	return this;
};

Number.prototype.formatMoney = function(c, d, t){
	var n = this, c = isNaN(c = Math.abs(c)) ? 2 : c, d = d == undefined ? "," : d, t = t == undefined ? "." : t, s = n < 0 ? "-" : "", i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "", j = (j = i.length) > 3 ? j % 3 : 0;
	return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
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

function Round(num, precision){
	var val = precision!=undefined?Math.pow(10, precision):1;
	return Math.round(num*val)/val;
}

function Ceil(num, precision){
	var val = precision!=undefined?Math.pow(10, precision):1;
	return Math.ceil(num*val)/val;
}

function getFloatInputValue (i)
{
	var stringValue = i.val ();
	if (stringValue)
		stringValue = stringValue.replace (",", ".");
	else
		stringValue = '0';
	var floatValue = parseFloat (stringValue);
	return floatValue;
}

function searchParentAttr (e, attr)
{
  var p = e;
  while (p.length)
  {
    var attrValue = p.attr (attr);
    if (p.attr (attr))
      return p.attr (attr);

    p = p.parent ();
    if (!p.length)
      break;
  }
  return null;
}


function searchParentAttrElement (e, attr)
{
	var p = e;
	while (p.length)
	{
		var attrValue = p.attr (attr);
		if (p.attr (attr))
			return p;

		p = p.parent ();
		if (!p.length)
			break;
	}
	return null;
}


function searchObjectId (e, objectType)
{
  var p = e;
  while (p.length)
  {
    var ot = p.attr ('data-object');
    if (ot === objectType)
      return p.attr ('id');

    p = p.parent ();
    if (!p.length)
      break;
  }

  return '';
}

function elementAttributes (e, prefix)
{
	var data = {};

	var iel = e.get(0);
	for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
	{
		var attrName = attrs.item(i).nodeName;
		if (attrName.substring(0, prefix.length) !== prefix)
			continue;
		var val = attrs.item(i).nodeValue;
		data[attrName] = val;
	}

	if ($.isEmptyObject(data))
		return null;

	return $.param(data);
}

function dfMaximizeMainElement (aElementId)
{
	$("html,body").css ({"overflow": "hidden"});
	contentElement = document.getElementById (aElementId);
	var newHeight = $(window).height() - contentElement.offsetTop;
  contentElement.style.height = newHeight + 'px';
  return newHeight;
}


function testInputType (type)
{
	var el = document.createElement('input'), notATypeValue = 'not-a-'+type;
	el.setAttribute('type', type);
	el.setAttribute('value', notATypeValue);
	return !(el.value === notATypeValue);
}

// -----------
// uiHooks
// -----------

var e10_uiHooks = Array ();
function addUiHook (eventName, table, func)
{
	e10_uiHooks.push ({"eventName": eventName, "table": table, "func": func});
};


function callUiHook (eventName, table, e)
{
	for (var i = 0; i < e10_uiHooks.length; i++)
	{
		var hook = e10_uiHooks [i];
		//alert ('QQ: ' + hook ["eventName"] + ' t: ' + hook ["table"] + ' / ' + table);
		if ((hook ["eventName"] == eventName) && (hook ["table"] == table))
		{
			if (!hook ["func"].call (e))
				return false;
			//alert ("START!");
			return true;
		}
	}
	return false;
}

// -----------

function attLinkPreviewOpen (e)
{
	var mainBrowser = $('#mainBrowser');
	var xc = e.offset().left;
	var leftPos = '5vw';
	if (xc < mainBrowser.width() / 2)
		leftPos = '50vw';

	var urlPreview = e.parent().attr('data-url-preview');

	var previewHtml =
					"<div id='e10-link-preview' class='' style='left: "+leftPos+";'>" +
					"<img src='"+urlPreview+"'/>"+
					"</div>"
			;

	var mainBrowserContent = $('#mainBrowserContent');
	$('body').append (previewHtml);
}

function attLinkPreviewClose (e)
{
	$('#e10-link-preview').detach();
}

// -----------
// mainBrowser
// -----------

function mainBrowserInit (id)
{
	// -- disable backspace key
	$(document).keydown(function(e) {
	var element = e.target.nodeName.toLowerCase();
	if (element != 'input' && element != 'textarea') {
			if (e.keyCode === 8) {
					return false;
			}
	}
	});

	//if (e10ClientType [1] != 'desktop')
	//	CLICK_EVENT = 'touchend';

	var mainBrowser = $("#" + id);
  mainBrowserRefreshLayout (id);

  $("#mainBrowser").delegate ("#mainBrowserLeftMenu.e10-app-lb-icons li", CLICK_EVENT, function(event) {
    menuItemClick ($(this), event);
  });
	$("#mainBrowser").delegate ("#smallPanelMenu li", CLICK_EVENT, function(event) {
		menuItemClick ($(this), event);
	});
	$("#mainBrowser").delegate ("#mainBrowserLeftMenu.e10-app-lb-panel li>span.title", CLICK_EVENT, function(event) {
		menuItemClickPanel ($(this), event);
	});

	$("body").on (CLICK_EVENT, "div.e10-panelSubMenu li.mi", function(event) {
		menuItemClickSubMenu ($(this), event);
	});

/*	$("#mainBrowser").delegate ("#mainListViewMenu li ul li", "click", function(event) {
    viewerMenuItemClick ($(this));
  });
*/

  $("body").delegate ("div.e10-att-input button", CLICK_EVENT, function(event) {
    e10AttWidgetSelect ($(this), event);
  });

	$('#e10-userSettingsBtn').on (CLICK_EVENT, function(event) {
			e10userSettingsMenu ();
		});

	$("body").on (CLICK_EVENT, "ul.e10-selectReport li", function(event) {
		e10selectReport ($(this), event);
	});


	$("body").on (CLICK_EVENT, "div.e10-param .dropdown-menu a", function(event) {
		e10ReportChangeParam ($(this));
	});

	$("body").on (CLICK_EVENT, "div.e10-param .e10-param-btn", function(event) {
		e10ReportChangeParam ($(this));
	});

	$("body").on (CLICK_EVENT, "div.e10-param-list li.selectable div.title", function(event) {
		e10ListParamToggle ($(this));
	});

	$("#mainBrowserContent").on ('change', "#e10dashboardWidget input, #e10dashboardWidget select", function(event) {
		e10WidgetParam(event, $(this));
	});

	$("#e10-tm-viewerbuttonbox").on ('change', "input, select", function(event) {
		e10refreshReport (0);
	});
	$("body").on ('change', "#e10-report-panel", function(event) {
		e10refreshReport (0);
	});

	$("body").on (CLICK_EVENT, ".df2-reportaction-trigger", function(event) {
		e10printReport ($(this), event);
	});

	$('body').delegate ("ul.df2-detail-menu.report >li", CLICK_EVENT, function(event) {
		e10reportChangeTab ($(this), event);
	});

	$('body').delegate ("ul.df2-detail-menu.widget >li, ul.e10-panelMenu-small.widget >li", CLICK_EVENT, function(event) {
		e10widgetChangeTab ($(this), event);
	});

	$("body").delegate ('div.e10-rows ul li input, div.e10-rows ul li select, div.e10-rows ul li button', 'focus', function(event) {
		changeFocusedDocumentRow ($(this), event);
	});

	$("body").on('mouseenter', 'span.e10-att-link >span.pre', function() {
		attLinkPreviewOpen ($(this));
	});
	$("body").on('mouseleave', 'span.e10-att-link >span.pre', function() {
		attLinkPreviewClose ($(this));
	});

	$("body").on (CLICK_EVENT, "span.e10-sum-table-exp-icon", function(event) {
		e10SumTableExpandedCellClick ($(this), event);
	});

	$("body").on (CLICK_EVENT, "tr.e10-sum-table-row-selectable", function(event) {
		e10SumTableSelectRow ($(this), event);
	});

	$("body").on (CLICK_EVENT, "li.e10-static-tab", function(event) {
		e10StaticTab ($(this), event);
	});


	$(window).resize(function() {mainBrowserRefreshLayout ("mainBrowser")});

	var mainListViewMenu = $('#mainListViewMenu');
	if (mainListViewMenu.parent().hasClass('e10-app-lb-panel'))
	{
		var firstItem = mainListViewMenu.find(">li:first");
		if (firstItem.hasClass('closed'))
			firstItem.removeClass('closed');
		var firstMenuItem = firstItem.find('>ul:first>li:first');
		menuItemClickPanel(firstMenuItem.find('>span.title'));
	}
	else
	{
		var firstItem = mainListViewMenu.find("li:first");
		if (firstItem.is('LI'))
			menuItemClick(firstItem);
		else if (mainBrowser.hasClass('e10-appEmbed'))
			e10DocumentAction(null, mainBrowser);
	}
	g_NCTimer = setTimeout (function () {e10NCReload ('init')}, 1000);
}

function mainBrowserRefreshLayout (id, object) {
	var mbc = $('#mainBrowserContent');

	if (object)
		mbc.attr('data-disable-top-toolbar', (object.disableTopToolbar) ? '1' : '0');

	var mainBrowser = $('#mainBrowser');
	var nh = dfMaximizeMainElement(id);

	var mainBrowserSizeX = mainBrowser.width();

	var rightBarSizeX = 0;
	var rightBar = $('#mainBrowserRightBar');
	if (rightBar.is('DIV'))
		rightBarSizeX = rightBar.width();

	var leftMenu = $('#mainBrowserLeftMenu');
	var leftMenuSizeX = 0;
	if (leftMenu.length)
		leftMenuSizeX = leftMenu.width();

	var leftSubMenu = $('#mainBrowserLeftMenuSubItems');
	var leftSubMenuSizeX = 0;
	if (leftSubMenu.hasClass('open'))
		leftSubMenuSizeX = leftSubMenu.outerWidth(true);

	var topMenu = $('#mainBrowserTopBar');
	var topMenuSizeY = topMenu.height();
	topMenu.width(mainBrowserSizeX - leftMenuSizeX - rightBarSizeX);

	var appMenuSizeY = 0;
	var appMenu = $('#mainBrowserAppMenu');
	if (appMenu.length)
		appMenuSizeY = appMenu.height();

	var disableTopToolbar = parseInt(mbc.attr('data-disable-top-toolbar'));
	if (disableTopToolbar) {
		topMenu.hide();
		topMenuSizeY = 6;
	}
	else
		topMenu.show();

	var mainBrowserMMTopY = appMenuSizeY;
	var mbcPosY = 6;
	if ($('body').hasClass('e10-appMainWidgetMode')) {
		mbcPosY = 0;
		topMenuSizeY = 0;
		mainBrowserMMTopY = 0;
	}

	mbc.css({top: topMenuSizeY + appMenuSizeY});
	mbc.css({left: leftMenuSizeX + leftSubMenuSizeX});
	mbc.height(nh - mbc.position().top - mbcPosY);
	mbc.width(mainBrowserSizeX - leftMenuSizeX - leftSubMenuSizeX- rightBarSizeX);

	var leftMenuHeight = nh - appMenuSizeY;
	leftMenu.height(leftMenuHeight);
	if (rightBarSizeX) {
		rightBar.css({top: appMenuSizeY});
		rightBar.height(leftMenuHeight);
	}

	if (leftMenu.hasClass('e10-app-lb-panel'))
	{
		var leftMenuPanelItemsHeight = leftMenuHeight - $('#mainBrowserLeftMenuHeader').innerHeight() - 10;
		leftMenu.find('#mainListViewMenu').height(leftMenuPanelItemsHeight);
	}

	leftSubMenu.height(nh - mbc.position().top - mbcPosY);
	leftSubMenu.css({left: leftMenuSizeX, top: topMenuSizeY + appMenuSizeY});


	var nc = $('#mainBrowserNC');
	var nctlbr = $('#mainBrowserNC>div.e10-nc-toolbar');
	nc.css ({top: appMenuSizeY});
	nc.height (mainBrowser.height() - appMenuSizeY);

	nc.find('>div.e10-nc-viewer').height (mainBrowser.height() - appMenuSizeY - nctlbr.height());

	var mm = $('#mainBrowserMM');
	mm.css({top: mainBrowserMMTopY});
	mm.height(mainBrowser.height() - mainBrowserMMTopY);

	var objEl = $('#mainBrowserContent div:first-child');
	if (objEl.attr ('data-object') == 'viewer')
	{
		viewerRefreshLayout (objEl.attr ('id'), nh);
		return;
	}
	if (objEl.attr ('id') === 'e10reportWidget')
	{
		reportWidgetRefreshLayout();
		return;
	}

	dfMaximizeMainElement (id);
} // mainBrowserRefreshLayout


function menuItemClick (e, event)
{
	var objectType = e.attr ('data-object');
	if (objectType === 'report')
	{
		e10report (e, event);
		return;
	}

	e10CloseModals ();

	$("#mainListViewMenu li").removeClass ("activeMainItem");
	$("#smallPanelMenu li").removeClass ("activeMainItem");
	e.addClass ("activeMainItem");
	viewerMenuLoadViewer (e);

	var activePanel = $('#mainBrowserAppMenu ul.appMenuLeft li.active');
	if (e.attr('title'))
		setPageTitle (e.attr('title'));
	else
		setPageTitle (e.find('>div.t').text());
} // menuItemClick

function menuItemClickSubMenu (e, event)
{
	var objectType = e.attr ('data-object');
	if (objectType === 'report')
	{
		e10report (e, event);
		return;
	}

	e10CloseModals ();

	$("#mainBrowserLeftMenuSubItems>div.e10-panelSubMenu.active >ul >li").removeClass ("active");
	e.addClass ("active");
	viewerMenuLoadViewer (e);

	setPageTitle (e.find('>div.t').text());
} // menuItemClickSubMenu

function menuItemClickPanel (titleElement, event)
{
	var e = titleElement.parent();

	if (e.hasClass('e10-app-lb-panel-group'))
	{
		if (e.hasClass('closed'))
		{
			e.removeClass('closed');
		}
		else
		{
			e.addClass('closed');
		}

		return;
	}

	var objectType = e.attr ('data-object');
	if (objectType === 'report')
	{
		e10report (e, event);
		return;
	}

	e10CloseModals ();

	$("#mainBrowserLeftMenu li.activeMainItem").removeClass ("activeMainItem");
	e.addClass ("activeMainItem");
	viewerMenuLoadViewer (e);

	setPageTitle (titleElement.text());
}

function setPageTitle (title)
{
	var activePanelText = '';
	var activePanel = $('#mainBrowserAppMenu ul.appMenuLeft li.active');
	if (activePanel.length)
		activePanelText = activePanel.text();

	var fullTitle = title;
	if (activePanelText && activePanelText !== '' && activePanelText.charCodeAt(0) != 160)
		fullTitle += ' / ' + activePanelText;

	fullTitle += ' | ' + serverTitle;

	document.title = fullTitle;
}

function e10doSizeHints (e, viewersRefreshLayout)
{
	e.find ('.e10-wsh-h2b').each (
		function ()
		{
			var thisEl = $(this);
			var sizer = searchObjectAttr (thisEl, 'data-e10mxw');
			if (sizer)
			{
				var h = sizer.height() - thisEl.offset().top - 12 + sizer.offset().top;
				thisEl.height(h);
			}
			if (thisEl.attr ('data-refreshLayout'))
			{
				window[thisEl.attr ('data-refreshLayout')](thisEl);
			}
			//alert ("test: " + sizer.height());
		}
	);

	e.find ('.e10-wsh-h2p').each (
		function ()
		{
			var thisEl = $(this);
			thisEl.height (thisEl.parent().height());
		}
	);
//TODO: jenom jednou!
	e.find ('.e10-wsh-h2b').each (
		function ()
		{
			var thisEl = $(this);
			var sizer = searchObjectAttr (thisEl, 'data-e10mxw');
			if (sizer) {
				var h = sizer.height() - thisEl.offset().top - 12 + sizer.offset().top;
				var minHeight = parseInt(thisEl.attr('data-min-height'));
				if (h < minHeight)
					h = minHeight;
				thisEl.height(h);
			}
			if (thisEl.attr ('data-refreshLayout'))
			{
				window[thisEl.attr ('data-refreshLayout')](thisEl);
			}

			if (thisEl.attr('data-init-viewers') !== undefined)
			{
				thisEl.find('div.df2-viewer').each(function () {
					var viewerId = $(this).attr("id");
					initViewer(viewerId);
				});

			}
		}
	);

	e.find ('.e10-wsh-h2p').each (
		function ()
		{
			var thisEl = $(this);
			thisEl.height (thisEl.parent().height());
		}
	);

	e.find ('.e10-wsh-h2t').each (
		function ()
		{

			var thisEl = $(this);
			var sizer = searchObjectAttr (thisEl, 'data-main-tabs');
			thisEl.height (sizer.height());
		}
	);


	if (viewersRefreshLayout === 1)
	{
		e.find('div.df2-viewer').each(function () {
			var viewerId = $(this).attr("id");
			initViewer(viewerId);
		});
	}
}

function e10setProgress (e, progress)
{
	if (progress)
		e.css ({'color': 'rgba(0,0,0,.2)'});
	else
		e.css ({'color': 'rgba(0,0,0,1)'});
}

function e10setProgressIndicator (objectId, state)
{
	var i = $('#'+objectId+'Progress');
	if (!i[0])
		return;

	if (state)
	{
		if (i.attr ('data-run') === '1')
			return;
		i.attr ('data-run', '1');
		i.data ('oldState', i.html());
		var spinnerUrl = httpApiRootPath + '/www-root/sc/shipard/spinner-bars.svg';
		i.html("<img style='width: 1em; height: 1em;' src='"+spinnerUrl+"'></img>");
	}
	else
	{
		i.attr ('data-run', '0');
		i.html (i.data ('oldState'));
	}
}

function e10userSettingsMenu ()
{
	var userMenu = $('#e10-tm-user-m');
	userMenuBtn = userMenu.parent();

	if (userMenu.hasClass ('active'))
	{
		userMenu.removeClass ('active');
		userMenuBtn.removeClass ('active');
	}
	else
	{
		userMenuBtn.addClass ('active');
		userMenu.addClass ('active');
		userMenu.css ({top: $('#e10-panel-topmenu').height() + 2});
	}
}

var g_htmlCodeTopMenuSearch;
var g_htmlCodeToolbarViewer;

function e10browserClearToolbar ()
{
	$('#e10-tm-searchbox').find ('>*').remove ();

	$('#e10-tm-viewerbuttonbox').find ('>*').remove ();
	$('#e10-tm-viewerbuttonbox').html ('');

	$('#e10-tm-detailbuttonbox').find ('>*').remove ();
	$('#e10-tm-detailbuttonbox').html ('');

	$('#e10-tm-toolsbuttonbox').find ('>*').remove ();
	$('#e10-tm-toolsbuttonbox').html ('');
}

function e10browserSetContent (data)
{
	e10browserClearToolbar ();
	var browserContent = $("#mainBrowserContent");
	browserContent.find("*:first").remove();
	$('#e10-tm-searchbox').html (data.htmlCodeTopMenuSearch);
	$('#e10-tm-viewerbuttonbox').html (data.htmlCodeToolbarViewer);
	$('#e10-tm-toolsbuttonbox').html (data.htmlCodeToolbarTools);


	browserContent.html (data.mainCode);

	if (data.htmlCodeDetails)
		$('#mainBrowserRightBarDetails').html (data.htmlCodeDetails);
	else
		$('#mainBrowserRightBarDetails').find ('>*').remove ().html ('');

	e10setProgress (browserContent, 0);

	g_htmlCodeTopMenuSearch = data.htmlCodeTopMenuSearch;
	g_htmlCodeToolbarViewer = data.htmlCodeToolbarViewer;
}

function e10browserCheckToolbar ()
{
	if ($('#e10-tm-searchbox').html () == '')
	{
		$('#e10-tm-searchbox').html (g_htmlCodeTopMenuSearch);
		$('#e10-tm-viewerbuttonbox').html (g_htmlCodeToolbarViewer);
	}
}

// -----
// forms
// -----

function mainFormInit (id)
{
}


function mainFormRefreshLayout (id)
{
} // mainFormRefreshLayout


// ----
// help
// ----

function e10Help (e)
{
	var newFormHtml = "<div id='HelpEnv' class='e10-help-env'></div>" +
											"<div id='Help' class='e10-help'>" +
												"<div id='HelpHeader' class='e10-help-header'></div>" +
												"<div id='HelpContent' class='e10-help-content'></div>" +
											"</div>"
	;

	var mainBrowser = $('#mainBrowser');
	var mainBrowserContent = $('#mainBrowserContent');

	var newEditForm = $('body').append (newFormHtml);

	var helpEnv = $('#HelpEnv');
	var help = $('#Help');
	var helpHeader = $('#HelpHeader');
	var helpContent = $('#HelpContent');

	var leftOffset = 0;
	helpEnv.css ({left: 0, top: 0, width: mainBrowser.width (), height: mainBrowser.height()});
	help.css ({left: mainBrowser.width () - help.width(), top: 0, height: mainBrowser.height()});

	var url = httpApiRootPath + '/api/help';

  var jqxhr = $.getJSON (url, function(data) {
			helpHeader.html (data.object.headerCode);
			helpContent.html (data.object.contentCode);
			helpContent.height (mainBrowser.height() - helpHeader.height ());
  }).error(function() {alert("error 2: content not loaded (" + url + ")");});
}

function e10CloseHelp (e)
{
	$('#Help').detach();
	$('#HelpEnv').detach();
}



// ------
// panels
// ------

function df2PanelAction (event, e)
{
	var actionType = e.attr ("data-action");
	if (actionType === 'reloadPanel')
	{
		location.reload();
		return;
	}
	if (actionType === 'go')
	{
		window.location = e.attr ('data-href');
		return;
	}

	var newFormHtml = "<div id='PanelActionEnv' class='e10-panelAction-env'></div>" +
											"<div id='PanelAction' class='e10-panelAction'>" +
												"<div id='PanelActionMsg' class='e10-panelAction-waitMsg'><h3>čekejte, prosím</h3>akce je spuštěna a může trvat až minutu...</div>" +
											"</div>"
	;

	var mainBrowser = $('#mainBrowser');
	var mainBrowserContent = $('#mainBrowserContent');

	var newEditForm = $('body').append (newFormHtml);

	var panelActionEnv = $('#PanelActionEnv');
	var panelAction = $('#PanelAction');
	var panelActionMsg = $('#PanelActionMsg');

	var leftOffset = 0;
	panelActionEnv.css ({left: 0, top: 0, width: mainBrowser.width (), height: mainBrowser.height()});
	panelAction.css ({left: (mainBrowser.width () - panelAction.width())/2, top: 100, height: mainBrowser.height() - 200});

	var urlPath = "/api/call/" + actionType + '?mismatch=1&callback=?';

	//alert (urlPath);
	e10.server.get (urlPath, function(data) {
			//alert (JSON.stringify (data.object.message));
			panelActionMsg.html ("<h3>hotovo</h3>" + data.object.message + "<p/><button class='btn btn-large btn-success df2-panelaction-trigger' data-action='reloadPanel'>Pokračovat</button>");
  });
}

// ---
// hotkeys
// ---
function testHotkey(event)
{
	if (g_openModals.length)
	{
		var modalElementId = g_openModals[g_openModals.length - 1];
		var btnsElement = $('#'+modalElementId+' >div.e10-ef-buttons');
		if (btnsElement.length)
		{
			var saveInProgress = 0;
			var btnSave = btnsElement.find('button[data-save-style="default"]');
			if (btnSave.length && btnSave.attr ('data-insave') === '1')
				saveInProgress = 1;

			if (saveInProgress)
				return;

			if (event.keyCode === 27) { // ESC
				var btnClose = btnsElement.find('button[data-action="cancelform"]');
				if (btnClose.length && btnSave.length && btnSave.prop('disabled')) {
					btnClose.click();
					return;
				}

				if (btnClose.length && !btnSave.length) {
					btnClose.click();
					return;
				}
			}
			if (event.keyCode === 113) { // F2
				var btnPrimarySave = btnsElement.find('button[data-save-style="primary"]');
				if (btnPrimarySave.length) {
					btnPrimarySave.click();
					return;
				}
			}
		}
	}

	if (!g_openModals.length)
	{
		var mainViewer = $('#mainBrowserContent > div.e10-mainViewer');
		if (mainViewer.length)
		{
			if (event.keyCode === 13) {
				var openButton = $('#e10-tm-detailbuttonbox button[data-action="editform"]');
				if (openButton.length) {
					openButton.click();
					return;
				}
			}
			if (event.keyCode === 78 && event.ctrlKey) { // CTRL-N
				var newButton = $('#e10-tm-viewerbuttonbox button[data-action="newform"]');
				if (newButton.length) {
					newButton.click();
					return;
				}
			}
		}
	}
}

// -------
// viewers
// -------

function touchScroll(id)
{
	if(navigator.userAgent.indexOf('Android') != -1 && navigator.userAgent.indexOf('2.') != -1 && navigator.userAgent.indexOf('Opera') == -1)
	{
		var el=document.getElementById(id);
		var scrollStartPos=0;

		document.getElementById(id).addEventListener("touchstart", function(event) {
			scrollStartPos=this.scrollTop+event.touches[0].pageY;
			//event.preventDefault();
		},false);

		document.getElementById(id).addEventListener("touchmove", function(event) {
			this.scrollTop=scrollStartPos-event.touches[0].pageY;
			event.preventDefault();
		},false);
	}
}


var g_formId = 1;
var e10ViewerDelegatesDone = false;
var g_focusedInputId = '';
var g_supportsPassiveEL = false;
var g_standaloneApp = 0;

function e10jsinit ()
{
	try {
		var opts = Object.defineProperty({}, 'passive', {
			get: function() {
				g_supportsPassiveEL = true;
			}
		});
		window.addEventListener("test", null, opts);
	} catch (e) {}

	if (("standalone" in window.navigator) && window.navigator.standalone)
		g_standaloneApp = 1;
	else
	if (window.matchMedia('(display-mode: standalone)').matches)
		g_standaloneApp = 2;

	if (g_standaloneApp === 1)
		$('body').addClass ('e10-ios-standalone-app');
	if (g_standaloneApp)
	{
		$('#e10-logout-full-button').hide();
		$('#e10-logout-close-button').show();
	}

	if (g_e10_appMode)
		window.onbeforeunload = function() { if (g_openModals.length) return "POZOR: máte otevřeno editační okno."; };

	$("body").delegate ("ul.e10-form-tabs>li, ul.nav-pills>li", CLICK_EVENT, function(event) {
		e10FormsTabClick ($(this));
	});
	$("body").delegate ("ul.e10-form-maintabs>li", CLICK_EVENT, function(event) {
		e10FormsTabClick ($(this));
	});

	$('body').on (CLICK_EVENT, ".df2-action-trigger", function(event) {
		event.stopPropagation();
		event.preventDefault();
		if ($(this).parent().parent().hasClass('dropdown-menu'))
			$('body').trigger('click');
		df2ViewerAction (event, $(this));
	});

	$('body').on (CLICK_EVENT, ".df2-action-trigger-no-shift", function(event) {
		if (!event.shiftKey)
		{
			event.stopPropagation();
			event.preventDefault();
			df2ViewerAction(event, $(this));
		}
	});

	$('body').on (CLICK_EVENT, "button.df2-panelaction-trigger, span.df2-panelaction-trigger", function(event) {
		df2PanelAction (event, $(this));
	});

	$('body').on ('focus', "div.e10-ef-form input, div.e10-ef-form textarea", function(event) {
		e10FormsFocusRefInput (event, $(this));
	});

	$('body').on ('focus', "div.e10-ef-form select", function(event) {
		e10FormsRefInputComboClose ($(this));
	});

	$('body').on ('blur', "input.e10-inputRefId", function(event) {
		e10FormsBlurRefInput (event, $(this));
	});

	$('body').on ('blur', "input.e10-inputDateS", function(event) {
		e10FormsBlurDateInput (event, $(this));
	});

	$('body').on ('blur', "input.e10-inputColor", function(event) {
		e10FormsBlurColorInput (event, $(this));
	});

	$('body').on ('focus', "div.e10-inputDocLink", function(event) {
		e10FormsFocusRefInput (event, $(this));
	});

	$('body').on ('click', "i.e10-inputReference-clearItem", function(event) {
		e10FormsRefInputClear ($(this));
	});

	$('body').on ('click', "i.e10-inputReference-editItem", function(event) {
		e10FormsRefInputEdit ($(this));
	});

	$('body').on ('click', "span.e10-inputDocLink-closeItem", function(event) {
		e10FormsInputDocLinkCloseItem (event, $(this));
	});

	$('body').on (CLICK_EVENT, ".e10-widget-trigger, .df2-widget-trigger", function(event) {
		event.stopPropagation();
		event.preventDefault();
		if ($(this).parent().parent().hasClass('dropdown-menu'))
			$('body').trigger('click');
		e10WidgetAction (event, $(this));
	});

	$('body').on (CLICK_EVENT, ".e10-document-trigger", function(event) {
		event.stopPropagation();
		event.preventDefault();
		if ($(this).parent().parent().hasClass('dropdown-menu'))
			$('body').trigger('click');
		e10DocumentAction (event, $(this));
	});

	$('body').on (CLICK_EVENT, "button.e10-row-action", function(event) {
		e10FormRowAction (event, $(this));
	});

	$('body').on (CLICK_EVENT, "#wss-bc-kbd", function(event) {
		e10SwitchBarcodeKbd (event, $(this));
	});

	$('body').on ('focus', "input,select,textarea", function(event) {
		g_focusedInputId = $(this).attr ('id');
	});

	$('body').on (CLICK_EVENT, "li.e10-sensor", function(event) {
		e10SensorToggle (event, $(this));
	});

	$('body').on ('click', "div.e10-reportPanel-toggle", function(event) {
		e10ReportPanelToggle ($(this));
	});

	$('body').on (CLICK_EVENT, "#e10-nc-button,#e10-nc-close", function(event) {
		e10NCToggle ($(this));
	});

	$('body').on (CLICK_EVENT, "#e10-mm-button,#e10-mm-close", function(event) {
		e10MMToggle ($(this));
	});

	$("body").on ('change', "input.e10-ino-saveOnChange", function(event) {
		//e10SaveOnChange ($(this));
		e10FormNeedSave ($(this), -1);
	});

	$("body").on ('change', "select.e10-ino-saveOnChange", function(event) {
		//e10SaveOnChange ($(this));
		e10FormNeedSave ($(this), -1);
	});

	$("body").on ('change', "textarea.e10-ino-saveOnChange", function(event) {
		//e10SaveOnChange ($(this));
		e10FormNeedSave ($(this), -1);
	});

	$("body").on ('change', "input,select", function(event) {
		//e10.events.columnInputEvent('change', $(this));
		e10FormNeedSave ($(this), 0);
	});

	$("body").on ('change', "input.e10-input-scanner", function(event) {
		e10FormCheckScannerInput ($(this), event);
	});


	$("body").on ('input propertychange', "input,textarea", function(event) {
		e10FormNeedSave ($(this), 0);
	});

	initEventer();
	initMQTT ();
}

function initEventer()
{
	var eventMethod = window.addEventListener ? "addEventListener" : "attachEvent";
	var eventer = window[eventMethod];
	var messageEvent = eventMethod === "attachEvent" ? "onmessage" : "message";
	eventer(messageEvent, function (e) {
		if (e.data === "close-iframe-embedd-element")
		{
			if ($('#embeddAppBlocker').hasClass('iframe-app'))
			{
				$('#embeddApp').remove();
				$('#embeddAppBlocker').remove();
			}
			else
				location.reload(true);
		}
	});
}

function initSubObjects(data)
{
	if (!data.subObjects)
		return;

	for (var i = 0; i < data.subObjects.length; i++)
	{
		var so = data.subObjects[i];
		if (so.type === 'viewer')
		{
			initViewer(so.id, so);
		}
	}
}

function initViewer (id, data)
{
	if (!e10ViewerDelegatesDone)
	{
		$("body").on ('keyup', "input.e10-viewer-search", function(event) {
			viewerIncSearch ($(this), event);
		});

		$("body").on ('change', "input.e10-viewer-param", function(event) {
			viewerIncSearch ($(this), event);
		});

		$("body").on ('keydown', "input.e10-viewer-search", function(event) {
			viewerKeyboard ($(this), event);
		});

		$("body").on ('keyup', "", function(event) {
			testHotkey(event);
		});

		$('body').delegate ('ul.e10-viewer-list >li', 'dblclick', function(event) {
			event.stopPropagation();
			event.preventDefault();
			viewerItemDblClick ($(this));
		});

		$("body").delegate ("ul.e10-viewer-list>li, table.dataGrid tr.e", /*CLICK_EVENT*/'click', function(event) {
			viewerItemClick ($(this));
		});
		$("body").delegate ("div.e10-viewer-list>div.r", 'click', function(event) {
			viewerItemClick ($(this));
		});

		$("body").delegate ("div.e10-rows ul li.e10-rows-append button", CLICK_EVENT, function(event) {
			e10AppendFormRow ($(this));
		});

		$("body").delegate ("button.e10-row-append", CLICK_EVENT, function(event) {
			e10AppendFormProperty ($(this));
		});

		$('body').delegate ("div.viewerQuerySelect span.q", CLICK_EVENT, function(event) {
			e10viewerChangeMainQuery ($(this), event);
		});

		$('body').delegate ("div.viewerBottomTabs span.q", CLICK_EVENT, function(event) {
			e10viewerChangeBottomTab ($(this), event);
		});

		$('body').delegate ("div.viewerTopTabs span.q", CLICK_EVENT, function(event) {
			e10viewerChangeTopTab ($(this), event);
		});

		$('body').delegate ("div.viewerPanelsTabs span.q", CLICK_EVENT, function(event) {
			e10viewerChangePanelsTab ($(this), event);
		});

		$("body").on ('change', "div.queryWidget input", function(event) {
			viewerIncSearch($(this, event, 1));
		});

		$('body').delegate ("ul.df2-detail-menu.viewer>li, div.e10-mv-ld-tabs>ul>li", CLICK_EVENT, function(event) {
			e10viewerChangeDetail ($(this), event);
		});

		$("body").on (CLICK_EVENT, "ul.e10-viewer-combo >li", function(event) {
			e10viewerDoComboClick ($(this), event);
		});

		$("body").on (CLICK_EVENT, ".e10-sidebar-setval", function(event) {
			e10FormsDoSidebarSetVal ($(this), event);
		});


        $("body").on ('dragenter', "ul.e10-viewer-list>li.r, .e10-att-target", function(event) {
            e10viewerAttDragEnter ($(this), event);
        });
        $("body").on ('dragover', "ul.e10-viewer-list>li.r, .e10-att-target", function(event) {
            e10viewerAttDragOver ($(this), event);
        });
        $("body").on ('dragleave', "ul.e10-viewer-list>li.r, .e10-att-target", function(event) {
            e10viewerAttDragLeave ($(this), event);
        });
        $("body").on ('drop', "ul.e10-viewer-list>li.r, .e10-att-target", function(event) {
            e10viewerAttDragDrop ($(this), event);
        });

		e10ViewerDelegatesDone = true;
	}

	var viewer = $('#' + id);

	if (data)
	{
		viewerRefreshLayout (id);
		viewerAppendContent(viewer, data);
	}

	var viewerLines = $('#' + id + 'Items');
	if (viewerLines.hasClass ('dataGrid'))
	{
		viewerLines.find('>table.dataGrid.main').floatThead({
			scrollContainer: function (table) {
				return table.closest('.df2-viewer-list');
			},
			useAbsolutePositioning: false,
			zIndex: 101
		});
	}

	if (!data)
	{
		viewerRefreshLayout(id);
		if (viewer.hasClass('e10-viewer-pane'))
			viewerRefresh (viewer);
	}
	if (viewerLines.hasClass ('dataGrid'))
		viewerLines.find ('>table.dataGrid.main').floatThead('reflow');

	if (viewerLines.length)
		viewerLines[0].addEventListener('scroll', e10viewerLoadNextData, g_supportsPassiveEL ? { passive: true } : false);
}


function viewerAppendContent (viewer, data)
{
	var viewerMode = viewer.attr('data-mode');

	if (viewerMode !== 'panes')
		return;

	var cntColumns = data.viewer.panesColumns;
	var columns = [];
	var c = 0;

	var viewerList = $('#' + data.id + 'Items');

	var endMark = null;
	var dynamicContent = viewerList.find('>div.e10-vp-dc');
	if (!dynamicContent.length)
	{
		if (data.staticContent)
		{
			var scel = $('<div class="e10-vp-sc"></div>');
			var sc = viewerList.append(scel);
			scel.html(data.staticContent);
		}

		dynamicContent = $('<div class="e10-vp-dc"></div>');
		viewerList.append(dynamicContent);

		endMark = $('<div class="e10-vp-endMark">'+data.endMark+'</div>');
		viewerList.append(endMark);
	}
	else {
		endMark = $(viewerList.find('>div.e10-vp-endMark'));
		endMark.html(data.endMark);
	}


	var vgIdCurrent = '___x___';
	for (var i = 0; i < data.htmlItems.length; i++)
	{
		var item = data.htmlItems[i];
		if (item['code'] === '')
			continue;

		var vgId = (item.vgId) ? item.vgId : 'NONE';
		if (vgId !== vgIdCurrent)
		{
			var existedColumns = dynamicContent.find('>div.e10-vp-col.vgId-'+vgId);

			if (!existedColumns.length)
			{
				if (vgId !== 'NONE') {
					var gd = data.viewerGroups[vgId];
					if (gd['code'] !== undefined &&gd['code'] !== '') {
						var groupHeader = $('<div class="e10-vp-vg">' + gd['code'] + '</div>');
						dynamicContent.append(groupHeader);
					}
					else
					{
						var groupHeader = $('<div class="e10-vp-vg blank"></div>');
						dynamicContent.append(groupHeader);
					}

					if (gd['columnsHeaderCode'] !== undefined && gd['columnsHeaderCode'] !== '') {
						var columnsHeader = dynamicContent.parent().parent().find('div.e10-sv-search-toolbar>div.e10-vp-dc-col-header');
						if (columnsHeader.length === 0)
							dynamicContent.parent().parent().find('div.e10-sv-search-toolbar').
								append($('<div class="e10-vp-dc-col-header" style="width: 100%; display: inline-block;">' + gd['columnsHeaderCode'] + '</div>'));
					}

					if (gd['cntColumns'])
						cntColumns = gd['cntColumns'];
					else
						cntColumns = data.viewer.panesColumns;
				}

				if (cntColumns == 0)
				{
					var oneEmWidth = parseFloat($("html").css("font-size"));
					var minColEmWidth = data.viewer.panesColumnWidth;
					var minColPxWidth = minColEmWidth * oneEmWidth;
					var hh = viewerList.width();
					cntColumns = (hh / minColPxWidth) | 0;
					if (!cntColumns)
						cntColumns = 1;
					else if (cntColumns > 10)
						cntColumns = 10;
				}
				var className = 'e10-vp-col e10-vp-col-'+cntColumns+' vgId-'+vgId;

				var columnsHeadersCode = '';
				var columnsHeadersClassName = 'e10-bold e10-vp-col-'+cntColumns;
				for (c = 0; c < cntColumns; c++)
				{
					if (data.viewer.columnsInfo && data.viewer.columnsInfo[c])
					{
						columnsHeadersCode += '<div style="float: left;padding-left: 1ex; " class="'+columnsHeadersClassName+'">';
						columnsHeadersCode += data.viewer.columnsInfo[c]['titleCode'];
						columnsHeadersCode += '</div>';
					}
				}
				if (columnsHeadersCode !== '')
				{
					var columnsHeader = dynamicContent.parent().parent().find('div.e10-sv-search-toolbar>div.e10-vp-dc-col-header');
					if (columnsHeader.length === 0)
						dynamicContent.parent().parent().find('div.e10-sv-search-toolbar').append($('<div class="e10-vp-dc-col-header" style="width: 100%; display: inline-block;">' + columnsHeadersCode + '</div>'));
				}

				columns = [];
				for (c = 0; c < cntColumns; c++)
				{
					columns[c] = $('<div class="'+className+'"></div>');
					dynamicContent.append(columns[c]);
				}
			}
			else
			{
				columns = [];
				cntColumns = existedColumns.length;
				for (c = 0; c < existedColumns.length; c++)
					columns[c] = $(existedColumns[c]);
			}
			c = 0;
			vgIdCurrent = vgId;
		}

		if (item['columnNumber'] !== undefined)
		{
			columns[item['columnNumber']].append(item['code']);
		}
		else {
			columns[c].append(item['code']);
			c++;
		}
		if (c === cntColumns)
			c = 0;
	}
}


function e10viewerChangeMainQuery (e, event)
{
	var sel = e.parent();
	if (sel.is ('SPAN'))
		sel = sel.parent();
	sel.find ('span.active').removeClass ('active');
	sel.find ('input').val (e.attr('data-mqid'));

	e.addClass ('active');

	viewerRefresh (e);
}

function e10viewerChangeBottomTab (e, event)
{
	var sel = e.parent();
	if (sel.is ('SPAN'))
		sel = sel.parent();
	sel.find ('span.active').removeClass ('active');
	sel.find ('input').val (e.attr('data-mqid'));

	e.addClass ('active');

	viewerRefresh (e);
}

function e10viewerChangeTopTab (e, event)
{ // TODO: merge with e10viewerChangeBottomTab?
	var sel = e.parent();
	if (sel.is ('SPAN'))
		sel = sel.parent();
	sel.find ('span.active').removeClass ('active');
	sel.find ('input').val (e.attr('data-mqid'));

	e.addClass ('active');

	viewerRefresh (e);
}

function e10viewerChangePanelsTab(e, event)
{
	var sel = e.parent();
	if (sel.is ('SPAN'))
		sel = sel.parent();
	sel.find ('span.active').removeClass ('active');

	var panelId = e.attr('data-id');
	sel.find ('input').val (panelId);
	e.addClass ('active');

	var viewerId = searchObjectId (e, 'viewer');
	var viewer = $('#' + viewerId);
	e10setProgressIndicator(viewerId, 1);

	var tableName = viewer.attr ("data-table");
	var viewerName = viewer.attr ("data-viewer-view-id");

	var url = "/api/viewerpanel/" + tableName + "/" + viewerName + '/' + panelId + "/" + '?mismatch=1&ownerViewerId='+viewerId;
	var codeTarget = $('#'+viewerId+'Report >div.e10-mv-lr-content');

	e10.server.get (url, function(data)
	{
		codeTarget.html (data.object.htmlContent);
		codeTarget.find ('div.df2-viewer').each (function () {
			var viewerId = $(this).attr ("id");
			initSubObjects(data);
		});
		e10setProgressIndicator(viewerId, 0);
	});
}


function e10viewerChangeDetail (e, event)
{
	e.parent().find ('li.active').removeClass ('active');
	e.addClass ('active');

	var viewerId = e.parent().attr ('data-viewer');
	var listItem = $('#' + viewerId + 'Items >.active');

	$('#mainBrowserRightBarButtonsAdd').find ('>*').remove ();
	$('#mainBrowserRightBarButtonsEdit').find ('>*').remove ();

	e10viewerSetDetail (viewerId, listItem);
}


function e10viewerDoComboClick (e, event)
{
	var viewerId = searchObjectId (e, 'viewer');
	var viewer = $('#' + viewerId);

	var pk = e.attr ('data-pk');
	if (viewer.attr ('data-combo-column-target'))
	{ // column combo
		var targetId = viewer.attr ('data-combo-column-target');
		var target = $('#' + targetId);
		if (target.attr ('readonly') !== undefined)
			return;

		if (target.attr ('data-listid'))
		{
			//var table = viewer.attr ('data-table');
			var table = searchParentAttr (e, 'data-table');
			var infotext = e.find ('.df2-list-item-t1').text ();
			//var ndx = e.find ('div.df2-list-item-t1').text ();

			var listItems = target.find ('ul').first();
			var newItem = "<li class='data' data-pk='" + pk + "' data-table='" + table + "'" + '>' +
								infotext + "<span class='e10-inputDocLink-closeItem'>&times;</span></li>";


			target.find ("span.placeholder").hide();
			$(newItem).insertBefore($('#' + targetId + '>ul>li.input'));
			listItems.find('li.input>input').val('').focus();
			e10FormSetAsModified (target);

			var formId = searchObjectId (target, 'form');
			var form = $('#'+formId);
			e10doSizeHints (form);
		}
		else
		{
			var valueInput = target.find ('input.e10-inputNdx');
			if (valueInput.is('INPUT'))
			{
				valueInput.val (pk);
				target.find('span.btns').show();
				var infotext = e.find ('div.df2-list-item-t1').text ();
				target.find ('span.e10-refinp-infotext').text (infotext);
			}
			else
			{
				valueInput = target.find ('input.e10-inputRefId, textarea.e10-inputRefId');
				var inputPrefix = searchParentAttr(valueInput, 'data-inputprefix');
				var iel = e.get(0);
				for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
				{
					var attrName = attrs.item(i).nodeName;
					if (attrName.substring(0, 7) !== 'data-cc')
						continue;
					var valParts = attrs.item(i).nodeValue.split(':');
					var inputId = '#'+inputPrefix + valParts[0];
					var inputElement = $(inputId);
					if (inputElement.hasClass('e10-inputLogical'))
					{
						var checkIt = (b64DecodeUnicode(valParts[1]) === '1');
						inputElement[0].checked = checkIt;
					}
					else
						inputElement.val (b64DecodeUnicode(valParts[1]));
				}
				valueInput.addClass ('e10-ino-saveOnChange');
			}

			if (event !== 0 && event.shiftKey)
				valueInput.attr ('data-softchange', '1');
			e10FormSetAsModified (valueInput);
			if (valueInput.hasClass ('e10-ino-saveOnChange'))
				valueInput.change();

			target.find ('input.e10-inputRefId').focus();
		}
		return;
	}

	if (viewer.attr ('data-combo-rows-target'))
	{ // main rows combo
		var formId = viewer.attr ('data-combo-formid-target');
		var form = $('#' + formId);
		if (form.attr ('data-readonly') !== undefined)
			return;
		var options = {"appendRowList": "rows", "appendRowItemPK": pk};
		if (!e10SaveOnChange (form, options))
			setTimeout (function () {e10viewerDoComboClick (e, 0)}, 50);
	}
}

var dadCounter = 0;
var lastDadElement = null;

function e10viewerAttDragEnter (e, event)
{
	event.stopPropagation();
	event.preventDefault();

	if (lastDadElement != null)
		lastDadElement.removeClass('dropzone');

	dadCounter++;
	lastDadElement = e;
	if (!e.hasClass('dropzone'))
	{
			e.addClass('dropzone');
	}
}

function e10viewerAttDragLeave (e, event)
{
	dadCounter--;
	if (dadCounter === 0)
	{
		event.stopPropagation();
		event.preventDefault();
		e.removeClass('dropzone');
		lastDadElement = null;
	}
}

function e10viewerAttDragOver (e, event)
{
    event.stopPropagation();
    event.preventDefault();
}

function e10viewerAttDragDrop (e, event)
{
    event.stopPropagation();
    event.preventDefault();

		if (lastDadElement != null)
			lastDadElement.removeClass('dropzone');
		lastDadElement = null;

    var files = event.originalEvent.dataTransfer.files;

		var viewerId = '';
		if (e.is('li') && e.hasClass ('r'))
    	viewerId = searchObjectId (e, 'viewer');

		var tableId = searchParentAttr(e, 'data-table');

    var pk = e.attr ('data-pk');
	if (pk === undefined)
		pk = searchParentAttr(e, 'data-pk');

    g_formId++;
    var newElementId = "mainEditF" + g_formId;

    var url = "/api/window/" + 'lib.ui.AttachmentsWindow' + "/0?callback=?&newFormId=" + newElementId +
        '&tableid=' + tableId + '&recid='+pk;

    var postData = {};

    e10.server.post(url, postData, function(data) {
        e10ViewerCreateEditForm (data, newElementId, 'viewer', viewerId);
        var attInput = $('#'+newElementId+' input.e10-att-input-file').get(0);

        attInput.files = files;
	      e10AttWidgetFileSelected(attInput);
    });
}


function reportWidgetRefreshLayout ()
{
	var widget = $('#e10reportWidget');

	var totalSizeY = widget.parent().height ();
	var totalSizeX = widget.parent().width();

	widget.width (totalSizeX);
	widget.height (totalSizeY);

	var leftPanel = $("#e10reportWidget >div.e10-wr-left");
	var reportsList = leftPanel.find("ul.e10-wr-listreps");
	var content = $("#e10reportWidget >div.e10-wr-content");

	leftPanel.height (totalSizeY);
	reportsList.height (totalSizeY);

	var reportsListSizeX = leftPanel.width();
	var contentSizeX = totalSizeX - reportsListSizeX;
	content.height (totalSizeY);
	content.width (contentSizeX - 4 - 8);

	var contentData = $("#e10reportWidget >div.e10-wr-content >div.e10-wr-data");
	var contentPanel = $("#e10reportWidget >div.e10-wr-content >div.e10-wr-params");

	contentData.width (contentSizeX - 4 - 8 - contentPanel.width() - 3);
	contentData.height(totalSizeY - 2);
	contentPanel.height(totalSizeY);
}

function boardWidgetRefreshLayout (id)
{
	var contentElement = $('#e10dashboardWidget>div>div.e10-widget-content');
	if (!contentElement.length)
	{
		$('#e10dashboardWidget').find ('div.e10-gs-row.sameHeight').each (function () {
			var h = $(this).innerHeight() - 20;
			$(this).find('>div>div').height(h);
		});

		var maxh = $('#e10dashboardWidget').innerHeight();

		$('#e10dashboardWidget').find ('div.df2-viewer').each (function () {
			var oo = $(this).parent();
			oo.height(maxh - oo.position().top - 15);
		});

		$('#e10dashboardWidget').find ('div.e10-widget-reportViewer >div.e10-widget-content').each (function () {
			var oo = $(this);
			oo.height(maxh - oo.position().top - 20);
		});

		return;
	}

	var height = contentElement.parent().parent().innerHeight();
	height -= contentElement.position().top;
	contentElement.height(height);

	var widget = $('#'+id);
	var widgetContent = $("#"+id+">div.e10-widget-content");

	var totalSizeY = widgetContent.height ();
	var contentSizeX = widget.width();

	var contentData = $("#"+id+">div.e10-widget-content >div.e10-wr-data");
	var contentPanel = $("#"+id+" >div.e10-widget-content >div.e10-wr-params");

	contentData.width (contentSizeX - contentPanel.width());
	contentData.height(totalSizeY);
	contentPanel.height(totalSizeY);
}

function viewerRefreshLayout (id, h)
{
  var viewer = $('#' + id);
	var viewerId = viewer.attr ('data-viewer');
	var viewerType = viewer.attr ('data-viewertype');

	var linesWidth = parseInt (viewer.attr ('data-lineswidth'));

	var totalSizeY = viewer.parent().height ()|0;
  var totalSizeX = viewer.parent().width()|0;

	viewer.width (totalSizeX);
	viewer.height (totalSizeY);

	var viewerLinesContainer = $("#" + id + " >div.e10-sv-body");
	var viewerLinesFWToolbar = $("#" + id + " >div.e10-sv-fw-toolbar");
	var viewerLinesBottomTabs = viewerLinesContainer.find ("div.viewerBottomTabs");
	var viewerLinesSearch = $("#" + id + "Search");
	var viewerLinesList = $("#" + id + "Items");
	var viewerDetailList = $("#" + id + "Details");
	var viewerDetailReport = $("#" + id + "Report");

	var panelLeft = $("#" + id + "PanelLeft");
	var panelRight = $("#" + id + "PanelRight");
	var panelLeftSizeX = 0;
	if (panelLeft.length)
		panelLeftSizeX = panelLeft.width();
	var panelRightSizeX = 0;
	if (panelRight.length)
		panelRightSizeX = panelRight.width();

	var linesSizeX = totalSizeX;
	if (viewerType == 'mainViewer')
		linesSizeX = ((totalSizeX - panelLeftSizeX - panelRightSizeX)/ 100 * linesWidth) | 0;
	else
	{
		linesSizeX -= panelLeftSizeX;
		linesSizeX -= panelRightSizeX;
	}

	var detailSizeX = totalSizeX - linesSizeX;
	detailSizeX -= panelLeftSizeX + panelRightSizeX;

	viewerLinesContainer.width (linesSizeX);

	viewer.show ();

	var topRowSizeY = 0;
	if (viewerLinesSearch.is (':visible'))
		topRowSizeY += viewerLinesSearch.height ();

	var toolbarSizeY = 0;//viewerLinesToolbar.height ();

	var contentY = totalSizeY - topRowSizeY - toolbarSizeY - 0;

	var detailTop = viewer.position().top|0;
	var containerTop = 0;
	if (viewerLinesFWToolbar.length)
	{
		containerTop += viewerLinesFWToolbar.height();
		detailTop += viewerLinesFWToolbar.height();
		totalSizeY -= viewerLinesFWToolbar.height();

		viewerLinesFWToolbar.width(linesSizeX + detailSizeX + panelRightSizeX);
	}

	viewerLinesContainer.height (totalSizeY);
	viewerLinesContainer.css({top: containerTop});

	var bottomTabsHeight = 0;
	if (viewerLinesBottomTabs.is ('div'))
		bottomTabsHeight = viewerLinesBottomTabs.height ();
	viewerLinesList.height (contentY - 3 - bottomTabsHeight);

	if (viewerLinesList.hasClass ('dataGrid'))
	{
		viewerLinesList.find ('>table.dataGrid.main').floatThead('reflow');
	}

	if (viewerType == 'mainViewer')
	{
		viewerDetailList.width (detailSizeX - 10);
		viewerDetailList.height (totalSizeY);

		viewerDetailReport.width (detailSizeX - 10);
		viewerDetailReport.height (totalSizeY);

		viewerDetailReport.show ();

		var panelsTabsSizeY = 0;
		var viewerDetailPanelTabs = viewerDetailReport.find ('div.viewerPanelsTabs');
		if (viewerDetailPanelTabs.is ('DIV'))
			panelsTabsSizeY = viewerDetailPanelTabs.height() + 10;

		var viewerDetailPanelContent = viewerDetailReport.find ('div.e10-mv-lr-content');
		viewerDetailPanelContent.height (totalSizeY - panelsTabsSizeY - 5);

		viewerDetailList.css   ({position: 'absolute', left: (linesSizeX + viewerLinesContainer.position().left + 10)|0, top: detailTop});
		viewerDetailReport.css ({position: 'absolute', left: (linesSizeX + viewerLinesContainer.position().left + 10)|0, top: detailTop});

		viewerRefreshLayoutListDetail (viewer);
	}

	// -- fix viewer lines width
	viewerLinesList.find('>li').each (function () {
		var t1 = $(this).find ('div.df2-list-item-t1');
		var i1 = $(this).find ('span.df2-list-item-i1');
		t1.width(t1.parent().width() - i1.width() - 5);
	});
} // viewerRefreshLayout



function viewerRefreshLayoutListDetail (e)
{
	var viewerId = searchObjectId (e, 'viewer');
	var viewerDetailList = $("#" + viewerId + "Details");
	var sizeY = viewerDetailList.height ();

	var viewerDetailListHeader	= viewerDetailList.find ('div.e10-mv-ld-header');
	var viewerDetailListContent = viewerDetailList.find ('div.e10-mv-ld-content');
	var viewerDetailListTabs	= viewerDetailList.find ('div.e10-mv-ld-tabs');

	var contentSizeY = sizeY - viewerDetailListHeader.height () - 2;
	if (viewerDetailListTabs.is ('DIV'))
	{
		contentSizeY -= viewerDetailListTabs.height();
	}

	viewerDetailListContent.height (contentSizeY);
/*	viewerDetailListContent.find ('div.df2-viewer').each (function () {
			      var viewerId = $(this).attr ("id");
						initViewer (viewerId);
  });*/

	e10doSizeHints (viewerDetailListContent);

	viewerDetailListContent.find('table.default.main').floatThead({
		scrollContainer: function(table){
			return table.parent().parent();
		},
		useAbsolutePositioning: false,
		zIndex: 10100
	});

}

function initViewers (id) {
	var element = $('#'+id);
	element.find('div.df2-viewer').each(function () {
		var viewerId = $(this).attr("id");
		initViewer(viewerId);
	});
}


function e10viewerLineDetail (id, visibility)
{
	var viewer = $("#" + id);

	var detailElementId = viewer.attr('data-inline-source-element-detail-element');
	if (detailElementId !== undefined)
	{
		var detailElement = null;
		detailElement = $('#'+detailElementId);
		if (!visibility) {
			detailElement.html('');
			return;
		}
	}

	var viewerDetailList = $("#" + id + "Details");
	if (visibility && viewerDetailList.is (':visible'))
	{
		viewerRefreshLayoutListDetail (viewerDetailList);
		return;
	}
	else
	if (!visibility && !viewerDetailList.is (':visible'))
		return;

	var viewer = $('#' + id);
	var viewerLinesContainer = $("#" + id + " >div.e10-sv-body");
	var totalSizeX = viewer.parent().width();
	var linesSizeX = (((totalSizeX / 5) * 2) | 0) - 0;

	if (visibility)
	{
		$('#mainViewerDetailMenu').show ();

		viewerDetailList.show ();
		viewerRefreshLayoutListDetail (viewerDetailList);
	}
	else
	{
		var detailSizeX = totalSizeX - linesSizeX;
		$('#mainViewerDetailMenu').hide ();
		$('#mainBrowserRightBarButtonsEdit *').detach ();
		$('#mainBrowserRightBarButtonsAdd *').detach ();
		viewerDetailList.hide ();
		var detailToolbar = $('#e10-tm-detailbuttonbox');
		detailToolbar.find ('>*').remove ();
	}
} // e10viewerLineDetail


function e10viewerLoadNextData (ev)
{
	var e = $(ev.target);
	var viewer = e.parent().parent();

	var loadOnProgress = parseInt(viewer.attr ('data-loadonprogress'));
	if (loadOnProgress)
		return;

	var heightToEnd = e[0].scrollHeight - (e.scrollTop() + e.height ());
	if (heightToEnd <= 500)
	{
		viewer.attr ('data-loadonprogress', 1);
		window.requestAnimationFrame (function () {viewerRefreshLoadNext(viewer.attr('id'));});
	}
}

function e10viewerCloseDetail (e)
{
	var viewerId = searchObjectId (e, 'viewer');
	var viewer = $('#' + viewerId);

	if (viewer.attr ('data-toolbar') === 'e10-tm-detailbuttonbox')
		e10browserCheckToolbar ();

	var activeItem = $("#" + viewerId + "Items li.active");
	if (activeItem.is ('LI'))
	{
		viewerItemClick (activeItem);
		if (activeItem.hasClass ('active'))
			viewerItemClick (activeItem);
	}
	else
		e10viewerLineDetail (viewerId, false);
}

function e10viewerPrintDetail (e)
{
	var viewerId = searchObjectId (e, 'viewer');

	var reportHeader = $('#'+viewerId).find ('div.e10-mv-ld-header');
	var reportContent = $('#'+viewerId).find ('div.e10-reportContent');

	var printCode =
		"<div id='e10-print-page' class='e10-reportContentZTR' style='font-size: 70% !important;'>" +
			reportHeader.html() +
			reportContent.html() +
			"</div>";

	$('body').append (printCode);
	window.print();
	$('#e10-print-page').detach();

	mainBrowserRefreshLayout ('mainBrowser');
	reportWidgetRefreshLayout ();
}

function e10viewerNavPath (viewer, tableName, docPK, listItem)
{
	var viewerId = viewer.attr ('data-viewer-view-id');
	var detailId = 'default';

	var activeDetail = viewer.find ('div.e10-mv-ld-tabs >ul >li.active');
	if (activeDetail.is ('LI'))
	{
		detailId = activeDetail.attr ('data-detail');
	}
	else
	{
		activeDetail = $('#mainViewerDetailMenu li.active');
		if (activeDetail.is ('LI'))
			detailId = activeDetail.attr ('data-detail');
	}
	var apiPath = "/api/detail/" + tableName + "/" + viewerId + '/' + detailId + "/" + docPK + '?mismatch=1';

	return apiPath;
}


function viewerMenuLoadViewer (e)
{
	var objectType = e.attr ('data-object');
	if (objectType === undefined)
		return;

	var subMenu = $('#mainBrowserLeftMenuSubItems');
	if (subMenu.hasClass('open') && objectType !== 'subMenu' && (e.parent().attr('id') === 'mainListViewMenu' || e.parent().attr('id') === 'smallPanelMenu'))
	{
		subMenu.removeClass('open').addClass('closed');
	}

	$('#mainBrowserRightBarButtonsAdd *').detach();
	$('#mainBrowserRightBarButtonsEdit *').detach();

	if (objectType == 'viewer')
	{
		var tableName = e.attr ("data-table");
		var funcName = e.attr ("data-func");

		g_formId++;
		var newElementId = "mainListV" + g_formId;
		var urlPath = "/api/viewer/" + tableName + "/" + funcName  + "/html?fullCode=1&newElementId=" + newElementId;

		if (e.attr ('data-object-params'))
			urlPath += '&data-object-params='+e.attr ('data-object-params');

		var browserContent = $("#mainBrowserContent");

		e10setProgress (browserContent, 1);
		e10.server.get(urlPath, function(data)
		{
			mainBrowserRefreshLayout ('mainBrowser', data.object);
			e10browserSetContent (data.object);
			initViewer (newElementId, data.object);

			var viewerLines = $('#' + newElementId + 'Items');
			viewerLines.find('>li').each (function () {
				var t1 = $(this).find ('div.df2-list-item-t1');
				var i1 = $(this).find ('span.df2-list-item-i1');
				t1.width(t1.parent().width() - i1.width() - 5);
			});

			$('#e10-panel-topmenu').attr ('data-viewer', newElementId);
			if (!g_e10_touchMode)
				$('#' +newElementId + 'Search input').first ().focus ();
		});
		setNotificationBadges();

		return;
	}

	if (objectType == 'widget')
	{
		var className = e.attr ("data-class");
		if (className === 'Shipard.Report.WidgetReports' || className === 'e10.widgetReports')
		{
			var urlPath = "/api/widget/" + className + "/html?fullCode=1";

			if (e.attr ("data-subclass"))
				urlPath += '&subclass=' + e.attr ("data-subclass");
			if (e.attr ("data-subtype"))
				urlPath += '&subtype=' + e.attr ("data-subtype");

			var browserContent = $("#mainBrowserContent");

			e10setProgress (browserContent, 1);
			e10.server.get(urlPath, function(data)
			{
				mainBrowserRefreshLayout ('mainBrowser', data.object);
				e10browserSetContent (data.object);
				reportWidgetRefreshLayout ();
			});
		}
		else
			e10widgetRefresh (1);
		return;
	}

	if (objectType == 'wizard')
	{
		e10DocumentWizard (e);
		return;
	}

	if (objectType == 'subMenu')
	{
		if (subMenu.hasClass('closed'))
		{
			subMenu.removeClass('closed').addClass('open');
			mainBrowserRefreshLayout ('mainBrowser');
			$('#mainBrowserContent *').detach();
			$('#mainBrowserRightBarDetails').find ('>*').remove ().html ('');
		}
		var subMenuId = e.attr('data-mi-uid');
		subMenu.find ('>div').each (function () {
			$(this).hide().removeClass('active');
		});
		var subMenuPanel = $('#'+subMenuId);
		subMenuPanel.addClass('active').show();

		var activeMenuItem = subMenuPanel.find('>ul>li.active');
		if (!activeMenuItem.is('LI'))
		{
			activeMenuItem = subMenuPanel.find('>ul>li:first');
			activeMenuItem.addClass('active');
		}
		activeMenuItem.trigger('click');

		return;
	}

	alert ("viewerMenuLoadView: " + objectType);
} // viewerMenuLoadView


function viewerItemClick (e)
{
	if (!e.attr ('data-pk'))
		return;

	var viewerId = searchObjectId (e, 'viewer');
	var viewer = $('#' + viewerId);

	if (viewer.attr ('data-toolbar') === 'e10-tm-detailbuttonbox')
		e10browserCheckToolbar ();

	var viewId = viewer.attr ('data-viewer');
	var viewerType = viewer.attr ('data-viewertype');
	var viewerMainType = viewer.attr ('data-type');
	var viewerList = $('#' + viewerId + 'Items');
	var rowElement = viewerList.attr ('data-rowelement');

	var toolbarId = viewer.attr('data-toolbar');
	var detailToolbar = $('#'+toolbarId);


	//if (callUiHook ("viewerItemClick", tableName, $(e)))
	//	return;

	if (e.hasClass ('active'))
	{
		$('#'+toolbarId+' *').remove ();

//		if (viewerType == 'detailViewer')
//		{
//		}

		e.removeClass ('active');
		e10viewerLineDetail (viewerId, false);
		return;
	}

  $('#' + viewerId + 'Items '+rowElement).removeClass ("active");
  e.addClass ("active");

	if (viewerType == 'detailViewer')
	{
		e10viewerSetDetailInline (viewerId, e);
		return;
	}

	if (viewerType === 'miniViewer' && e.hasClass('e'))
	{
		e10viewerSetDetail (viewerId, e, 1);
		return;
	}

	if (viewerMainType === 'inline')
	{
		e10viewerSetDetail (viewerId, e);
		return;
	}

	if (viewerType != 'mainViewer')
		return;

	$('#mainBrowserRightBarButtonsEdit *').detach ();
	e10viewerSetDetail (viewerId, e);
} // viewerItemClick


function e10viewerSetDetail (viewerId, listItem, toolbarOnly)
{
	e10setProgressIndicator(viewerId, 1);

  var viewer = $("#" + viewerId);
  var tableName = searchParentAttr (listItem, 'data-table');

  var detail = $('#' + viewerId + 'Details');
	var detailHeader = detail.find ('div.e10-mv-ld-header');
	var detailContent = detail.find ('div.e10-mv-ld-content');

	var detailToolbar = null;
	var detailToolbarId = viewer.attr ('data-toolbar');
	if (detailToolbarId !== 'NONE')
		detailToolbar = $('#'+detailToolbarId);

  var docPK = listItem.attr ("data-pk");
	var urlPath = e10viewerNavPath (viewer, tableName, docPK, listItem);
	urlPath += '&fullCode=1';

	var paramsElement = $('#e10-tm-viewerbuttonbox');
	var params = df2collectFormData (paramsElement);
	if (params !== '')
		urlPath += '&' + params;

	//alert (urlPath);
	e10.server.setRemote(viewer);
	e10.server.get(urlPath, function(data)
	{
			detail.attr ('data-table', tableName);
			detail.attr ('data-pk', docPK);
			//alert (data.object.htmlContent);
			detail.attr ('data-addparams', '');

			if (toolbarOnly)
			{
				detailHeader.html (data.object.htmlHeader);
				if (detailToolbar !== null)
					detailToolbar.html (data.object.htmlButtons);
			}
			else
			{
				if (data.object.disabledDetails)
				{
					var tabs = $('#mainBrowserRightBarDetails>ul.df2-detail-menu.viewer');
					tabs.find ('>li').each (function () {
						var detailId = $(this).attr ("data-detail");

						if (data.object.disabledDetails.disabled.indexOf (detailId) === -1)
							$(this).show();
						else
							$(this).hide();
					});
					if (data.object.disabledDetails.activate)
					{
						tabs.find ('>li.active').removeClass('active');
						tabs.find ('>li[data-detail="'+data.object.disabledDetails.activate+'"]').addClass('active');
					}
				}

				detailHeader.html (data.object.htmlHeader);
				detailContent.html (data.object.htmlContent);
				if (detailToolbar !== null)
					detailToolbar.html (data.object.htmlButtons);

				$('#mainBrowserRightBar').attr ('data-viewer', data.object.detailViewerId);
				if (data.object.htmlCodeToolbarViewer)
					$('#mainBrowserRightBarButtonsAdd').html (data.object.htmlCodeToolbarViewer);

				e10viewerLineDetail (viewerId, true);
				e10DecorateFormWidgets (detailContent);

				initSubObjects(data);
			}
			e10setProgressIndicator(viewerId, 0);
	});
}

function e10viewerSetDetailInline (viewerId, listItem)
{
	var viewer = $("#" + viewerId);
	var tableName = searchParentAttr (listItem, 'data-table');

	var toolbarId = viewer.attr('data-toolbar');
	var detailToolbar = $('#'+toolbarId);

	var detailId = 'E10.TableViewDetail';
	if (viewer.attr ('data-inline-source-element-detail-id'))
		detailId = viewer.attr ('data-inline-source-element-detail-id');

	var detailElementId = viewer.attr('data-inline-source-element-detail-element');
	var detailElement = null;
	if (detailElementId !== undefined)
		detailElement = $('#'+detailElementId);

	var docPK = listItem.attr ("data-pk");
	var urlPath = "/api/detail/" + tableName + "/" + viewer.attr ('data-viewer-view-id') + '/' + detailId + "/" + docPK + '?mismatch=1&fullCode=1&embeddedViewer=1';

	e10.server.setRemote(viewer);
	e10.server.get(urlPath, function(data)
	{
		if (detailElement)
			detailElement.html (data.object.htmlContent);

		detailToolbar.html (data.object.htmlButtons);
	});
}

function viewerItemDblClick (e, disableEdit)
{
	var viewerId = searchObjectId (e, 'viewer');
  var viewer = $('#' + viewerId);
  var tableName = viewer.attr ("data-table");
  var docPK = e.attr ("data-pk");

	if (viewer.attr ('data-viewertype') !== 'mainViewer')
		return false;

	var dblClkBtn = $('#e10-tm-viewerbuttonbox').find ('button.dblclk');
	if (dblClkBtn.is('button'))
	{
		if (dblClkBtn.attr ('data-addparams'))
		{
			var newAddParams = dblClkBtn.attr ('data-addparams').replace("{pk}", docPK, "g");
			dblClkBtn.attr ('data-addparams', newAddParams);
		}
		dblClkBtn.click();
		return true;
	}

	if (disableEdit === 1)
		return false;

	//e10ViewerEditRow (e, viewerId);
	return false;
}


function viewerRefresh (e, focusPK, appendLines)
{
	var viewerId = searchObjectId(e, 'viewer');
	var viewer = $('#' + viewerId);
	var viewerType = viewer.attr('data-viewertype');

	var viewerLines = $('#' + viewerId + 'Items');
	if (!appendLines) {
		//e10setProgress (viewerLines, 1);
		e10viewerCloseDetail(e);
	}
	var tableName = viewer.attr("data-table");
	if (!tableName)
		return;

	var viewerOptions = viewer.attr("data-viewer-view-id");

	var urlPath = '';
	var rowsPageNumber = 0;
	var refreshRowsPageNumber = 0;
	if (appendLines)
		rowsPageNumber = parseInt(viewerLines.attr('data-rowspagenumber')) + 1;
	else {
		refreshRowsPageNumber = parseInt(viewerLines.attr('data-rowspagenumber'));
		viewerLines.attr('data-rowspagenumber', 0);
	}
	urlPath = '/api/viewer/' + tableName + '/' + viewerOptions + '/html' + "?callback=?";
	if (refreshRowsPageNumber)
		urlPath += "&refreshRowsPageNumber=" + refreshRowsPageNumber
	else
		urlPath += "&rowsPageNumber=" + rowsPageNumber;

	var queryParams = viewer.attr ("data-queryparams");
	if (queryParams)
		urlPath += '&' + queryParams;

  df2FillViewerLines (viewerId, urlPath, focusPK, appendLines);
}

function viewerRefreshLoadNext (viewerId)
{
	var viewer = $('#' + viewerId);

	var viewerLines = $('#' + viewerId + 'Items');
	var tableName = viewer.attr ("data-table");
	if (!tableName)
		return;

	var viewerOptions = viewer.attr ("data-viewer-view-id");

	var urlPath = '';
	var rowsPageNumber = parseInt (viewerLines.attr ('data-rowspagenumber')) + 1;

	urlPath = '/api/viewer/' + tableName + '/' + viewerOptions + '/html' + "?callback=?&rowsPageNumber=" + rowsPageNumber;

	var queryParams = viewer.attr ("data-queryparams");
	if (queryParams)
		urlPath += '&' + queryParams;

	df2FillViewerLines (viewerId, urlPath, undefined, 1);
}


function df2viewerFocusPK (viewerId, pk)
{
	var viewerLines = $('#' + viewerId + 'Items');
	var rowElement = viewerLines.attr ('data-rowelement');

	var oneRow = null;

	if (rowElement === 'tr')
	{
		oneRow = viewerLines.find('tr[data-pk="' + pk + '"]').first ();
		if (oneRow.length)
		{
			viewerLines.scrollTo(oneRow);
			viewerItemClick (oneRow);
		}
		return;
	}

  oneRow = $('#' + viewerId + 'Items >[data-pk="' + pk + '"]').first ();
  if (oneRow.length)
  {
    oneRow.parent().scrollTo (oneRow);
    viewerItemClick (oneRow);
  }
}

var g_incSearchTimer = 0;

function viewerKeyboard(e, event)
{
	var viewerId = '';
	if (e.hasClass('e10-inputRefId') || e.hasClass('e10-inputListSearch'))
		viewerId = searchObjectId($('#'+ e.attr ('data-sid')+' >div.df2-viewer'), 'viewer');
	else
		viewerId = searchObjectId (e, 'viewer');

	var viewer = $('#' + viewerId);

	if (event && event.type == 'keydown')
	{
		if (event.keyCode == 38)
		{ // arrowUp
			//event.stopImmediatePropagation();
			event.stopPropagation();
			event.preventDefault();
			viewerFocusLine (viewer, -1);
		}
		else
		if (event.keyCode == 40)
		{ // arrowDown
			event.stopPropagation();
			event.preventDefault();
			viewerFocusLine (viewer, 1);
		}
		else
		if (event.keyCode == 13)
		{ // arrowDown
			event.stopPropagation();
			event.preventDefault();
			viewerEnter(viewer, e, event);
		}
	}
}

function viewerFocusLine(viewer, dir)
{
	var rows = viewer.find ('ul.e10-viewer-list');
	var focusedRow = rows.find ('li.active');
	var checkFocus = 0;

	if (dir === 1)
	{ // down/next
		if (focusedRow.is ('LI'))
		{
			var nextRow = focusedRow.next ();
			if (nextRow.is('LI'))
			{
				focusedRow.removeClass ('active');
				nextRow.addClass ('active');
				checkFocus = 1;
			}
		}
		else
		{
			var firstRow = rows.find ('li').first();
			if (firstRow.is ('LI'))
			{
				firstRow.addClass ('active');
				checkFocus = 1;
			}
		}
	}
	else
	if (dir === -1)
	{ // up/prev
		if (focusedRow.is ('LI'))
		{
			var prevRow = focusedRow.prev ();
			if (prevRow.is ('LI'))
			{
				focusedRow.removeClass ('active');
				prevRow.addClass ('active');
				checkFocus = 1;
			}
			else
				return;
		}
	}

	if (checkFocus)
	{
		focusedRow = rows.find ('li.active');
		var frHeight = focusedRow.height();
		var ofsY = focusedRow.offset().top - rows.offset().top;
		var szeY = rows.height ();
		var heightToEnd = rows[0].scrollHeight - (rows.scrollTop() + rows.height ());


		if ((ofsY + frHeight)> rows.height())
			rows.scrollTop (rows.scrollTop() + frHeight*2);
		else
		if ((ofsY - frHeight) < 0)
			rows.scrollTop (rows.scrollTop() - frHeight*2);

		//console.log ('T1: ' + ofsY + ' --- ' + heightToEnd + ' .... ' + szeY);
	}
}

function viewerEnter(viewer, srcElement, event)
{
	var rows = viewer.find ('ul.e10-viewer-list');
	var focusedRow = rows.find ('li.active');
	if (viewer.attr ('data-combo-formid-target'))
	{

	}
	else
	if (viewer.attr ('data-combo-column-target'))
	{
		e10viewerDoComboClick (focusedRow, event);
		if (srcElement.hasClass('e10-inputRefId'))
			srcElement.focusNextInputField();
	}
	else
	{ // classic viewer
		//viewerItemClick (focusedRow);
		viewerItemDblClick (focusedRow);

		/*TODO: delete code?
		var viewId = viewer.attr ('data-viewer');
		var viewerType = viewer.attr ('data-viewertype');

		if (viewerType != 'mainViewer')
			return;

		$('#mainBrowserRightBarButtonsEdit *').detach ();
		e10viewerSetDetail (viewer.attr ('id'), focusedRow);
		*/
	}
}

function viewerIncSearch(e, event, force)
{
	var srcElement = null;
	if (e.hasClass('e10-inputRefId') || e.hasClass('e10-inputListSearch'))
		srcElement = $('#'+ e.attr ('data-sid')+' >div.df2-viewer');
	else
		srcElement = e;

	var viewerId = searchObjectId (srcElement, 'viewer');
	var viewer = $('#' + viewerId);
	var thisVal = e.val();
	if (e.attr ('data-lastvalue') && e.attr ('data-lastvalue') == thisVal && force !== undefined)
		return;

	var escape = 0;

	if (event && event.type == 'keyup')
	{
		if (e.attr('data-onenter'))
		{
			if (event.keyCode !== 13)
				escape = 1;
		}
		else
		if (event.keyCode == 13)
			escape = 1;

		if (!e.attr ('data-lastvalue') && thisVal == '')
			return;

		if (event.keyCode == 38 || event.keyCode == 40 || escape)
			return;
	}

	if (viewer.attr ('data-loadonprogress') && viewer.attr ('data-loadonprogress') != 0)
	{
		g_incSearchTimer = setTimeout (function () {viewerIncSearch (e)}, 100);
		return;
	}

	if (g_incSearchTimer)
	{
		clearTimeout (g_incSearchTimer);
		g_incSearchTimer = 0;
	}

	if (escape)
		return;

	e.attr ('data-lastvalue', thisVal);
	viewer.attr ('data-loadonprogress', 1);
	viewerRefresh (srcElement);
}

function df2CreateDialog (urlPath)
{
}

function df2ViewerAction (event, e)
{
	var actionType = e.attr ("data-action");
	if (actionType == "fulltextsearch")
		return;
	if (actionType == "fulltextsearchclear")
	{
		var inp = e.parent().parent().find ("input").first();
		inp.val ("").focus();
		viewerIncSearch (inp);
		return;
	}
	var table = searchParentAttr (e, "data-table");

	if (callUiHook (actionType, table, $(e)))
		return;

	if (actionType == "newform")
	{
		var copyDoc = 0;
		var doIt = 1;
		if (event.shiftKey || event.altKey || e.attr('data-copyfrom'))
		{
			copyDoc = 1;
			if (!event.shiftKey && !event.altKey)
				doIt = confirm("Opravdu udělat kopii dokumentu?");
            else if (event.altKey)
            {
                doIt = confirm("Opravdu udělat kopii dokumentu včetně příloh?");
                if (doIt)
                    copyDoc = 2;
            }
		}
		if (doIt)
			e10ViewerAddRow (e, copyDoc);
		return;
	}

	if (actionType == "editform")
	{
		e10ViewerEditRow (e);
		return;
	}

	if (actionType == "saveform")
	{
		if (event.shiftKey && e.attr('data-noclose') == '1')
			e.removeAttr('data-noclose');

		if (!df2saveForm (e))
			setTimeout (function () {df2ViewerAction (event, e)}, 50);
		return;
	}

	if (actionType == "cancelform")
	{
		e10ViewerCancelForm (e);
		return;
	}

	if (actionType == "deleteform")
	{
		e10ViewerDeleteRow (e, 'delete');
		return;
	}

	if (actionType == "undeleteform")
	{
		e10ViewerDeleteRow (e, 'undelete');
		return;
	}

	if (actionType == "close-lv-detail")
	{
		e10viewerCloseDetail (e);
		return;
	}

	if (actionType == "print-lv-detail")
	{
		e10viewerPrintDetail (e);
		return;
	}

	if (actionType == "print")
	{
		e10ViewerPrintDetail (e);
		return;
	}

	if (actionType == "printdirect")
	{
		e10ViewerPrintDetailDirect (e);
		return;
	}

	if (actionType == "printviewer")
	{
		e10ViewerPrint (e);
		return;
	}

	if (actionType == "help")
	{
		e10Help (e);
		return;
	}

	if (actionType == "close-help")
	{
		e10CloseHelp (e);
		return;
	}

	if (actionType == "addwizard")
	{
		e10ViewerAddWizard (e);
		return;
	}

    if (actionType === "window")
    {
        e10ViewerWindow (e);
        return;
    }

	if (actionType == "wizardnext")
	{
		e10WizardNext (e);
		//alert ("wizard next");
		return;
	}

	if (actionType === "open-link")
	{
		var url = e.attr ('data-url-download');
		var usePopup = 0;

		var shift = 0;
		if ((event && event.altKey))
			shift = 1;

		var withShift = 'popup';
		if (e.attr('data-with-shift') === 'tab')
			usePopup = !shift;
		else if (e.attr('data-with-shift') === 'popup' || e.attr('data-with-shift') === undefined)
			usePopup = shift;

		var popUpId = 'default';
		if (e.attr('data-popup-id'))
			popUpId = e.attr('data-popup-id');

		if (popUpId === 'NEW-TAB')
		{
			window.open (url, '_blank');
			return;
		}
		if (popUpId === 'THIS-TAB')
		{
			document.location.href = url;
			return;
		}

		if (usePopup)
		{
			var height = ((screen.availHeight * window.devicePixelRatio) * 0.8) | 0;
			var width = (height * .7 + 50) | 0;//(screen.width * 0.85) | 0;

			var nw = window.open(url, "e10pw"+popUpId, "location=no,status=no,width=" + width + ",height=" + height);
			nw.focus();
			return;
		}

		window.open (url, "e10nt"+popUpId);
		return;
	}

	if (actionType === "open-popup")
	{
		var popUpId = 'e10pw-default';
		if (e.attr('data-popup-id'))
			popUpId = e.attr('data-popup-id');
		var popUpUrl = e.attr ('data-popup-url');
		if (!popUpUrl)
		{
			return;
		}

		var width = (screen.width * 0.85) | 0;
		var height = (screen.height * 0.75) | 0;

		if (e.attr ('data-popup-width') !== undefined)
			width = ((screen.availWidth * window.devicePixelRatio) * parseFloat(e.attr ('data-popup-width'))) | 0;
		if (!width)
			width = (screen.width * 0.85) | 0;

		if (e.attr ('data-popup-height') !== undefined)
			height = ((screen.availHeight * window.devicePixelRatio) * parseFloat(e.attr ('data-popup-height'))) | 0;
		if (!height)
			height = (screen.height * 0.75) | 0;

		window.open(popUpUrl, "e10pw"+popUpId, "location=no,status=no,width=" + width + ",height=" + height);

		return;
	}

	if (actionType === 'open-iframe-app')
	{
		var url = e.attr ('data-popup-url');
		if (!url)
		{
			return;
		}
		var embeddAppHtml = "<div id='embeddAppBlocker' class='iframe-app' style='position: fixed; z-index: 31000; background-color: #000; opacity: .5;'></div>" +
			"<iframe id='embeddApp' frameborder='0' src='"+url+"' style='padding: 0; background-color: white; position: absolute;z-index: 32000; box-shadow: 0 5px 5px rgba(0, 0, 0, 0.5); border: 1px solid navy; overflow: hidden;'></iframe>";

		$('body').append (embeddAppHtml);
		var embedAppBlocker = $('#embeddAppBlocker');
		var embedApp = $('#embeddApp');

		var leftOffset = 5;
		var rightOffset = 5;
		var topOffset = 5;
		var bottomOffset = 5;
		embedAppBlocker.css ({left: 0, top: 0, width: window.innerWidth, height: $(window).height()});
		embedApp.css ({left: leftOffset, top: topOffset, width: window.innerWidth - leftOffset - rightOffset - 5, height: $(window).height() - topOffset - bottomOffset});


	}

	if (actionType === "sidebar-tab")
	{
		var activeTab = e.parent().find('>li.active');
		var activeTabId = activeTab.attr('data-tab-id');
		activeTab.removeClass('active');
		e.addClass('active');

		$('#'+activeTabId).removeClass('active');

		var activeTabContent = $('#'+e.attr('data-tab-id'));
		activeTabContent.addClass('active');

		activeTabContent.find ('div.df2-viewer').each (function () {
			var viewerId = $(this).attr ("id");
			viewerRefreshLayout(viewerId);
		});


		return;
	}

	if (actionType === 'moveUp' || actionType === 'moveDown')
	{
		e10ViewerSwapRows (e, actionType);
		return;
	}

	if (actionType === 'viewer-inline-action')
	{
		e10ViewerInlineAction (e);
		return;
	}

	if (actionType === 'inline-action')
	{
		e10InlineAction (e);
		return;
	}

	e10ViewerRowAction (e);

	//alert ("action: " + actionType);
}

function viewerAddParams (viewer, button)
{
	var ap = viewer.attr ('data-addparams');
	var bottomTab = viewer.find ('div.viewerBottomTabs >span.active');
	if (bottomTab.is ('span'))
	{
		var btap = bottomTab.attr ('data-addparams');
		if (btap !== undefined)
		{
			if (ap != '')
				ap += '&';
			ap += btap;
		}
	}

	var leftPanelList = viewer.find ('div.e10-sv-left div.title.active');

	if (leftPanelList.length && leftPanelList.attr ('data-addparams'))
	{
		var btap = leftPanelList.attr ('data-addparams');
		if (btap !== undefined)
		{
			if (ap != '')
				ap += '&';
			ap += btap;
		}
	}

	if (button)
	{
		var btap = button.attr ('data-addparams');
		if (btap !== undefined)
		{
			if (ap != '')
				ap += '&';
			ap += btap;
		}
	}

	return ap;
}


function e10ViewerAddWizard (e)
{
	var viewerId = searchParentAttr (e, 'data-viewer');
	var viewer = $('#' + viewerId);
	var table = searchParentAttr (viewer, "data-table");
	var addParams = viewerAddParams (viewer, e);
	var wizardClass = e.attr ('data-class');

	var srcObjectType = 'viewer';
	var srcObjectId = viewerId;

	if (e.attr ('data-srcobjecttype') !== undefined)
		srcObjectType = e.attr ('data-srcobjecttype');
	if (e.attr ('data-srcobjectid') !== undefined)
		srcObjectId = e.attr ('data-srcobjectid');

	var fullTextSearch = viewer.find ("div.e10-sv-search input").first().val ();

	var focusedPK = '';
	var focusedRow = viewer.find ('ul.e10-viewer-list > li.active');
	if (focusedRow.is ('li'))
		focusedPK = focusedRow.attr ('data-pk');
	else
	if (e.attr ('data-pk'))
		focusedPK = e.attr ('data-pk');

	g_formId++;
	var newElementId = "mainEditF" + g_formId;

	var url = "/api/wizard/" + wizardClass + "/0?callback=?&fullTextSearch=" + fullTextSearch + "&newFormId=" + newElementId;
	if (addParams)
		url += '&' + addParams;
	if (focusedPK != '')
		url += '&focusedPK=' + focusedPK;

	var params = elementAttributes (e, 'data-param');
	if (params)
		url += '&' + params;

	var postData = {};
	var dataFormElementId = 'mainBrowserTopBar';
	if (e.attr('data-form-element-id') != undefined)
		dataFormElementId = e.attr('data-form-element-id');
	var dataForm = $('#'+dataFormElementId);
	e10collectFormData (dataForm, postData);

  e10.server.post(url, postData, function(data) {
			e10ViewerCreateEditForm (data, newElementId, srcObjectType, srcObjectId);
  });
}

function e10ViewerWindow (e)
{
    var viewerId = searchParentAttr (e, 'data-viewer');
    var viewer = $('#' + viewerId);
    var table = searchParentAttr (viewer, "data-table");
    var addParams = viewerAddParams (viewer, e);
    var wizardClass = e.attr ('data-class');

    var fullTextSearch = viewer.find ("div.e10-sv-search input").first().val ();

    var focusedPK = '';
    var focusedRow = viewer.find ('ul.e10-viewer-list > li.active');
    if (focusedRow.is ('li'))
        focusedPK = focusedRow.attr ('data-pk');
    else
    if (e.attr ('data-pk'))
        focusedPK = e.attr ('data-pk');

    g_formId++;
    var newElementId = "mainEditF" + g_formId;

    var url = "/api/window/" + wizardClass + "/0?callback=?&fullTextSearch=" + fullTextSearch + "&newFormId=" + newElementId;
    if (addParams)
        url += '&' + addParams;
    if (focusedPK != '')
        url += '&focusedPK=' + focusedPK;

    var postData = {};
    var dataForm = $('#mainBrowserTopBar');
    e10collectFormData (dataForm, postData);

    e10.server.post(url, postData, function(data) {
        e10ViewerCreateEditForm (data, newElementId, 'viewer', viewerId);
    });
}

function e10AppendFormRow (button)
{
	var table = searchParentAttr (button, "data-table");
	var list = button.attr ('data-list');
	var pk = searchParentAttr (button, "data-pk");
	var rowNumber = button.attr ('data-row');

	var rows = button.parent().parent();
	var url = httpApiRootPath + "/api/list/" + table + '/' + list + '/append/' + pk  + "?callback=?&rowNumber=" + rowNumber;

	var options = {"appendRowList": list, "appendBlankRow": 1};
	e10SaveOnChange (button, options);
}

function e10AppendFormProperty (button)
{
	var table = searchParentAttr (button, "data-table");
	var list = button.attr ('data-list');
	var pk = searchParentAttr (button, "data-pk");

	var row = button.parent().parent();
	var rows = row.parent();

  var formElementId = searchObjectId (button, 'form');

	var rowNumber = rows.find('tr.e10-property').length + 100;
	var propertyId = button.attr ('data-propid');
	var groupId = button.attr ('data-groupid');

	var url = httpApiRootPath + "/api/list/" + table + '/' + list + '/append/' + pk  +
							"?callback=?&rowNumber=" + rowNumber + '&propId=' + propertyId + '&groupId=' + groupId +
							'&formElementId=' + formElementId;

	var jqxhr = $.getJSON (url, function(data) {
			//alert (JSON.stringify (data));
			row.after (data.object.htmlContent);
			e10DecorateFormWidgets (rows);
			row.next().find ('input.e10-ef-w50').focus();
  }).error(function() {alert("error 12: content not loaded (" + url + ")");});
}

function e10ViewerAddRow (e, copyDoc)
{
	var viewerId = searchParentAttr(e, 'data-viewer');
	var viewer = $('#'+viewerId);

	var table = searchParentAttr (viewer, "data-table");
	var addParams = viewerAddParams (viewer, e);

	var fullTextSearch = viewer.find ("div.e10-sv-search input").first().val ();

	g_formId++;
	var newElementId = "mainEditF" + g_formId;

	var focusedPK = '';

	var viewerLines = $('#' + viewerId + 'Items');
	var rowElement = viewerLines.attr ('data-rowelement');
	if (rowElement === 'tr')
	{
		var focusedRow = viewerLines.find ('>table >tbody >tr.active');
		if (focusedRow.is ('tr'))
			focusedPK = focusedRow.attr ('data-pk');
	}
	else
	if (rowElement === 'li')
	{
		var focusedRow = viewerLines.find ('>li.active');
		if (focusedRow.is ('li'))
			focusedPK = focusedRow.attr ('data-pk');
	}

	var url = "/api/form/" + table + "/new/?callback=?&fullTextSearch=" + fullTextSearch + "&newFormId=" + newElementId;
	if (addParams)
		url += '&' + addParams;
	if (focusedPK != '')
		url += '&focusedPK=' + focusedPK + '&copyDoc=' + copyDoc;
	if (e.attr ('data-create-params') !== undefined)
		url += '&'+e.attr ('data-create-params')+'&createDoc=1';
	url += "&viewPortWidth="+document.documentElement.clientWidth;

	var inlineSourceElementType = viewer.attr('data-inline-source-element-type');
	var inlineSourceElementId = viewer.attr('data-inline-source-element-id');
	var inlineSourceElement = null;
	if (inlineSourceElementType)
		inlineSourceElement = {'type': inlineSourceElementType, 'id': inlineSourceElementId};

  e10.server.post(url, {}, function(data) {
			e10ViewerCreateEditForm (data, newElementId, 'viewer', viewerId, inlineSourceElement);
  });
}


function e10ViewerEditRow (e)
{
	var viewerId = searchParentAttr(e, 'data-viewer');
	var viewer = $('#'+viewerId);

	var table = searchParentAttr (e, "data-table");
	var pk = searchParentAttr (e, "data-pk");

	g_formId++;
	var newElementId = "mainEditF" + g_formId;
	var url = "/api/form/" + table + "/edit/" + pk + "?callback=?&newFormId=" + newElementId;
	url += "&viewPortWidth="+document.documentElement.clientWidth;
	var inlineSourceElementType = viewer.attr('data-inline-source-element-type');
	var inlineSourceElementId = viewer.attr('data-inline-source-element-id');
	var inlineSourceElement = null;
	if (inlineSourceElementType)
		inlineSourceElement = {'type': inlineSourceElementType, 'id': inlineSourceElementId};

	e10.server.setRemote(e);
  e10.server.post(url, {}, function(data) {
			e10ViewerCreateEditForm (data, newElementId, 'viewer', viewerId, inlineSourceElement);
  });
}


function e10ViewerDeleteRow (e, action)
{
	var viewerId = searchParentAttr(e, 'data-viewer');
	var viewer = $('#'+viewerId);

	var table = searchParentAttr (e, "data-table");
	var pk = searchParentAttr (e, "data-pk");

	var url = "/api/form/" + table + "/" + action + "/" + pk + "?callback=?";
	e10.server.post(url, {}, function(data) {
			viewerRefresh ($('#'+viewerId));
  });
}

function e10ViewerSwapRows (e, action)
{
	var viewer = null;
	var viewerId = '';

	viewer = searchParentAttrElement(e, 'data-viewer');
	viewerId = viewer.attr('id');

	var viewerLines = $('#' + viewerId + 'Items');

	var viewerMode = viewer.attr('data-mode');
	var rowElement = viewerLines.attr ('data-rowelement');

	var activeRow = viewerLines.find (rowElement+'.r.active:first');

	if (!activeRow.length)
	{

		return;
	}

	var firstPK = activeRow.attr ('data-pk');
	var secondPK = 0;

	if (action === 'moveDown') {
		var nextRow = activeRow.next();
		if (nextRow.length) {
			secondPK = nextRow.attr ('data-pk');
			nextRow.after(activeRow);
		}
	}
	else if (action === 'moveUp') {
		var prevRow = activeRow.prev();
		if (prevRow.length) {
			secondPK = prevRow.attr ('data-pk');
			prevRow.before(activeRow);
		}
	}

	if (!firstPK || !secondPK)
	{

		return;
	}

	var table = searchParentAttr (e, 'data-table');
	var requestParams = {};
	requestParams['object-class-id'] = 'lib.core.ui.SwapViewerRows';
	requestParams['table'] = table;
	requestParams['firstPK'] = firstPK;
	requestParams['secondPK'] = secondPK;

	e10.server.api(requestParams, function(data) {

	});
}

function e10ViewerPrintDetail (e)
{
	var table = searchParentAttr (e, "data-table");
	var reportClass = e.attr ('data-report');
	var pk = searchParentAttr (e, "data-pk");
	var printer = searchParentAttr (e, "data-printer");
	if (printer === undefined)
		printer = 0;

	var url = httpApiRootPath + '/api/formreport/' + table + '/' + reportClass + '/' + pk + '?vvv='+Date.now();

	var params = elementAttributes (e, 'data-param');
	if (params)
		url += '&' + params;

	url += '&printer=' + printer;

	if (e.attr ('data-saveas') !== undefined)
	{
		if (params)
			url += '&';
		else
			url += '&';
		url += 'saveas=' + e.attr('data-saveas');
		window.location = url;
	}
	else
	{
		if (e10embedded)
		{
			window.location = url;
		}
		else
		{
			var width = (screen.width * 0.85) | 0;
			var height = (screen.height * 0.85) | 0;
			window.open(url, "test", "location=no,status=no,resizable,width=" + width + ",height=" + height);
		}
	}
}

function e10ViewerPrintDetailDirect (e)
{
	var viewerId = searchParentAttr (e, 'data-viewer');
	var table = searchParentAttr (e, "data-table");
	var reportClass = e.attr ('data-report');
	var pk = searchParentAttr (e, "data-pk");
	var printer = e.attr ('data-printer');
	var url = httpApiRootPath + '/api/formreport/' + table + '/' + reportClass + '/' + pk + "?printer=" + printer;
	if (e.attr ('data-print') !== undefined)
		url += '&print='+e.attr ('data-print');
	$.get (url);
}

function e10ViewerPrint (e)
{
	var viewerId = searchObjectId (e, 'viewer');
	var viewer = $('#' + viewerId);

	var tableName = viewer.attr ("data-table");
	if (!tableName)
		return;

	e10setProgressIndicator (viewerId, 1);

	var viewerOptions = viewer.attr ("data-viewer-view-id");

	var rowsPageNumber = -1;

	var format = e.attr('data-format');
	var urlPath = '/api/viewer/' + tableName + '/' + viewerOptions + '/' +
								"?callback=?&rowsPageNumber=" + rowsPageNumber + '&saveas=' + format;

	var queryParams = viewer.attr ("data-queryparams");
	if (queryParams)
		urlPath += '&' + queryParams;


	var form = $('#' + viewerId + 'Search');
	var formPostData = df2collectFormData (form);

	var panelRight = $('#' + viewerId + 'PanelRight');
	if (panelRight.length)
		formPostData += df2collectFormData (panelRight, 1);

	var bottomTab = viewer.find ("div.viewerBottomTabs input");
	if (bottomTab.is ('INPUT'))
		formPostData += "&bottomTab=" + encodeURI (bottomTab.val ());

	var qryForm = $('#' + viewerId + 'Report');
	formPostData += df2collectFormData (qryForm, 1);

	e10.server.postForm(urlPath, formPostData, function(data)
	{
			e10setProgressIndicator (viewerId, 0);

			if (format === 'pdf') {
				var width = (screen.width * 0.85) | 0;
				var height = (screen.height * 0.85) | 0;
				window.open(httpApiRootPath + data.object.fileUrl, "test", "location=no,status=no,resizable,width=" + width + ",height=" + height);
			}
			else
				window.location = httpApiRootPath + data.object.fileUrl;
	});
}

function e10ViewerRowAction (e)
{
	var viewerId = searchParentAttr (e, 'data-viewer');
	var table = searchParentAttr (e, "data-table");
	var pk = searchParentAttr (e, "data-pk");
	var action = e.attr ('data-action');

	var url = '/api/form/' + table + '/' + action + '/' + pk + '?callback=?';

	e10.server.get(url, function(data) {
			viewerRefresh ($('#'+viewerId));
  });
}

function e10ViewerInlineAction (e)
{
	if (e.attr('data-object-class-id') === undefined)
		return;

	var viewerId = searchParentAttr (e, 'data-viewer');

	var requestParams = {};
	requestParams['object-class-id'] = e.attr('data-object-class-id');
	requestParams['action-type'] = e.attr('data-action-type');
	elementPrefixedAttributes (e, 'data-action-param-', requestParams);
	if (e.attr('data-pk') !== undefined)
		requestParams['pk'] = e.attr('data-pk');

	e10.server.api(requestParams, function(data) {
		if (data.reloadNotifications === 1)
			e10NCReset();

		viewerRefresh ($('#'+viewerId));
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
		if (data.reloadNotifications === 1)
			e10NCReset();

		if (e.parent().hasClass('btn-group'))
		{
			e.parent().find('>button.active').removeClass('active');
			e.addClass('active');
		}
	});
}


function e10FormNeedSave (element, saveType)
{
	e10FormSetAsModified (element);
	if (saveType === -1)
	{
		e10SaveOnChange (element);
	}

	if (element.is('input') && element.attr('type') === 'radio')
	{
		element.parent().parent().find('>div.active').removeClass('active');
		if (element[0].checked)
			element.parent().addClass('active');
	}
}

function e10FormRowAction (event, button)
{
	var action = button.attr ('data-action');

	if (action === "delete")
	{
		e10FormRowActionDelete (button);
		return;
	}
	if (action === "insert")
	{
		e10FormRowActionInsert (button);
		return;
	}
	if (action === "up")
	{
		e10FormRowActionUp (button);
		return;
	}
	if (action === "down")
	{
		e10FormRowActionDown (button);
		return;
	}

	alert ("e10FormRowAction: " + action);
}

function e10FormRowActionDelete (button)
{
	var row = null;
	var rows = null;

	if (button.parent().hasClass('e10-row-menu-btns'))
		row = button.parent().parent().parent().parent();
	else
		row = button.parent().parent();

	rows = row.parent().parent();

	row.detach();
	e10FormNeedSave(rows, -1);
}

function e10FormRowActionInsert (button)
{
	var row = button.parent().parent().parent().parent();
	var rows = row.parent().parent();

	var table = searchParentAttr (button, "data-table");
	var list = rows.attr ('data-list');
	var pk = searchParentAttr (button, "data-pk");
	var rowNumber = button.attr ('data-row');
	var options = {"appendRowList": list, "appendBlankRow": 1, "rowOrder": button.attr('data-row-order'), "rowNumber": rowNumber};
	e10SaveOnChange (button, options);
}

function e10FormRowActionUp (button)
{
	var rowCurrent = button.parent().parent().parent().parent();
	var rowPrev = rowCurrent.prev();
	var rows = rowCurrent.parent().parent();

	var list = rows.attr ('data-list');
	var rowOrderColumnId = 'rowOrder';
	var rowOrderCurrentInputName = 'lists.'+list+'.'+rowCurrent.attr('data-rowid')+'.'+rowOrderColumnId;
	var rowOrderCurrentInput = rowCurrent.find('input[name="'+rowOrderCurrentInputName+'"]');
	var rowOrderCurrent = rowOrderCurrentInput.val();
	var rowOrderPrevInputName = 'lists.'+list+'.'+rowPrev.attr('data-rowid')+'.'+rowOrderColumnId;
	var rowOrderPrevInput = rowPrev.find('input[name="'+rowOrderPrevInputName+'"]');
	var rowOrderPrev = rowOrderPrevInput.val();

	rowOrderCurrentInput.val(rowOrderPrev);
	rowOrderPrevInput.val(rowOrderCurrent);

	e10FormNeedSave(rows, -1);
}

function e10FormRowActionDown (button)
{
	var rowCurrent = button.parent().parent().parent().parent();
	var rowNext = rowCurrent.next();
	var rows = rowCurrent.parent().parent();

	var list = rows.attr ('data-list');
	var rowOrderColumnId = 'rowOrder';
	var rowOrderCurrentInputName = 'lists.'+list+'.'+rowCurrent.attr('data-rowid')+'.'+rowOrderColumnId;
	var rowOrderCurrentInput = rowCurrent.find('input[name="'+rowOrderCurrentInputName+'"]');
	var rowOrderCurrent = rowOrderCurrentInput.val();
	var rowOrderNextInputName = 'lists.'+list+'.'+rowNext.attr('data-rowid')+'.'+rowOrderColumnId;
	var rowOrderNextInput = rowNext.find('input[name="'+rowOrderNextInputName+'"]');
	var rowOrderNext = rowOrderNextInput.val();

	rowOrderCurrentInput.val(rowOrderNext);
	rowOrderNextInput.val(rowOrderCurrent);

	e10FormNeedSave(rows, -1);
}

function e10FormSaveBegin (element, formId)
{
	if (element.attr ('data-fid') || formId)
	{
		var fid = (formId) ? formId : element.attr ('data-fid');
		var saveBtn = $('#'+fid+'Save');

		if (saveBtn.attr ('data-insave') === '1')
			return false;
		saveBtn.attr ('data-insave', '1');
		saveBtn.html ("<i class='fa fa-upload'></i> Ukládá se").prop('disabled', true);
		return true;
	}

	return false;
}

function e10FormSetAsModified (button)
{
	if (button.attr('data-miid'))
		button = $('#'+button.attr('data-miid'));

	if (button.attr ('data-fid'))
	{
		var fid = button.attr ('data-fid');
		var saveBtn = $('#'+fid+'Save');
		saveBtn.html ("<i class='fa fa-download'></i> Uložit").prop('disabled', false);
	}
}

function e10FormSetAsSaved (fid)
{
	var saveBtn = $('#'+fid+'Save');
	saveBtn.html ("<i class='fa fa-check'></i> Uloženo").prop('disabled', true);
	saveBtn.attr ('data-insave', '0');
}

function e10SaveOnChange (input, saveOptions)
{
  var id = searchObjectId (input, 'form');

	if (!e10FormSaveBegin(input, id))
		return false;

	//alert ('e10SaveOnChange');

	var e = $("#" + id);
	var codeTarget = e.parent().parent();
  //var formElement = document.getElementById (id);
  var formData = df2collectEditFormData (e, $.myFormsData [id]);
	if (saveOptions)
		formData.saveOptions = saveOptions;

	if (input.attr ('name'))
		formData.changedInput = input.attr ('name');
	if (input.attr ('data-softchange') === '1')
		formData.softChangeInput = 1;

	var focusedId = input.attr ('id');

	var table = e.attr ("data-table");

	var url = "/api/form/" + table + "/save?callback=?&newFormId=" + id;
	url += "&viewPortWidth="+document.documentElement.clientWidth;
	e10.server.post(url, formData, function(data)
	{
			codeTarget.html (data.mainCode);

			var sidebar = $("#" + id + 'Sidebar');

			var header = $("#" + id + 'Header');
			header.html (data.htmlHeader);

            if (data.flags.resetButtons !== undefined)
            {
                var buttons = $("#" + id + 'Buttons');
                buttons.html(data.buttonsCode);
            }

			df2FormsSetData (id, data);

			var form = $("#" + id);
			e10DecorateFormWidgets (form);
			e10doSizeHints (form);

			//alert (data.sidebarCode);

		/* TODO: delete code?
			if (data.sidebarCode != null)
			{
				sidebar.find ('*').detach();
				sidebar.html (data.sidebarCode);
				sidebar.find ('div.df2-viewer').each (function () {
							var viewerId = $(this).attr ("id");
							initViewer (viewerId);
				});
			}
		*/

			if (data.saveResult !== undefined && data.saveResult.modifiedRow !== undefined)
			{
				var rwsel = codeTarget.find ("div.e10-rows.e10-rows-" + data.saveResult.modifiedList + " ul");
				var mr = $(rwsel.find (">li")[data.saveResult.modifiedRow]);
				mr.addClass ('lastModifiedRow');
				rwsel.parent().animate({scrollTop: rwsel.find (">li.lastModifiedRow").offset().top - rwsel.offset().top}, 10);
				mr.find ('input:visible').first().focus().select();
			}
			else
			if (saveOptions)
			{
				var rwsel = codeTarget.find ("div.e10-rows.e10-rows-" + saveOptions.appendRowList + " ul");
				rwsel.find (">li.e10-row").last().find ("input:visible").first().focus().select();
			}
			else
			{
				//$('#' + g_focusedInputId).focusNextInputField();
				$('#' + g_focusedInputId).focus().select();
			}

			e10FormSetAsSaved (id);
  });

	return true;
}

function e10DecorateFormWidgets (e)
{
	e.find ("ul.e10-form-maintabs").each (function () {initMainTabs ($(this).attr ("id"))});
	e.find ("ul.e10-form-tabs").each (function () {initTabs ($(this).attr ("id"))});

  e.find ("input.e10-inputDate").datepicker ({duration: 50, beforeShow: function(i) { if ($(i).attr('readonly')) { return false; } }});
	e.find ("select.e10-inputEnumMultiple").each (function () {$(this).chosen();});
}



var g_openModals = Array ();

function e10ViewerCreateEditForm (data, formId, srcObjectType, srcObjectId, inlineSourceElement)
{
	var sidebarPos = data.flags.sidebarPos;
	var infoPanelPos = (data.flags.infoPanelPos === undefined) ? 0 : data.flags.infoPanelPos;
	var infoPanelWidth = (data.flags.infoPanelWidth === undefined) ? null : data.flags.infoPanelWidth;

	var flags = "";
  for (var colName in data.flags)
		flags += " data-flag-" + colName + "='" + data.flags [colName] + "'";

	var sidebarElementId = formId + 'Sidebar';
	var formClass = 'e10-ef-form';
	var newFormHtml = "<div id='" + formId+ "FormEnv' class='e10-ef-env'";

	if (inlineSourceElement)
	{
		newFormHtml += " data-inline-source-element-type='"+inlineSourceElement.type+"'";
		newFormHtml += " data-inline-source-element-id='"+inlineSourceElement.id+"'";
		formClass += ' e10-ef-form-'+inlineSourceElement.type;
	}
	else
		formClass += ' e10-ef-form-core';

	newFormHtml += "></div>";

	newFormHtml +=      "<div id='" + formId + "Form' class='"+formClass+"' data-object='modal' data-formId='"+formId+"' data-srcObjectType='" + srcObjectType + "' data-srcObjectId='" + srcObjectId + "' data-sidebar-element-id='" + sidebarElementId + "' " + flags + ">" +
											"<div id='" + formId + "Header' class='e10-ef-header'></div>" +
											"<div id='" + formId + "Content' class='e10-ef-content' data-e10mxw='1'></div>" +
											"<div id='" + formId + "Buttons' class='e10-ef-buttons'></div>";

	if (sidebarPos !== 3)
		newFormHtml += "<div id='" + formId + "Sidebar' class='e10-ef-sidebar e10-ef-sidebar-" + sidebarPos + "'></div>";

	if (infoPanelPos != 0)
	{
		newFormHtml += "<div style='overflow-y: auto; width: " + infoPanelWidth + ";' id='" + formId + "InfoPanel' class='e10-ef-infoPanel e10-ef-infoPanel-" + infoPanelPos + "'></div>";
	}
	newFormHtml += "</div>";

	if (sidebarPos === 3)
		newFormHtml += "<div id='" + formId + "Sidebar' class='e10-ef-sidebar e10-ef-sidebar-" + sidebarPos + "'></div>";

	var mainBrowser = $('#mainBrowser');
	var mainBrowserContent = $('#mainBrowserContent');

	var newEditForm = $('body').append (newFormHtml);
	g_openModals.push (formId+'Form');

	var formEnv = $('#' + formId + 'FormEnv');
	var formForm = $('#' + formId + 'Form');

	//var leftOffset = 80;
	//if (data.flags.width == 'max')
	//	leftOffset = 10;
	//leftOffset *= g_openModals.length;
	//formEnv.css ({left: mainBrowserContent.position().left, top: 0, width: mainBrowserContent.width (), height: mainBrowser.height()});
	//formForm.css ({left: mainBrowserContent.position().left + leftOffset + 2, top: 0, width: mainBrowserContent.width () - leftOffset/*, height: mainBrowser.height()*/});

	var formHeader = $('#' + formId + 'Header');
	formHeader.html (data.htmlHeader);
	var formContent = $('#' + formId + 'Content');
	formContent.html (data.mainCode);
	var formButtons = $('#' + formId + 'Buttons');
	formButtons.html (data.buttonsCode);

	if (sidebarPos != 0)
	{
		var formSidebar = $('#' + formId + 'Sidebar');
		formSidebar.html (data.sidebarCode);
	}

	if (infoPanelPos != 0)
	{
		var formInfoPanel = $('#' + formId + 'InfoPanel');
		formInfoPanel.html (data.infoPanelCode);
	}


	//--var contentSizeY = mainBrowser.height() - formButtons.height() - formHeader.height () - 4;
	//--formContent.height (contentSizeY);

	e10DecorateFormWidgets ($("#" + formId));
	e10ModalFormRefreshLayout (formId);

	df2FormsSetData (formId, data);

	//e10viewerLineDetail (viewerId, true);
	//viewerRefreshLayoutListDetail (detail);

	e10doSizeHints (formContent);
	setTimeout (function () {initSubObjects(data); }, 10);

	//viewerRefreshLayoutListDetail (detail);
	if (data.flags.autofocus === undefined)
		$("#" + formId + " input[type='text']:visible,textarea:visible,div.e10-inputDocLink:visible").first().focus();
	else
		$("#" + formId + " input.autofocus").first().focus();
} // e10ViewerCreateEditForm


function e10ModalFormRefreshLayout (formId)
{
	var body = $('body');

	var mainBrowser = $('#mainBrowser');
	var mainBrowserContent = $('#mainBrowserContent');
	var form = $('#' + formId + 'Form');
	var formEnv = $('#' + formId + 'FormEnv');
	var formForm = $('#' + formId + 'Form');

	var formHeader  = $('#' + formId + 'Header');
	var formContent = $('#' + formId + 'Content');
	var formButtons = $('#' + formId + 'Buttons');
	var formSidebar = $('#' + formId + 'Sidebar');
	var formInfoPanel = $('#' + formId + 'InfoPanel');
	var infoPanelWidth = 0;
	if (formInfoPanel.length) {
		infoPanelWidth = formInfoPanel.width();
	}
	var infoPanelPos = parseInt (form.attr ('data-flag-infopanelpos'));

	var maximizeX = 0;
	var maximizeY = 0;
	if (parseInt (form.attr ('data-flag-maximize')) === 1)
	{
		maximizeX = 1;
		maximizeY = 1;
	}

	var inlineSourceElementType = formEnv.attr('data-inline-source-element-type');

	var leftEnvPos = 0;
	var leftOffset = 0;
	var envSizeX = 0;
	var envHeight = 0;
	var zindexEnv = 9000 + g_openModals.length * 2;

	var formPosTop = 0;
	var setFormSizeY = 0;
	var formSizeX = 0;


	var appMenu = $('#mainBrowserAppMenu');
	var appMenuSizeY = 0;
	if (appMenu.length)
		appMenuSizeY = appMenu.height();

	if (inlineSourceElementType === undefined)
	{
		envHeight = mainBrowser.height() - appMenuSizeY;
		envSizeX = mainBrowserContent.width() + $('#mainBrowserRightBar').width();
		if (body.hasClass('e10-appEmbedAppIframe'))
		{
			leftOffset = $('#mainBrowserLeftMenu').width();
			leftEnvPos = leftOffset;
			envSizeX += leftOffset;
		}
		else if ((!body.hasClass('e10-appNormal') && (!body.hasClass('e10-appEmbed'))) || g_openModals.length > 1)
			leftOffset = 20;
		leftOffset *= g_openModals.length;

		var activeMenuItem = $("#mainListViewMenu li.terminal.activeMainItem");
		if (activeMenuItem.is('LI'))
			leftEnvPos = mainBrowserContent.position().left;
		else
			envSizeX += mainBrowserContent.position().left;

		if (formForm.attr('data-flag-terminal') === "1" && leftEnvPos === 0)
			leftEnvPos = mainBrowserContent.position().left;

		formEnv.css({
			left: leftEnvPos,
			top: appMenuSizeY, width: envSizeX,
			height: envHeight, 'z-index': zindexEnv
		});

		formPosTop = appMenuSizeY;
		setFormSizeY = envHeight;
		formSizeX = envSizeX - leftOffset - mainBrowserContent.position().left;
	}
	else
	if (inlineSourceElementType === 'form')
	{
		var inlineSourceElementId = formEnv.attr('data-inline-source-element-id');
		var parentForm = $('#'+inlineSourceElementId+'Form');
		var parentFormContent = $('#'+inlineSourceElementId+'Content');
		leftEnvPos = parentForm.position().left;
		envSizeX = parentFormContent.width();
		var topEnvPos = parentForm.position().top;
		envHeight = parentForm.height();
		formEnv.css({
			left: leftEnvPos,
			top: topEnvPos, width: envSizeX + 2,
			height: envHeight, 'z-index': zindexEnv
		});

		formPosTop = parentFormContent.position().top + appMenuSizeY + 10/*+ parentFormContent.offset().top*/;
		setFormSizeY = parentFormContent.innerHeight();
		formSizeX = envSizeX - leftOffset;
	}

	formSizeX -= infoPanelWidth;

	var contentLeft = 0;
	var sidebarPos = parseInt (form.attr ('data-flag-sidebarpos'));
	var sidebarWidthPerc = 0.25;
	if (form.attr ('data-flag-sidebarwidth') !== undefined)
		sidebarWidthPerc = parseFloat(form.attr ('data-flag-sidebarwidth'));
	var sidebarWidth = ((formSizeX * sidebarWidthPerc) | 0);
	if (sidebarPos === 0 || sidebarPos === 3) // none or on-parent-form
		sidebarWidth = 0;

	var contentSizeX = formSizeX - sidebarWidth;
	if (!maximizeX)
		contentSizeX = formContent.width () + 30;

	if (sidebarPos === 2) // right
	{
		//contentSizeX -= sidebarWidth;
	}
	else
	if (sidebarPos === 1) // left
	{
		//contentSizeX -= sidebarWidth;
		contentLeft = sidebarWidth;
	}

	var setFormLeft = mainBrowserContent.position().left + leftOffset /*+ 2*/;
	var setFormSizeX = formSizeX;
	if (maximizeX === 0)
		setFormSizeX = contentSizeX + sidebarWidth;

	var zindexForm = 9000 + g_openModals.length * 2 + 1;
	formForm.css ({left: setFormLeft, top: formPosTop, width: setFormSizeX, height: setFormSizeY, 'z-index': zindexForm});

	formHeader.css ({left: contentLeft, width: contentSizeX});
	formContent.width (contentSizeX);
	formButtons.css ({left: contentLeft, width: contentSizeX});

	var contentSizeY = envHeight - formButtons.height() - formHeader.height () - 4;
	formContent.css ({left: contentLeft, top: 0, width: contentSizeX, height: contentSizeY});

	if (sidebarPos === 2)
	{ // right
		if (infoPanelPos === 2)
			formSidebar.css ({left: contentSizeX + infoPanelWidth, top: 0, width: sidebarWidth + 1, height: setFormSizeY - 2});
		else
			formSidebar.css ({left: contentSizeX, top: 0, width: sidebarWidth + 1, height: setFormSizeY - 2});
	}
	else
	if (sidebarPos === 1)
	{ // left
		formSidebar.css ({left: 0, top: 0, width: sidebarWidth, height: setFormSizeY});
	}
	else
	if (sidebarPos === 3)
	{ // on-parent-form
		var parentFormSidebar = $('#'+inlineSourceElementId+'Sidebar');
		formSidebar.css ({
			left: parentFormSidebar.position().left + parentForm.offset().left  + 5,
			top: parentFormSidebar.position().top + + parentForm.offset().top,
			width: parentFormSidebar.width(), height: parentFormSidebar.height() + 1,
			'z-index': zindexForm
		});
	}
	else
	if (sidebarPos === 0)
	{ // none
		formSidebar.css ({width: 0, height: 0});
	}

	if (!infoPanelWidth) {
		var center = setFormLeft + ((envSizeX - setFormSizeX) / 2) | 0;
		if ((center + setFormSizeX) > envSizeX)
			center = mainBrowserContent.position().right - setFormSizeX - 90;
		formForm.css({left: center});
	}
	else
	{
		if (infoPanelPos === 2) {
				formInfoPanel.css({left: contentSizeX, top: 0, height: setFormSizeY});
		}
		else {
			if (sidebarPos === 1)
				formInfoPanel.css({left: setFormSizeX + contentLeft + 1  - sidebarWidth, top: 0, height: setFormSizeY});
			else
				formInfoPanel.css({left: setFormSizeX + contentLeft + 1, top: 0, height: setFormSizeY});
		}
	}
}


function e10CloseModals ()
{
  while (g_openModals.length)
  {
    var modalElementId = g_openModals.pop ();
		var modalElement = $('#'+modalElementId);
		modalElement.remove ();
		$('#'+modalElementId + 'Env').remove();
	}
}

function e10CloseModal ()
{
    if (g_openModals.length)
    {
        var modalElementId = g_openModals.pop ();
        var modalElement = $('#'+modalElementId);
        modalElement.remove ();
        $('#'+modalElementId + 'Env').remove();
    }
}


function e10ViewerCancelForm (e)
{
	var viewerId = searchObjectId (e, 'viewer');
  var viewer = $('#' + viewerId);

	var modalElementId = searchObjectId (e, 'modal');
	var modalElement = $('#'+modalElementId);
	var sidebarId = modalElement.attr('data-sidebar-element-id');
	if (sidebarId !== undefined)
		$('#'+sidebarId).remove();
	modalElement.remove ();
	$('#'+modalElementId + 'Env').remove();
	g_openModals.pop();

	// terminal mode? refresh main viewer!
	if (g_openModals.length === 0)
	{
		var activeMenuItem = $("#mainListViewMenu li.terminal.activeMainItem:first");
		if (activeMenuItem.is ("LI"))
			menuItemClick (activeMenuItem);
		else
		if ($('#mainBrowser').hasClass('e10-appEmbedPopup'))
			window.close();
		else
		if ($('#mainBrowser').hasClass('e10-appEmbed')) {
			window.parent.postMessage("close-iframe-embedd-element", "*");
//			window.parent.location.reload(true);
		}
	}

/*
	e10viewerCloseDetail (e);
	var pk = searchParentAttr (e, "data-pk");
	if (pk)
	{
		var oneRow = $('#' + viewerId + 'Items li[data-pk="' + pk + '"]').first ();
		if (oneRow.is ("li"))
			viewerItemClick (oneRow);
	}
	*/
}

function showDocLink (e)
{
}

function df2ViewerDoClick (event, e)
{
  var action = e.attr ("data-action");
  if (action == 'edit')
  {
    if (event.which == 1)
    {
      event.stopPropagation();
      event.preventDefault();
      showDocLink (e);
      return true;
    }
    return false;
  }

  if (action == 'new')
  {
      event.stopPropagation();
      event.preventDefault();
    showDocLink (e);
    return true;
  }

  if (action == 'save')
  {
      event.stopPropagation();
      event.preventDefault();
    df2saveForm (e);
    return true;
  }

  var apiPath = e.attr ("data-apipath");
  alert ("do-click: " + e.text() + ", action=" + action + ", apipath=" + apiPath);
} // df2ViewerDoClick


function df2saveForm (srcEl, successFunction)
{
	if (!e10FormSaveBegin(srcEl))
		return false;

	var noClose = 0;
	if (srcEl.attr ('data-noclose'))
		noClose = 1;

  var id = srcEl.attr ("data-form");

	var e = $("#" + id);

	var docState = 0;
	if (srcEl.attr ('data-docstate'))
		docState = parseInt (srcEl.attr ('data-docstate'));

	//alert ('docState: ' + docState);

  var formData = df2collectEditFormData (e, $.myFormsData [id]);
	formData ['setDocState'] = docState;
	formData.postData = e10DocumentData();

	var table = e.attr ("data-table");

	var url = "/api/form/" + table + "/save?callback=?&newFormId=" + id;
	url += "&viewPortWidth="+document.documentElement.clientWidth;

	e10.server.post(url, formData, function(data)
	{
		if (successFunction)
			successFunction (e, data.recData ['ndx']);
		else
		{
			if (data.saveResult !== undefined && data.saveResult.disableClose !== undefined)
				noClose = 1;
			if (noClose)
			{
				e.parent().parent().parent().find ('div.e10-ef-header').html (data.htmlHeader);

				var buttons = $("#" + id + 'Buttons');
				buttons.html (data.buttonsCode);
				if (data.saveResult !== undefined && data.saveResult.disableSetData !== undefined)
					e10FormSetAsSaved (id);
				else
				{
					e.parent().parent().html (data.mainCode);
					df2FormsSetData (id, data);
				}

				var form = $("#" + id);
				e10DecorateFormWidgets (form);
				e10doSizeHints (form);
				initSubObjects(data);

				var infoPanelPos = parseInt (form.attr ('data-flag-infopanelpos'));
				if (infoPanelPos != 0)
				{
					var formInfoPanel = $('#' + id + 'InfoPanel');
					formInfoPanel.html (data.infoPanelCode);
				}

				if (data.saveResult !== undefined && data.saveResult.disableClose !== undefined)
					form.find('td.e10-column-error input.e10-inputRefId').focus();
				else
					$('#' + g_focusedInputId).focus();

				form.find('.e10-column-error').tooltip('toggle');

				if (data.saveResult !== undefined && data.saveResult.notifications !== undefined)
				{
					for (var i = 0; i < data.saveResult.notifications.length; i++)
					{
						var n = data.saveResult.notifications[i];
						var opts = {text: n.msg};

						opts.type = 'error';
						if (n.title !== undefined)
							opts.title = n.title;

						if (n.mode === 'top')
						{
							opts.stack = g_NotifyStackTopBar;
							opts.addclass = "stack-bar-top";
							opts.cornerclass = '';
							opts.animation = 'slide';
							opts.animate_speed = 150;
							opts.width = "100%";
						}
						else
						{
							opts.stack = g_NotifyStackTopRight;
						}
						new PNotify(opts);
					}
				}
			}
			else
			{
				var modalElementId = searchObjectId (e, 'modal');
				var modalElement = $('#'+modalElementId);

				var srcObjectType = modalElement.attr ('data-srcObjectType');
				var srcObjectId = modalElement.attr ('data-srcObjectId');

				var sidebarId = modalElement.attr('data-sidebar-element-id');
				if (sidebarId !== undefined)
					$('#'+sidebarId).remove();
				modalElement.remove ();
				$('#'+modalElementId + 'Env').remove();
				g_openModals.pop ();

				if (data.flags.reloadNotifications === 1)
					e10NCReload();

				if (srcObjectType == 'viewer')
				{
					viewerRefresh ($('#' + srcObjectId), data.recData ['ndx']);
				}
				else
				if (srcObjectType == 'widget')
				{
					e10WidgetAction (null, null, srcObjectId);
				}
				else
				if (srcObjectType == 'form-to-save')
				{
					e10SaveOnChange ($('#'+srcObjectId));
				}
				else
				if (srcObjectType == 'iframe')
				{
					$('#'+srcObjectId)[0].contentWindow.location.reload(true);
				}
				else
				if (srcObjectType == 'none')
				{
					// terminal mode? refresh main viewer!
					if (g_openModals.length === 0)
					{
						var activeMenuItem = $("#mainListViewMenu li.terminal.activeMainItem:first");
						if (activeMenuItem.is ("LI"))
							menuItemClick (activeMenuItem);
						else
						if ($('#mainBrowser').hasClass('e10-appEmbedPopup'))
							window.close();
						else
						if ($('#mainBrowser').hasClass('e10-appEmbed'))
						{
							window.parent.postMessage("close-iframe-embedd-element", "*");
							//window.parent.location.reload(true);
						}
						else
						{
							var oneRow = $('#mainBrowserContent div.e10-mainViewer div.e10-sv-body .e10-viewer-list>.r.active').first ();
							if (oneRow.length !== 0)
							{
								var viewerId = $('#mainBrowserContent div.e10-mainViewer').attr ('id');
								e10viewerSetDetail (viewerId, oneRow);
							}
						}
					}
				}
			}
		}
  });
	return true;
}


function df2collectEditFormData (form, data)
{
	var newData = {};
  var usedInputs = new Array ();
	var thisInputValue = null;
	var mainFid = form.attr('id');

  var formElements = form.find (':input, div.e10-inputDocLink');
  for (var i = 0; i < formElements.length; i++)
  {
    var thisInput = $(formElements [i]);
		if (!thisInput.attr ("name") && !thisInput.attr ("data-name"))
			continue;
		if (thisInput.attr ("data-fid") !== mainFid)
			continue;
    var thisInputName = thisInput.attr ('name');
		if (thisInput.attr ("data-name"))
			thisInputName = thisInput.attr ('data-name');

		var dataMainPart = 'recData';
		var dataSubPart = null;
		var dataRowPart = null;
		var dataColumnPart = null;
		var dataLastPart = null;

		var nameParts = thisInputName.split ('.');
		if (nameParts.length == 1)
			dataColumnPart = thisInputName;
		else
		if (nameParts.length == 2)
		{
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
		}
		else
		if (nameParts.length == 3)
		{
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
			dataRowPart = nameParts [2];
		}
		else
		if (nameParts.length == 4)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
		}
		else
		if (nameParts.length == 5)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
			dataLastPart = nameParts [4];
		}

		thisInputValue = null;

    if (thisInput.hasClass ("e10-inputLogical"))
    {
			if (thisInput.attr ('value') == 'Y')
			{
				if (thisInput.is (':checked'))
					thisInputValue = 'Y';
				else
					thisInputValue = 'N';
			}
			else
			if (thisInput.attr ('value') != '1')
			{
				if (thisInput.is (':checked'))
					thisInputValue = thisInput.attr ('value');
				else
					thisInputValue = '0';
			}
			else
			{
				if (thisInput.is (':checked'))
					thisInputValue = 1;
				else
					thisInputValue = 0;
			}
    }
		else
    if (thisInput.hasClass ("e10-inputRadio"))
    {
			if (thisInput.is(':checked'))
				thisInputValue = thisInput.attr ('value');
			else
				continue;
    }
		else
		if (thisInput.hasClass ("e10-inputDate") || thisInput.hasClass ("e10-inputDateS"))
		{
			if (thisInput.val () != '')
			{
				var dp = thisInput.val ().split (".");
				thisInputValue = dp [2] + "-" + dp [1] + "-" + dp [0];

				if (thisInput.hasClass ("e10-inputDateTime"))
				{
					var timeInput = $('#'+thisInput.attr('id')+'_Time');
					thisInputValue += ' ' + timeInput.val ();
				}
				// native input type: thisInputValue = thisInput.val ();
			}
			else
				thisInputValue = '0000-00-00';
		}
		else
	  if (thisInput.hasClass ("e10-inputDateN"))
	  {
		  if (thisInput.val () != '')
		  {
			  var dp = thisInput.val ().split ("-");
			  thisInputValue = dp [0] + "-" + dp [1] + "-" + dp [2];

			  if (thisInput.hasClass ("e10-inputDateTime"))
			  {
				  var timeInput = $('#'+thisInput.attr('id')+'_Time');
				  thisInputValue += ' ' + timeInput.val ();
			  }
			  // native input type: thisInputValue = thisInput.val ();
		  }
		  else
			  thisInputValue = '0000-00-00';
	  }
		else
		if (thisInput.hasClass ("e10-inputMoney") || thisInput.hasClass ("e10-inputDouble"))
		{
			if (thisInput.val () != '')
			{
				var sv = thisInput.val ();
				sv = sv.replace (",", ".").replace(/\s/g,'');
				thisInputValue = parseFloat (sv);
			}
			else
				thisInputValue = 0.0;
		}
		else
		if (thisInput.hasClass ("e10-inputEnum"))
		{
			if (thisInput.hasClass ("e10-inputEnumMultiple"))
			{
				if (thisInput.val ())
					thisInputValue = thisInput.val ().join ('.');
				else
					thisInputValue = '';
			}
			else
				thisInputValue = thisInput.val ();
		}
		else
	  if (thisInput.hasClass ("e10-inputTimeLen"))
	  {
		  if (thisInput.val () != '')
		  {
			  var tlp = thisInput.val ().split (":");
			  var seconds = (parseInt(tlp[1]) + parseInt(tlp[0]) * 60) * 60;
			  thisInputValue = seconds.toString();
		  }
		  else
			  thisInputValue = '0';
	  }
	  else
		if (thisInput.hasClass ("e10-inputRefId") && !thisInput.hasClass ("e10-inputRefIdDirty"))
		{

		}
		else
		if (thisInput.hasClass ("e10-inputDocLink"))
		{
			thisInputValue = {};
			var listItems = thisInput.find ('ul li');
			for (var ii = 0; ii < listItems.length; ii++)
			{
				var li = $(listItems [ii]);
				if (!li.attr('data-pk'))
					continue;
				//thisInputValue.push ({"table": li.attr ('data-table'), "pk": li.attr ('data-pk')});
				if (!thisInputValue [li.attr ('data-table')])
					thisInputValue [li.attr ('data-table')] = [li.attr ('data-pk')];
				else
					thisInputValue [li.attr ('data-table')].push (li.attr ('data-pk'));
			}
		}
		else
		if (thisInput.hasClass ("e10-inputCode"))
		{
			thisInputValue = thisInput.data ('cm').getValue ();
		}
		else
			thisInputValue = thisInput.val ();

		if (thisInputValue === null)
			continue;

		if (!newData [dataMainPart])
			newData [dataMainPart] = {};

		if (dataMainPart == 'recData')
		{
			newData [dataMainPart][dataColumnPart] = thisInputValue;
	    usedInputs.push (dataColumnPart);
		}
		else
		{
			if (nameParts.length == 2)
			{
				if (!newData [dataMainPart]/*[dataColumnPart]*/)
					newData [dataMainPart]/*[dataColumnPart]*/ = {};
				newData [dataMainPart][dataColumnPart] = thisInputValue;
			}
			else
			if (nameParts.length == 3)
			{
				if (!newData [dataMainPart][dataColumnPart])
					newData [dataMainPart][dataColumnPart] = {};
				newData [dataMainPart][dataColumnPart][dataRowPart] = thisInputValue;
			}
			else
			if (nameParts.length == 4)
			{
				if (!newData [dataMainPart][dataSubPart])
					newData [dataMainPart][dataSubPart] = {};
				if (!newData [dataMainPart][dataSubPart][dataRowPart])
					newData [dataMainPart][dataSubPart][dataRowPart] = {};
				newData [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] = thisInputValue;
			}
			else
			if (nameParts.length == 5)
			{
				if (!newData [dataMainPart][dataSubPart])
					newData [dataMainPart][dataSubPart] = {};
				if (!newData [dataMainPart][dataSubPart][dataRowPart])
					newData [dataMainPart][dataSubPart][dataRowPart] = {};
				if (!newData [dataMainPart][dataSubPart][dataRowPart][dataColumnPart])
					newData [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] = {};
				newData [dataMainPart][dataSubPart][dataRowPart][dataColumnPart][dataLastPart] = thisInputValue;
			}
		}
  }

	if (data === null || data === undefined)
		return newData;

	if (!newData.recData)
		return newData;

  for (var colName in data.recData)
  {
    if (usedInputs.indexOf (colName) == -1)
		{
			if ((data.recData [colName] != null) && (data.recData [colName].date))
				newData.recData [colName] = data.recData [colName].date;
			else
				newData.recData [colName] = data.recData [colName];
		}
  }

	// blank lists
	if (data.lists)
	{
		if (!newData.lists)
			newData.lists = {};

	  for (var listName in data.lists)
		{
			if (!newData.lists [listName])
				newData.lists [listName] = new Array();
		}
	}

	newData ["documentPhase"] = data.documentPhase;

	// UI states
	var formId = form.attr ('id');
	newData ['ui'] = {'formId': formId};
	var mainTabs = $('#'+formId+'-mt');
	if (mainTabs.is ('UL'))
	{
		var activeTabId = mainTabs.find ('li.active').attr ('id');
		newData.ui ['activeMainTab'] = activeTabId;
	}

	//alert ("getData2: " + JSON.stringify (newData.recData));

	return newData;
} // df2collectEditFormData

// ---------
// tabWidget
// ---------

function initTabs (id)
{
  $("#" + id + " >li").each (function () {

			      var tabId = $(this).attr ("id");
						//alert (tabId);
						var tabContentId = tabId + '-tc';
						if ($(this).hasClass ('active'))
							$('#' + tabContentId).show ();
						else
							$('#' + tabContentId).hide ();
  });

}

function initMainTabs (id)
{
	var tabs = $('#' + id);
	var sizeX = tabs.parent().width ();
  $("#" + id + " >li").each (function () {

			      var tabId = $(this).attr ("id");
						var tabContentId = tabId + '-tc';
						var tab = $('#' + tabContentId);
						//tab.width (sizeX - tab.position().left);
						//alert (tabId);
						if ($(this).hasClass ('active'))
							$('#' + tabContentId).show ();
						else
							$('#' + tabContentId).hide ();
  });

}


// ---------
// documents
// ---------

function e10DocumentData ()
{
	var data = {};

	for (var si in webSocketServers)
	{
		var ws = webSocketServers[si];
		for (var camId in ws.cameras)
		{
			var camPic = $('#e10-cam-' + camId + '-right').parent();
			if (camPic.is('button'))
			{
				if (data.cameras === undefined)
					data.cameras = {};
				data.cameras[camId] = camPic.attr ("data-pict");
			}
		}
	}
	return data;
}


function e10DocumentAction (event, e)
{
	var actionType = e.attr ("data-action");

	if (actionType == "new")
	{
		var doIt = 1;
		if ((event && event.shiftKey) || e.attr('data-copyfrom'))
		{
			if (!event || !event.shiftKey)
				doIt = confirm("Opravdu udělat kopii dokumentu?");
		}
		if (doIt)
			e10DocumentAdd (e);
		return;
	}

	if (actionType == "edit")
	{
		e10DocumentEdit (e);
		return;
	}

	if (actionType === "show")
	{
		e10DocumentEdit (e, null, 'show');
		return;
	}

	if (actionType === "wizard")
	{
		e10DocumentWizard (e);
		return;
	}

	if (actionType == "setInputValue")
	{
		e10FormNeedSave ($('#'+ e.attr ('data-inputid')).val(e.text()), 0);
		return;
	}

	if (actionType == "incInputValue")
	{
		e10DocumentModifyColValue ($('#'+ e.attr ('data-inputid')), +1);
		return;
	}

	if (actionType == "decInputValue")
	{
		e10DocumentModifyColValue ($('#'+ e.attr ('data-inputid')), -1);
		return;
	}

	if (actionType == "mark")
	{
		e10DocumentMark(e);
		event.stopPropagation();
		event.preventDefault();
		return;
	}

	alert ("action: " + actionType);
}

function e10DocumentAdd (e, params)
{
	var table = '';
	var addParams = null;
	var openAs = null;
	var openUrlPrefix = null;
	var srcObjectType = 'none';
	var srcObjectId = '';

	if (e !== 0)
	{
		table = e.attr ("data-table");
		addParams = e.attr ('data-addparams');
		if (e.attr('data-open-as'))
			openAs = e.attr('data-open-as');
		if (e.attr('data-open-url-prefix'))
			openUrlPrefix = e.attr('data-open-url-prefix');
		if (e.attr ('data-srcobjecttype') !== undefined)
			srcObjectType = e.attr ('data-srcobjecttype');
		if (e.attr ('data-srcobjectid') !== undefined)
			srcObjectId = e.attr ('data-srcobjectid');
	}
	else
	{
		table = params.table;
		addParams = params.addparams;
		if (params.srcobjecttype !== undefined)
			srcObjectType = params.srcobjecttype;
		if (params.srcobjectid !== undefined)
			srcObjectId = params.srcobjectid;
	}

	g_formId++;
	var newElementId = "mainEditF" + g_formId;

	var url = table + "/new/?callback=?" + "&newFormId=" + newElementId;
	if (addParams)
		url += '&' + addParams;

	if (e !== 0 && e.attr('data-pict'))
		url += '&addPicture=' + e.attr('data-pict');

	if (e !== 0 && e.attr('data-copyfrom'))
		url += '&focusedPK=' + e.attr('data-copyfrom') + '&copyDoc=1';
	url += "&viewPortWidth="+document.documentElement.clientWidth;

	var postData = e10DocumentData ();
	if (e !== 0)
	{
		var dataForm = searchObjectAttr (e, 'data-pk');
		if (dataForm !== null)
			e10collectFormData (dataForm, postData);
	}

	if (openAs)
	{ 	// /app/!/e10-document-trigger/e10pro.kb.texts/edit/67?mismatch=1
		var openUrl = '/';
		if (openUrlPrefix)
			openUrl = openUrlPrefix;
		openUrl += 'app/!/e10-document-trigger/';
		openUrl += url;
		openUrl += '&e10window=popup';
		var width = (screen.width * 0.75) | 0;
		var height = (screen.height * 0.75) | 0;
		window.open(openUrl, "test123", "location=no,status=no,width=" + width + ",height=" + height);

		return;
	}


  e10.server.post("/api/form/" + url, postData, function(data) {
			e10ViewerCreateEditForm (data, newElementId, srcObjectType, srcObjectId);
  });
}

function e10DocumentEdit (e, params, actionType)
{
	var table = '';
	var addParams = null;
	var pk = 0;
	var srcObjectType = 'none';
	var srcObjectId = '';

	if (e !== 0)
	{
		table = e.attr ("data-table");
		addParams = e.attr ('data-addparams');
		pk = e.attr ('data-pk');
		if (e.attr ('data-srcobjecttype') !== undefined)
			srcObjectType = e.attr ('data-srcobjecttype');
		if (e.attr ('data-srcobjectid') !== undefined)
			srcObjectId = e.attr ('data-srcobjectid');
	}
	else
	{
		table = params.table;
		pk = params.pk;
		addParams = params.addparams;
		if (params.srcobjecttype !== undefined)
			srcObjectType = params.srcobjecttype;
		if (params.srcobjectid !== undefined)
			srcObjectId = params.srcobjectid;
	}

	g_formId++;
	var newElementId = "mainEditF" + g_formId;
	var action = (actionType != undefined) ? actionType : 'edit';
	var url = "/api/form/" + table + "/"+action+"/" + pk + "?callback=?&newFormId=" + newElementId;
	if (addParams)
		url += '&' + addParams;
	url += "&viewPortWidth="+document.documentElement.clientWidth;

	var postData = e10DocumentData ();


  e10.server.post(url, postData, function(data) {
			e10ViewerCreateEditForm (data, newElementId, srcObjectType, srcObjectId);
  });
}


function e10DocumentWizard (e)
{
	var table = e.attr ("data-table");
	var addParams = e.attr ('data-addparams');

	g_formId++;
	var newElementId = "mainEditF" + g_formId;

	//var url = httpApiRootPath + "/api/form/" + table + "/new/?callback=?" + "&newFormId=" + newElementId;
	var wizardClass = e.attr ("data-class");
	var url = "/api/wizard/" + wizardClass + "/0?callback=?&newFormId=" + newElementId;

	if (addParams)
		url += '&' + addParams;

	if (e.attr('data-pict'))
		url += '&addPicture=' + e.attr('data-pict');
    if (e.attr('data-pk'))
        url += '&pk=' + e.attr('data-pk');
    if (e.attr('data-table'))
        url += '&table=' + e.attr('data-table');

	var postData = e10DocumentData ();

	var dataForm = searchObjectAttr (e, 'data-pk');
	if (dataForm !== null)
		e10collectFormData (dataForm, postData);

	var srcObjectType = 'none';
	var srcObjectId = '';
	if (e !== 0 && e.attr ('data-srcobjecttype') !== undefined)
		srcObjectType = e.attr ('data-srcobjecttype');
	if (e !== 0 && e.attr ('data-srcobjectid') !== undefined)
		srcObjectId = e.attr ('data-srcobjectid');

	e10.server.post(url, postData, function(data) {
		e10ViewerCreateEditForm (data, newElementId, srcObjectType, srcObjectId);
	});
}


function e10DocumentModifyColValue (input, sign)
{
	input.focus ();
	var oldPrice = getFloatInputValue (input);

	var how = 1.0;
	if (oldPrice < 10)
		how = 0.1;
	else
	if (oldPrice < 50)
		how = 1.0;

	how = how * sign;

	var newPrice = oldPrice;
	newPrice += how;
	newPrice = Round (newPrice, 1);
	input.val (newPrice);
	e10FormNeedSave (input, 0);
}

function e10DocumentMark(e)
{
	var markContainer = searchObjectAttr(e, 'data-mark');
	var markButton = searchObjectAttr(e, 'data-mark-button-value');

	var markContainerId = markContainer.attr('id');

	var requestParams = {};
	requestParams['object-class-id'] = 'lib.core.ui.DocMark';
	requestParams['mark'] = markContainer.attr('data-mark');
	if (markContainer.attr('data-mark-st') !== undefined)
		requestParams['mark-st'] = markContainer.attr('data-mark-st');
	requestParams['table'] = markContainer.attr('data-table');
	requestParams['pk'] = markContainer.attr('data-pk');
	requestParams['button-value'] = markContainer.attr('data-mark-button-value');

	e10.server.api(requestParams, function(data) {
		var markContainer = $('#'+markContainerId);
		markContainer.html(data['rowsHtmlCode']);
		markContainer.attr('title', data['markTitle']);
	});
}

// -----
// forms
// -----

$.myFormsData = {};


function df2FormsSetData (id, data)
{
	//alert (JSON.stringify (data.recData));
	e10FormSetAsSaved (id);

  var form = $("#" + id);
  $.myFormsData [id] = data;//$.extend ({}, data);
	var formElements = form.find (':input, div.e10-inputDocLink');
	var readOnly = (form.attr ('data-readonly') !== undefined);

  for (var i = 0; i < formElements.length; i++)
	{
    var thisInput = $(formElements [i]);
		if (!thisInput.attr ("name") && !thisInput.attr ("data-name"))
			continue;
    var thisInputName = thisInput.attr ('name');
		if (thisInput.attr ("data-name"))
			thisInputName = thisInput.attr ('data-name');
		var thisInputReadOnly = (thisInput.attr ('readonly') !== undefined);

		var dataMainPart = 'recData';
		var dataSubPart = null;
		var dataRowPart = null;
		var dataColumnPart = null;
		var dataLastPart = null;


		//subColumns.data.table_1AII.0.naz_uc_skup


		var nameParts = thisInputName.split ('.');
		if (nameParts.length == 1)
			dataColumnPart = thisInputName;
		else
		if (nameParts.length == 2)
		{
			dataMainPart = nameParts [0];
			dataColumnPart = nameParts [1];
		}
		else
		if (nameParts.length == 3)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataColumnPart = nameParts [2];
		}
		else
		if (nameParts.length == 4)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
		}
		else
		if (nameParts.length == 5)
		{
			dataMainPart = nameParts [0];
			dataSubPart = nameParts [1];
			dataRowPart = nameParts [2];
			dataColumnPart = nameParts [3];
			dataLastPart = nameParts [4];
		}

		if (dataMainPart === 'extra')
				continue;

		var thisInputValue = null;
		if (dataMainPart == 'recData')
			thisInputValue = data [dataMainPart][dataColumnPart];
		else
		if (nameParts.length == 2)
			thisInputValue = data [dataMainPart][dataColumnPart];
		else
		if (nameParts.length == 3 && data [dataMainPart] !== undefined)
			thisInputValue = data [dataMainPart][dataSubPart][dataColumnPart];
		else
		if (nameParts.length == 4)
		{
			if ((data [dataMainPart] != undefined) && (data [dataMainPart][dataSubPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] != undefined))
				thisInputValue = data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart];
		}
		else
		if (nameParts.length == 5)
		{
			if ((data [dataMainPart] != undefined) && (data [dataMainPart][dataSubPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart] != undefined) && (data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart][dataLastPart] != undefined))
				thisInputValue = data [dataMainPart][dataSubPart][dataRowPart][dataColumnPart][dataLastPart];
		}

		if (thisInput.hasClass ("e10-fromSensor"))
		{
			var btnInputId = thisInput.attr ('id')+'_sensor';
			var sensorId = '';

			if (thisInputValue === undefined || thisInputValue == '' || thisInputValue == 0)
			{
				if (g_useMqtt)
				{
					sensorId = '#mqtt-sensor-'+thisInput.attr('data-srcsensor')+'>span.value';
					thisInputValue = $(sensorId).text();
					console.log("VALUE: "+thisInputValue);
					e10FormNeedSave(thisInput, 0);
				}
				else {
					sensorId = '#e10-sensordisplay-'+thisInput.attr('data-srcsensor');
					thisInputValue = $(sensorId).text();
					e10FormNeedSave(thisInput, 0);
				}
			}

			var dstelm = form.parent().parent().parent();
			var sensList = dstelm.attr('data_receivesensors');
			if (sensList === undefined)
				sensList = btnInputId;
			else
				sensList = sensList + ' ' + btnInputId;
			dstelm.attr ('data_receivesensors', sensList);

			$('#'+btnInputId).text ($(sensorId).text());
		}

		if (thisInput.hasClass ("e10-inputLogical"))
		{
			var checkIt = false;

			if (thisInput.val () == 'Y')
			{
				if (thisInputValue == 'Y')
					checkIt = true;
			}
			else
			if (thisInputValue)
				checkIt = true;

			if (checkIt)
				thisInput.attr('checked', true);
			else
				thisInput.attr('checked', false);
		}
		else
		if (thisInput.hasClass ("e10-inputRadio"))
		{
			if (thisInput.attr ('value') == thisInputValue)
				thisInput.attr('checked', true);
		}
		else
		if (thisInput.hasClass ("e10-inputDate") || thisInput.hasClass ("e10-inputDateN") || thisInput.hasClass ("e10-inputDateS"))
		{
			var dateVal = "";
			var timeVal = "";
			var timeInput = null;
			if (thisInputValue && (thisInputValue.date !== undefined || typeof thisInputValue === 'string'))
			{
				var ds = null;
				if (thisInputValue.date !== undefined)
					ds = thisInputValue.date.substring (0, 10);
				else
					ds = thisInputValue.substring (0, 10);

				if (ds === '0000-00-00')
					dateVal = '';
				else
				{
					dp = ds.split("-");
					if (thisInput.hasClass ("e10-inputDateN"))
						dateVal = dp [0] + "-" + dp [1] + "-" + dp [2];
					else
						dateVal = dp [2] + "." + dp [1] + "." + dp [0];
				}

				if (thisInput.hasClass ("e10-inputDateTime"))
				{
					timeInput = $('#'+thisInput.attr('id')+'_Time');

					if (thisInputValue.date !== undefined)
						timeVal = thisInputValue.date.substring (11, 16);
					else
						ds = thisInputValue.substring (11, 16);
				}
				// native input type: thisInput.val(ds);
			}
			thisInput.val (dateVal);

			if (timeInput)
				timeInput.val (timeVal);
		}
		else
		if (thisInput.hasClass ("e10-inputDateTime_Time"))
		{
		}
		else
		if (thisInput.hasClass ("e10-inputEnum"))
		{
			if (thisInput.hasClass ("e10-inputEnumMultiple"))
			{
				if (thisInputValue)
					thisInput.val (thisInputValue.split('.'));
				thisInput.trigger("liszt:updated");
			}
			else
				thisInput.val (thisInputValue);
		}
		else
		if (thisInput.hasClass ("e10-inputTimeLen"))
		{
			var timeLenVal = "0:00";
			if (thisInputValue)
			{
				var seconds = parseInt(thisInputValue);
				var allMinutes = (seconds / 60) | 0;
				var hours = (allMinutes / 60) | 0;
				var minutes = allMinutes - hours * 60;

				timeLenVal = hours.toString() + ':';
				if (minutes < 10)
					timeLenVal += '0';
				timeLenVal += minutes.toString();
			}
			thisInput.val (timeLenVal);
		}
		else
		if (thisInput.hasClass ("e10-inputRefId") && !thisInput.hasClass ("e10-inputRefIdDirty"))
		{
		}
		else
		if (thisInput.hasClass ("e10-inputNdx"))
		{
			thisInput.val (thisInputValue);
			if (thisInputValue != 0 && thisInputValue !== undefined) {
				thisInput.parent().find('span.btns').show();
			}
		}
		else
		if (thisInput.hasClass ("e10-inputDocLink"))
		{
			var searchInput = thisInput.find ('ul>li.input').html();
			var inpItems = '';

			for (var ii = 0; ii < thisInputValue.length; ii++)
			{
				var li = thisInputValue[ii];
				inpItems += "<li class='data' data-pk='" + li['dstRecId'] + "' data-table='" + li['dstTableId'] + "'" + '>' +
											li['title'] +
											((readOnly || thisInputReadOnly) ? "&nbsp;" : "<span class='e10-inputDocLink-closeItem'>&times;</span>") +
										"</li>";
			}
			inpItems += '<li class="input">' + searchInput + '</li>';
			thisInput.find ('ul').html (inpItems);
			if (inpItems != '')
				thisInput.find ("span.placeholder").hide();
			else
				thisInput.find ("span.placeholder").show();
			if (readOnly || thisInputReadOnly)
				thisInput.find ('ul>li.input').hide();
		}
		else
		if (thisInput.hasClass ("e10-inputMoney") || thisInput.hasClass ("e10-inputDouble"))
		{
			if (thisInputValue != 0)
				thisInput.val (thisInputValue);
			else
				thisInput.val ('');
		}
		else
		if (thisInput.hasClass ("e10-inputCode"))
		{
			var cm = thisInput.data ('cm');
			if (cm === undefined)
			{
				cm = CodeMirror.fromTextArea(thisInput[0], {tabSize: 2, lineNumbers: true, styleActiveLine: true});
				var cmWidth = form.width() - 1;
				cmWidth -= form.find('div.e10-form-maintabs-menu').width();
				cm.setSize(cmWidth, form.parent().parent().height() - $('#'+id+'Buttons').height() - 4);
				cm.on("change",function (cmEditor,cmChangeObject){e10FormNeedSave (thisInput, 0);});
				thisInput.data('cm', cm);
			}
			if (thisInputValue !== undefined && thisInputValue !== null)
				cm.setValue (thisInputValue);
		}
		else {
            thisInput.val(thisInputValue);
        }

		if (thisInput.hasClass ('e10-inputColor'))
			e10FormsBlurColorInput (0, thisInput);
	}
  //df2FormsRefreshInfoTexts (id);
} // df2FormsSetData


function df2runFormClose (id)
{
  $("#" + id).parent().remove ();
  $("#modalDlg").remove ();
}

function e10FormsTabClick (e)
{
  var pageid = e.attr ("id");
    //alert (pageid);

  if (pageid)
  {
    var activeTab = e.parent().find ("li.active").first();
    activeTab.removeClass ("active");

    $("#" + activeTab.attr ('id') + '-tc').hide();

    e.addClass ("active");
    $("#" + pageid + '-tc').show();

	var viewersRefreshLayout = parseInt(e.attr('data-first-click'));
	e10doSizeHints ($("#" + pageid + '-tc'), viewersRefreshLayout);
	if (viewersRefreshLayout)
		e.attr('data-first-click', '0');

	if (e.attr ('data-inputelement'))
	{
		$('#'+e.attr ('data-inputelement')+' input[name='+e.attr ('data-inputname')+']').val (e.attr ('data-inputvalue'));
	}

    return true;
  }

  return false;
}


function e10FormsInputDocLinkCloseItem (event, e)
{
	var formId = searchObjectId (e, 'form');
	e10FormSetAsModified (e.parent().parent().parent());

	var listItems = e.parent().parent();
	e.parent().detach();
	listItems.find('li.input>input').focus();

	var form = $('#'+formId);
	e10doSizeHints (form);
}


function e10FormsFocusRefInput (event, e)
{
	//alert ('refinput focused');
	e10FormsRefInputComboOpen (e);
}


function e10FormsRefInputComboOpen (input)
{
	var e = input;
	if (e.attr ('data-sidebar-local'))
	{
		e10FormsLocalSidebar (e);
		return;
	}

	if (e.is('textarea') && e.parent().parent().hasClass('CodeMirror'))
	{
		e = e.parent().parent().parent().find('textarea:first');
	}
	else
	if (e.hasClass('e10-inputListSearch'))
		e = e.parent().parent().parent();

	var table = searchParentAttr (e, "data-table");
	var pk = searchParentAttr (e, "data-pk");
	var formId = searchObjectId (e, 'form');
	if (!formId)
		formId = searchObjectId (e, 'wizard');

	var formId2 = searchParentAttr (e, "data-formid");

	var refInputId = e.parent().attr ('id');

	var columnId = '';
	var listId = '';
	var listGroup = '';

	var sideBarType ='column';
	if (e.attr ('data-column'))
		columnId = e.attr ('data-column');
	else
	if (e.attr ('data-listid'))
	{
		sideBarType = 'list';
		listId = e.attr ('data-listid');
		listGroup = e.attr ('data-listgroup');
		refInputId = e.attr ('id');
	}
	else
	if (e.attr ('data-sidebar-remote'))
	{
		var sidebar = $("#" + formId + 'Sidebar');
		if (sidebar.length)
		{
			if (sidebar.attr ('data-sidebar-local-column-target') === e.attr('id'))
				return;
		}

		sideBarType = 'remote';
		columnId = e.attr ('data-column');
		refInputId = e.attr ('id');
	}
	else
	{
		var form = $("#" + formId + 'Form');
		sideBarType = 'main';
		var sidebarViewer = $("#" + formId + 'Sidebar').find ("div.df2-viewer");

		var sidebarRefresh = form.attr('data-flag-sidebarrefresh');

		if (sidebarViewer.is ('DIV') && sidebarViewer.attr ('data-combo-rows-target') === 'rows' && sidebarRefresh !== 'always')
			return;
	}

	var columnName = e.attr ('name');
	var srcTableId = e.attr ('data-srctable');

	var url = '';

	if (table)
		url = "/api/form/" + table + "/sidebar/" + pk + '/' + sideBarType + '/' + srcTableId + '/';
	else
	{
		var wizardPage = searchParentAttr (e, "data-wizardpage");
		url = "/api/wizard/" + formId2 + "/" + wizardPage + "/sidebar/" + sideBarType + '/' + srcTableId + '/';
	}

	if (sideBarType === 'column')
		url	+= columnId;
	else
	if (sideBarType === 'list')
		url	+= listId + '/' + listGroup;
	else
	if (sideBarType === 'remote')
		url	+= columnName + '/' + e.attr ('data-sidebar-remote');

	url += "?callback=?&columnName=" + columnName;

	if (formId2)
		url += '&formId=' + formId2;

	var form = $("#" + formId + '');

	var formData = df2collectEditFormData (form, $.myFormsData [formId]);

	e10.server.post(url, formData, function(data) {

			var sidebar = $("#" + formId + 'Sidebar');
			sidebar.find ('*').detach();
			sidebar.html (data.sidebarCode);

			if (refInputId !== undefined)
				sidebar.attr ('data-sidebar-local-column-target', refInputId);
			else
				sidebar.removeAttr('data-sidebar-local-column-target');

			sidebar.find ('div.df2-viewer').each (function () {
			      var viewerId = $(this).attr ("id");
						initViewer (viewerId);
			});

			sidebar.find ('div.df2-viewer').each (function ()
			{
				if (sideBarType == 'main')
					$(this).attr ('data-combo-formid-target', formId).attr ('data-combo-rows-target', 'rows');
				else
					$(this).attr ('data-combo-column-target', refInputId);
			});
  });
}

function e10FormsBlurRefInput (event, e)
{
	//alert ('refinput focused');
	//e10FormsRefInputComboClose (e);
}

function e10FormsRefInputComboClose (e)
{
	//alert ('refinput focused');

	var table = searchParentAttr (e, "data-table");
	var pk = searchParentAttr (e, "data-pk");
	var formId = searchObjectId (e, 'form');
	var sidebar = $("#" + formId + 'Sidebar');
	sidebar.find ('*').detach();
	sidebar.html ('');
}

function e10FormsRefInputClear (e)
{
	var i = e.parent().parent();
	var v = i.find('input.e10-inputNdx');
	v.val('0');
	i.find('span.e10-refinp-infotext').html('');
	e10FormSetAsModified (v);
	i.find('input.e10-inputRefId').val('').focus();
	i.find('span.btns').hide();
	if (v.hasClass ('e10-ino-saveOnChange'))
		v.change();
}

function e10FormsRefInputEdit (e)
{
	var i = e.parent().parent();
	var v = i.find('input.e10-inputNdx');
	e.attr ('data-pk', v.val());
	e10DocumentEdit (e);
}


// -------------
// forms support
// -------------


function df2collectFormData (form, isNextBlock)
{ // DEPRECATED
	var data = {};

	var formElements = form.find ('input');
  for (var i = 0; i < formElements.length; i++)
  {
    var element = formElements [i];
    var type = element.type;
    if (type == "checkbox" || type == "radio")
    {
      if (element.checked)
				data[element.name] = element.value;
			continue;
    }
		data[element.name] = element.value;
  }
	formElements = form.find ('select');
  for (var i = 0; i < formElements.length; i++)
  {
    var element = formElements [i];
		data[element.name] = element.value;
  }

	formElements = form.find ('textarea');
  for (i = 0; i < formElements.length; i++)
  {
    var element = formElements [i];
		data[element.name] = element.value;
  }


	if (Object.keys(data).length === 0)
		return '';

	var frmData = '';
	if (isNextBlock === undefined)
		frmData = "frmId=myForm&";
	else {
		frmData = '&';
	}

	frmData += $.param(data);
	return frmData;
} // df2collectFormData

function e10collectFormData (form, data)
{
	var formElements = form.find ('input');
	for (var i = 0; i < formElements.length; i++)
	{
		var element = formElements [i];
		var type = element.type;
		if (type == "checkbox" || type == "radio")
		{
			if (element.checked)
				data[element.name] = element.value;
			continue;
		}
		data[element.name] = element.value;
	}
	formElements = form.find ('select');
	for (var i = 0; i < formElements.length; i++)
	{
		var element = formElements [i];
		data[element.name] = element.value;
	}

	formElements = form.find ('textarea');
	for (i = 0; i < formElements.length; i++)
	{
		var element = formElements [i];
		data[element.name] = element.value;
	}
} // e10collectFormData

function df2FillViewerLines (viewerId, url, focusPK, appendLines)
{
	var viewer = $('#' + viewerId);
	var form = $('#' + viewerId + 'Search');
  if (form)
  {
		viewer.attr ('data-loadonprogress', 1);
		e10setProgressIndicator (viewerId, 1);

    var formPostData = df2collectFormData (form);

		var bottomTab = viewer.find ("div.viewerBottomTabs input");
		if (bottomTab.is ('INPUT'))
			formPostData += "&bottomTab=" + encodeURI (bottomTab.val ());

		var qryForm = $('#' + viewerId + 'Report');
		formPostData += df2collectFormData (qryForm, 1);

		var panelLeft = $('#' + viewerId + 'PanelLeft');
		if (panelLeft.length)
			formPostData += df2collectFormData (panelLeft, 1);

		var panelRight = $('#' + viewerId + 'PanelRight');
		if (panelRight.length)
			formPostData += df2collectFormData (panelRight, 1);

		/*
		if (viewer.parent().hasClass('e10-wr-data'))
		{
			var widgetParams = viewer.parent().parent().find('div.e10-wr-params > div.params');
			formPostData += df2collectFormData (widgetParams, 1);
		}
		*/

		if (viewer.attr ('data-combo-column-target'))
		{
			var comboSearchValue = {};
			var inp = $('#'+viewer.attr ('data-combo-column-target')+' >input.e10-inputRefId');
			if (!inp.is('input'))
				inp = $('#'+viewer.attr ('data-combo-column-target')+' >ul>li.input>input');
			if (inp.is('input'))
				comboSearchValue ['fullTextSearch'] = inp.val();
			formPostData += '&'+$.param(comboSearchValue);
		}
		url += '&newElementId='+viewerId;

		if (viewer.attr('data-flow-params') !== undefined)
			url += '&viewerFlowParams='+encodeURI(viewer.attr('data-flow-params'));

		e10.server.setRemote(viewer);
		e10.server.postForm(url, formPostData, function(data) {
			var viewerLines = $('#' + viewerId + 'Items');

			if (data.object.flowParams !== undefined)
				viewer.attr('data-flow-params', data.object.flowParams);
			else
				viewer.attr('data-flow-params', '');

			var viewerMode = viewer.attr('data-mode');
			var rowElement = viewerLines.attr ('data-rowelement');
			if (appendLines)
			{
				if (viewerMode === 'panes')
				{
					viewerAppendContent(viewer, data.object);
				}
				else
				if (rowElement === 'tr')
				{
					viewerLines.find (">table tbody tr:last-child").detach();
					viewerLines.find ('>table tbody').append (data.object.htmlItems);
          viewerLines.find ('>table.dataGrid.main').floatThead('reflow');
        }
				else
				{
					viewerLines.find (">"+rowElement+":last-child").detach();
					var currCnt = viewerLines.find ('>'+rowElement).length;
					viewerLines.append (data.object.htmlItems);
					var vw = 0;

					window.requestAnimationFrame(
							function () {
								var newLines = viewerLines.find('>li');

								for (i = currCnt; i < newLines.length; i++) {
									var t = $(newLines[i]);
									var t1 = t.find('div.df2-list-item-t1');
									if (t1.attr('style'))
										continue;
									var i1 = t.find('span.df2-list-item-i1');
									if (!vw)
										vw = t1.parent().width();
									t1.width(vw - i1.width() - 5);
								}
							}
					);
				}
			}
			else
			{
				if (data.object.htmlPanelRight) {
					var panelRight = $('#' + viewerId + 'PanelRight');
					if (panelRight.hasClass('floating'))
					{
						var panelRightContent = panelRight.find('>div.params');
						panelRightContent.html(data.object.htmlPanelRight);
					}
					else
						panelRight.html(data.object.htmlPanelRight);
				}
				if (data.object.htmlCodeFullWidthToolbarAddButtons !== undefined)
				{
					var addButtonsElement = $('#' + viewerId + 'FWTAddButtons');
					addButtonsElement.html(data.object.htmlCodeFullWidthToolbarAddButtons);
				}
				if (viewerMode === 'panes')
				{
					viewerLines.html ('');
					viewerAppendContent(viewer, data.object);
				}
				else
				if (rowElement === 'tr')
				{
					viewerLines.find ('>table tbody').html (data.object.htmlItems);
                    viewerLines.find ('>table.dataGrid.main').floatThead('reflow');
				}
				else
				{
					viewerLines.html (data.object.htmlItems);
					viewerLines.find('>li').each (function () {
						var t1 = $(this).find ('div.df2-list-item-t1');
						var i1 = $(this).find ('span.df2-list-item-i1');
						t1.width(t1.parent().width() - i1.width() - 5);
					});
				}
				viewerLines.scrollTop (0);
			}
			viewerLines.attr ('data-rowspagenumber', data.object.rowsPageNumber);
			if ((focusPK) && (focusPK !== "")) {
				df2viewerFocusPK(viewerId, focusPK);
			}
			viewer.attr ('data-loadonprogress', 0);

			e10setProgressIndicator (viewerId, 0);
		});
  }
  else
  {
    var jqxhr = $.getJSON(url, function(data) {
	    var viewerLines = $('#' + viewerId + 'Items');
	    e10setProgress (viewerLines, 0);
	    viewerLines.html (data.object.htmlItems);
			//alert ("focusPK: " + focusPK);
			if ((focusPK) && (focusPK !== ""))
				df2viewerFocusPK (viewerId, focusPK);
			alert ('123');
    }).error(function() {alert("error 24: content not loaded (" + url + ")");});
  }
  return true;
}


/* attachments */

function e10AttWidgetFileSelected (input)
{
	var infoPanel = $(input).parent().find ('div.e10-att-input-files');

	var info = '<table>';
	for (var i = 0; i < input.files.length; i++)
	{
		var file = input.files[i];
    var fileSize = 0;
		if (file.size > 1024 * 1024)
			fileSize = (Math.round(file.size * 100 / (1024 * 1024)) / 100).toString() + 'MB';
		else
			fileSize = (Math.round(file.size * 100 / 1024) / 100).toString() + 'KB';
		info += '<tr>' + '<td>' + file.name + "</td><td class='number'>" + fileSize + '</td><td>-</td></tr>';
	}
	info += '</table>';
	infoPanel.html (info);
}


function e10AttWidgetUploadFile (button)
{
	var table = searchParentAttr (button, 'data-table');
	if (table === null)
		table = '_tmp';

	var pk = searchParentAttr (button, 'data-pk');

	var infoPanel = button.parent().parent().find ('div.e10-att-input-files');
	var input = button.parent().parent().find ('input:first').get(0);
	infoPanel.attr ('data-fip', input.files.length);
	for (var i = 0; i < input.files.length; i++)
	{
		var file = input.files[i];
		var url = httpApiRootPath + "/upload/e10.base.attachments/" + table + '/' + pk + '/' + file.name;
		e10AttWidgetUploadOneFile (url, file, infoPanel, i);
 	}
}


function e10AttWidgetUploadOneFile (url, file, infoPanel, idx)
{
	var xhr = new XMLHttpRequest();
	xhr.upload.addEventListener ("progress", function (e) {e10AttWidgetUploadProgress (e, infoPanel, idx);}, false);
	//xhr.upload.addEventListener ("load", function (e) {e10AttWidgetUploadDone (e, infoPanel, idx);}, false);
	xhr.onload = function (e) {e10AttWidgetUploadDone (e, infoPanel, idx);};
	xhr.open ("POST", url);
	xhr.setRequestHeader ("Cache-Control", "no-cache");
	xhr.setRequestHeader ("Content-Type", "application/octet-stream");
	xhr.send(file);

	/*
req.upload.addEventListener("progress", updateProgress, false);
req.upload.addEventListener("load", transferComplete, false);
req.upload.addEventListener("error", transferFailed, false);
req.upload.addEventListener("abort", transferCanceled, false);
	*/

}

function e10AttWidgetUploadDone (e, infoPanel, idx)
{
	var cell = infoPanel.find ('table tr:eq('+idx+') td:eq(2)');
	cell.css ({"background-color": "green"}).attr('data-ufn', e.target.responseText);
	var fip = parseInt (infoPanel.attr ('data-fip')) - 1;
	infoPanel.attr ('data-fip', fip);

	if (fip == 0)
	{
		if (infoPanel.parent().attr('data-table'))
		{
			viewerRefresh (infoPanel);
		}
        if (infoPanel.parent().attr('data-closewindow'))
        {
            e10CloseModal();
            $('body .dropzone').removeClass ('dropzone');
        }
	}
}

function e10AttWidgetUploadProgress (e, infoPanel, idx)
{
	if (e.lengthComputable)
	{
		var percentage = Math.round((e.loaded * 100) / e.total);
		var cell = infoPanel.find ('table tr:eq('+idx+') td:eq(2)');
		cell.text (percentage + ' % ');
	}
}

/* rows */

function e10RowsEditRefreshLayout (el)
{
	//alert ('e10AttWidgetRefreshLayout');
	var totalSizeY = el.height ();
	//var menuSizeY = el.find ('div.e10-att-input-menu').height ();
	var sizeY = totalSizeY /*- menuSizeY*/ - 2;
	var grid = el.find ('>ul');
	//grid.height (sizeY);
}


function changeFocusedDocumentRow (e, event)
{
	if (e.is ("tr"))
	{
		if (!e.hasClass ("active"))
		{
			e.parent().find ("tr.active").removeClass ("active");
			e.addClass ("active");
			//e.find ("input.vk-vkp-polozky-radek-mnozstvi").focus ();
		}
		return;
	}

	var row = searchObjectAttr (e, 'data-rowid');
	if (e.parent().hasClass('e10-rows-append'))
		row = e.parent();

	if (!row.hasClass ('active'))
	{
		row.parent().find ("li.active").removeClass ("active");
		row.addClass ("active");
	}
}


function e10StaticTab (e, event)
{
	e.parent().find ('li.active').removeClass ('active');
	e.addClass ('active');

	e.parent().parent().find('div.e10-static-tab-content>div.active').removeClass('active');
	$('#'+e.data('content-id')).addClass('active');

}


/*******************************/
function e10widgetChangeTab (e, event)
{
	e.parent().parent().find ('li.active').removeClass ('active');
	e.addClass ('active');
	e10widgetRefresh (0);
}

function e10widgetRefresh (setTabs)
{
	var e = $('#mainListViewMenu li.activeMainItem');
	if (!e.length)
		e = $('#smallPanelMenu>li.activeMainItem');

	if (e.attr('data-object') === 'subMenu')
		e = $('#mainBrowserLeftMenuSubItems >div.active>ul>li.active');

	var className = e.attr ("data-class");

	var urlPath = "/api/widget/" + className + "/html?fullCode=1";

	if (e.attr ("data-subclass"))
		urlPath += '&subclass=' + e.attr ("data-subclass");
	if (e.attr ("data-subtype"))
		urlPath += '&subtype=' + e.attr ("data-subtype");

	var activePanel = $('#mainWidgetTabs li.active');
	if (!activePanel.is ('LI'))
		activePanel = $('#smallWidgetTabs li.active');
	if (activePanel.is ('LI'))
	{
		if (setTabs === 0) {
			urlPath += "&widgetPanelId=" + activePanel.attr('data-subreport');
			e10.server.setRemote(activePanel);
		}
	}

	var paramsElement = $('#e10-tm-viewerbuttonbox');
	var params = df2collectFormData (paramsElement);
	urlPath += '&' + params;

	var browserContent = $("#mainBrowserContent");

	if (setTabs === 0)
	{
		browserContent.find("div:first div").remove();
		browserContent.find("div:first").html ("<div class='e10-reportContent'><i class='fa fa-spinner fa-spin fa-2x'></i></div>");
	}
	else
		e10setProgress (browserContent, 1);

	e10.server.get(urlPath, function(data)
	{
		if (setTabs === 1)
		{
			mainBrowserRefreshLayout ('mainBrowser', data.object);
			e10browserSetContent (data.object);
			e10setProgress (browserContent, 0);
		}
		else
		{
			//alert (data.htmlCodeToolbarViewer);
			$('#e10-tm-viewerbuttonbox').html (data.object.htmlCodeToolbarViewer);
			browserContent.find ("*:first").remove();
			browserContent.html (data.object.mainCode);
		}
		initSubObjects(data);
		setNotificationBadges();
	});
}

function e10WidgetParam (event, e)
{
	if (e.attr('data-call-function'))
	{
		e10.executeFunctionByName (e.attr ('data-call-function'), e);
		return;
	}

	var parentObject = searchObjectAttr (e, 'data-object');
	var parentObjectType = (parentObject) ? parentObject.attr ('data-object') : '';

	if (parentObjectType !== 'widget')
		return;

	e10WidgetAction (event, e);
}

function e10WidgetAction (event, e, widgetId)
{
	if (e && e.attr ('data-call-function') !== undefined)
	{
		e10.executeFunctionByName (e.attr ('data-call-function'), e);
		return;
	}

	var widget = null;
	if (widgetId === undefined)
		widget = searchObjectAttr (e, 'data-widget-class');
	else
		widget = $('#'+widgetId);

	var actionType = 'reload';
	if (e !== null)
		actionType = e.attr ("data-action");

	if (actionType === 'edit-iframe-doc')
	{
		try
		{
			var iframe = widget.find('iframe');
			var framePrimaryElement = iframe.contents().find('#e10-primary-page-content');
			if (!framePrimaryElement.length || !framePrimaryElement.attr('data-table'))
				framePrimaryElement = iframe.contents().find('body');

			var failed = 0;
			var editParams = {addparams: null};
			if (framePrimaryElement.attr('data-table'))
				editParams.table = framePrimaryElement.attr('data-table');
			else
				failed = 1;
			if (framePrimaryElement.attr('data-pk'))
				editParams.pk = framePrimaryElement.attr('data-pk');
			else
				failed = 1;

			editParams.srcobjecttype = 'iframe';
			editParams.srcobjectid = iframe.attr('id');
		} catch(err)
		{
			failed = 1;
		}
		if (failed)
		{
			alert ("Tuto stránku nelze editovat.");
		}
		else
			e10DocumentEdit(0, editParams);

		return;
	}
	else if (actionType === 'new-iframe-doc')
	{
		try
		{
			var iframe = widget.find('iframe');
			var framePrimaryElement = iframe.contents().find('#e10-primary-page-content');
			if (!framePrimaryElement.length || !framePrimaryElement.attr('data-table'))
				framePrimaryElement = iframe.contents().find('body');

			var failed = 0;
			var editParams = {addparams: null};
			if (framePrimaryElement.attr('data-table'))
				editParams.table = framePrimaryElement.attr('data-table');
			else
				failed = 1;
			if (framePrimaryElement.attr('data-addparams'))
				editParams.addparams = framePrimaryElement.attr('data-addparams');
			else
				failed = 1;

			editParams.srcobjecttype = 'iframe';
			editParams.srcobjectid = iframe.attr('id');
		} catch(err)
		{
			failed = 1;
		}
		if (failed)
		{
			alert ("Stránku nelze přidat.");
		}
		else
			e10DocumentAdd(0, editParams);

		return;
	}
	else if (actionType === 'open-iframe-tab')
	{
		try
		{
			var iframe = widget.find('iframe');
			var targetUrl = iframe[0].contentWindow.location.href;
			window.open(targetUrl, '_blank');
		} catch(err) {}
		return;
	}

	var postData = {};
	if (widget.attr ('data-collect') !== undefined)
	{
		var fn = window[widget.attr ('data-collect')];
		if (typeof fn === "function")
			postData = fn(widget);
	}
	else
	{
		e10collectFormData (widget, postData);
	}

	var fullCode = 0;
	if ((e && e.parent().hasClass('e10-wf-tabs')) || (widget.hasClass('e10-widget-viewer')))
		fullCode = 1;
	else if (!event && !e)
		fullCode = 1;

	if (e && e.parent().hasClass('e10-wf-tabs'))
	{
		var tabList = e.parent();
		var inputId = tabList.attr('data-value-id');
		if (inputId === undefined)
			inputId = (tabList.hasClass('right')) ? 'e10-widget-topTab-value-right' : 'e10-widget-topTab-value';
		$('#'+inputId).val (e.attr('data-tabid'));

		try {
			var iframeElement = widget.find('iframe');
			if (iframeElement.length)
				postData['iframeActiveUrl'] = iframeElement[0].contentWindow.location.href;
		} catch (err) {}
	}

	var className = widget.attr ("data-widget-class");
	var widgetParams = widget.attr ("data-widget-params");
	var oldWidgetId = widget.attr ('id');
	var urlPath = "/api/widget/" + className + "/html?fullCode="+fullCode+"&widgetAction="+actionType+'&widgetId='+oldWidgetId;
	if (widgetParams != '')
		urlPath += "&" + widgetParams;

	if (e !== null && e.attr('data-action-params'))
		urlPath += "&" + e.attr('data-action-params');

	var params = df2collectFormData (widget);
	if (params != '')
		urlPath += '&' + params;
	e10.server.setRemote(widget);
	e10.server.postForm(urlPath, postData, function (data)
	{
		if (widget.attr ('data-set-content-function') !== undefined && actionType !== 'reload')
		{
			var fn = window[widget.attr ('data-set-content-function')];
			if (typeof fn === "function")
				fn(widget, data);
		}
		else
		if (widget.hasClass('e10-widget-board') && !data.object.fullCode)
		{
			var content = widget.find('>div.e10-widget-content>div.e10-wr-data');
			content.find("*:first").remove();
			content.html(data.object.mainCode);
		}
		else
		if (widget.hasClass('e10-widget-board') && data.object.fullCode)
		{
			var browserContent = $("#e10dashboardWidget");
			browserContent.find ("*:first").remove();
			browserContent.html (data.object.mainCode);
			var content = widget.find('>div.e10-widget-content>div.e10-wr-data');
			setTimeout (function () {initSubObjects(data); }, 10);
		}
		else {
			widget.find("*:first").remove();
			widget.html(data.object.mainCode);
			if (className === 'e10.base.NotificationCentre')
				e10NCSetButton(data.object);
			setTimeout (function () {initSubObjects(data); }, 10);
		}
		setNotificationBadges();
	});
}

function e10WidgetInit (id)
{
	var w = $('#'+id);
	if (!w.length)
		return;

	w.find ('div.e10-widget-iframe').each (function ()
	{
		$(this).parent().parent().height($(this).parent().parent().parent().height() - 2);
		$(this).height($(this).parent().parent().parent().height() - 2);
	});

	w.find ('div.e10-max-height').each (function ()
	{
		$(this).parent().parent().height($(this).parent().parent().parent().height() - 2);
		$(this).height($(this).parent().parent().parent().height() - 2);
	});

	var w = $('#'+id);
	w.find ('div.e10-remote-widget').each (function ()
	{
		var id = $(this).attr ('id');
		e10LoadRemoteWidget(id);
	});

	boardWidgetRefreshLayout(id);
}

function e10LoadRemoteWidget (id)
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
}

/*******************************/
function e10MMToggle (button)
{
	var panel = $('#mainBrowserMM');
	if (panel.hasClass('open'))
	{
		panel.removeClass('open');
		panel.addClass('close');
	}
	else
	{
		if (button.parent().hasClass('e10-wf-tabs'))
		{
			var mainBrowserMMTopY = button.parent().height();
			var mainBrowser = $('#mainBrowser');
			var mm = $('#mainBrowserMM');
			mm.css({top: mainBrowserMMTopY});
			mm.height(mainBrowser.height() - mainBrowserMMTopY);
		}

		panel.removeClass('close');
		panel.addClass('open');
	}
}

function e10NCToggle ()
{
	var panel = $('#mainBrowserNC');
	if (panel.hasClass('open'))
	{
		panel.removeClass('open');
		panel.addClass('close');
	}
	else
	{
		panel.removeClass('close');
		panel.addClass('open');
	}
}


var g_NCTimer = 0;
function e10NCReload (actionType)
{
	var widget = $('#mainBrowserNC>div.e10-widget-pane');

	if (!widget.length)
		return;

	var className = widget.attr ("data-widget-class");
	var urlPath = "/api/widget/" + className + "/html?fullCode=0"+"&widgetAction="+actionType;

	e10.server.get(urlPath, function(data) {
			widget.find ("*:first").remove();
			widget.html (data.object.mainCode);
			e10NCSetButton (data.object);
			e10NCShowBubbles (data.object.notifications);
			initSubObjects(data);
		},
		function() {e10NCSetButton ({cntNotificationsText: '!', cntNotifications: 0});}
	);

	g_NCTimer = setTimeout (function () {e10NCReload ('init')}, 60000);
}
var g_activeNtfBadges = [];

function e10NCReset()
{
	clearTimeout(g_NCTimer);
	g_NCTimer = setTimeout (function () {e10NCReload ('init')}, 1000);
}

function e10NCSetButton (data)
{
	var ncButton = $('#e10-nc-button-cn');
	ncButton.text(data.cntNotificationsText);

	var ncIcon =$('#e10-nc-button>i');
	var appBrowserIconElement = $('#e10-browser-app-icon');

	var ncRunningActivities = $('#e10-nc-button-ra');
	if (data.runningActivities)
		ncRunningActivities.show();
	else
		ncRunningActivities.hide();

	if (data.cntNotifications > 0) {
		ncIcon.attr("class", "fa fa-bell-o");
		var iconFileName = e10dsIconServerUrl + 'imgs/-i256/-b./-v5/' + e10dsIconFileName;
		if (data.cntNotifications < 10)
			iconFileName = e10dsIconServerUrl + 'imgs/-i256/-b' + data.cntNotifications + '/-v5/' + e10dsIconFileName;
		appBrowserIconElement.attr ('href', iconFileName);
	}
	else {
		ncIcon.attr("class", "fa fa-bell-o e10-off");
		appBrowserIconElement.attr ('href', e10dsIcon);
	}

	g_activeNtfBadges = [];
	for (var badgeId in data.ntfBadges)
	{
		if (data.ntfBadges[badgeId])
			g_activeNtfBadges[badgeId] = data.ntfBadges[badgeId];
		setNotificationBadge(badgeId);
	}
}

function setNotificationBadges()
{
	for (var badgeId in g_activeNtfBadges)
	{
		setNotificationBadge(badgeId);
	}
}

function setNotificationBadge(badgeId)
{
	var ntfElement = $('#'+badgeId);
	if (ntfElement.length)
	{
		if (g_activeNtfBadges[badgeId])
			ntfElement.text(g_activeNtfBadges[badgeId]).show();
		else
			ntfElement.text('').hide();
	}
	ntfElement = $('#'+badgeId+'-sec');
	if (ntfElement.length)
	{
		if (g_activeNtfBadges[badgeId])
			ntfElement.text(g_activeNtfBadges[badgeId]).show();
		else
			ntfElement.text('').hide();
	}

}

function e10NCShowBubbles (notifications)
{
	if (notifications === undefined)
		return;
	for (var i = 0; i < notifications.length; i++)
	{
		var n = notifications[i];
		var opts = {text: n.msg};

		opts.type = 'error';
		if (n.title !== undefined)
			opts.title = n.title;
		if (n.icon !== undefined)
			opts.icon = n.icon;

		if (n.mode === 'top')
		{
			opts.stack = g_NotifyStackTopBar;
			opts.addclass = "stack-bar-top";
			opts.cornerclass = '';
			opts.animation = 'slide';
			opts.animate_speed = 150;
			opts.width = "100%";
		}
		else
		{
			opts.stack = g_NotifyStackTopRight;
			if (n.desktop !== undefined)
			{
				var dn = {icon: e10dsIcon, title: 'New notification 2'};

				opts.desktop = {desktop: true, icon: e10dsIcon};
				if (n.title !== undefined) {
					opts.title = n.title;
					dn.body = n.msg;
					dn.title = n.title;
				}
				if (n.msg !== undefined) {
					dn.body = n.msg;
				}
				if (window.Notification && Notification.permission !== 'denied')
				{
					if (Notification.permission === 'granted')
					{
						const notification = new Notification(dn.title, dn);
						return;
					}
					Notification.requestPermission().then(function(p) {
						if(p === 'granted') {
							const notification = new Notification(dn.title, dn);
						} else {
							console.log('User blocked notifications.');
						}
					}).catch(function(err) {
						console.error(err);
					});
				}

				PNotify.desktop.permission();
			}
		}
		new PNotify(opts);
	}
}

/**
 * scan to document support
 */
var g_ScanToDocumentTimer = 0;

function e10ScanToDocumentReload (widgetId, actionType)
{
	var widget = $('#'+widgetId);
	if (widget.length == 0)
		return;
	var table = widget.attr('data-table');
	var pk = widget.attr('data-pk');
	var className = widget.attr ("data-widget-class");
	var urlPath = httpApiRootPath + "/api/widget/" + className + "/html?fullCode=0"+"&widgetAction="+actionType+'&table='+table+'&pk='+pk;

	var jqxhr = $.getJSON(urlPath, function(data) {
		widget.find ("*:first").remove();
		widget.html (data.object.mainCode);
	}).error(function() {alert ('nejede to!');});

	g_ScanToDocumentTimer = setTimeout (function () {e10ScanToDocumentReload (widgetId, 'init')}, 10000);
}


/*******************************/

function e10ListParamToggle (e)
{
	var param = searchParentAttrElement(e, 'data-paramid');
	param.find('div.title.active').removeClass('active');
	e.addClass('active');
	param.find('input').val(e.attr('data-value')).trigger('change');
}

function e10ReportChangeParam (e)
{
	var param = searchObjectAttr (e, 'data-paramid');
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

function e10ReportPanelToggle (e)
{
	var panel = e.parent();
	if (panel.hasClass('open'))
	{
		panel.removeClass('open');
		panel.addClass('close');
	}
	else
	{
		panel.removeClass('close');
		panel.addClass('open');
	}
}

function e10selectReport (e, event)
{
	e.parent().find ('li.active').removeClass ('active');
	e.addClass ('active');
	e10refreshReport (1);
}

function e10refreshReport (setTabs)
{
	var widgetTest = $('#e10dashboardWidget');
	if (widgetTest.is ('DIV'))
	{
		e10widgetRefresh(0);
		return;
	}

	var activeReport = $('#e10reportWidget ul.e10-selectReport li.active');
	var activeSubReportId = 'subReportId=';
	if (setTabs !== 1)
	{
		var activeSubReport = $('#mainReportWidgetTabs li.active');
		if (activeSubReport.is ('LI'))
			activeSubReportId += activeSubReport.attr ('data-subreport');
	}
	var paramsElement = $('#e10-tm-viewerbuttonbox');
  var params = df2collectFormData (paramsElement);
	params += df2collectFormData ($('#e10-report-panel'), 1);

	var reportClass = activeReport.attr ('data-class');
	var urlPath = httpApiRootPath + "/api/report/" + reportClass + "/widget" + '?' + activeSubReportId + '&' + params;

	var browserContent = $("#e10reportWidget >div.e10-wr-content >div.e10-wr-data");
	var browserPanel = $("#e10reportWidget >div.e10-wr-content >div.e10-wr-params >div.params");

	browserContent.find("*:first").remove();
	var spinnerUrl = httpApiRootPath + '/www-root/sc/shipard/spinner-bars.svg';
	browserContent.html ("<div class='e10-reportContent'><img style='width: 2em; height: 2em;' src='"+spinnerUrl+"'></img>&nbsp;Přehled se připravuje, čekejte prosím...<br/></div>");
	var jqxhr = $.getJSON(urlPath, function(data) {
		browserContent.find("*:first").remove();
		browserContent.html (data.object.mainCode);

		$('#e10-tm-viewerbuttonbox').html (data.object.htmlCodeToolbarViewer);
		browserPanel.html (data.object.htmlCodeReportPanel);

		if (setTabs === 1)
			$('#mainBrowserRightBarDetails').html (data.object.htmlCodeDetails);
	}).error(function() {alert("error 27: content not loaded (" + urlPath + ")");});
}

function e10reportChangeTab (e, event)
{
	e.parent().find ('li.active').removeClass ('active');
	e.addClass ('active');
	e10refreshReport (0);
}


function e10printReport (printButton, event)
{
	event.stopPropagation();
	event.preventDefault();

	var reportClass = '';
	var activeSubReportId = 'subReportId=';
	var paramsElement = null;

	var params = '';
	if (printButton.attr ('data-params'))
		params = printButton.attr ('data-params');

	var bp = printButton.parent();
	if (bp.attr ('data-report-class'))
	{
		reportClass = bp.attr ('data-report-class');
		paramsElement = bp.parent();
		if (bp.attr ('data-subreport'))
			activeSubReportId += bp.attr ('data-subreport');
	}
	else
	{
		paramsElement = $('#e10-tm-viewerbuttonbox');
		var activeReport = $('#e10reportWidget ul.e10-selectReport li.active');
		var activeSubReport = $('#mainReportWidgetTabs li.active');
		if (activeSubReport.is('LI'))
			activeSubReportId += activeSubReport.attr('data-subreport');
		reportClass = activeReport.attr ('data-class');
	}

  params += df2collectFormData (paramsElement, (params === '') ? 0 : 1);
	params += df2collectFormData ($('#e10-report-panel'), 1);

	var format = 'pdf';
	if (printButton.attr ('data-format'))
		format = printButton.attr ('data-format');

	var urlPath = httpApiRootPath + "/api/report/" + reportClass + "/" + format + '?' + activeSubReportId + '&' + params;

	if (format === 'pdf')
	{
		if (e10embedded)
		{
			window.location = urlPath;
		}
		else
		{
			var width = (screen.width * 0.85) | 0;
			var height = (screen.height * 0.85) | 0;
			window.open(urlPath, "test", "location=no,status=no,resizable,width=" + width + ",height=" + height);
		}
	}
	else
	{
		//var dropDown = printButton.parent().parent().parent().find ('button.dropdown-toggle');
		//dropDown.html ("<i class='fa fa-spinner fa-spin'></i>");
		window.location = urlPath;
		//dropDown.html ("<span class='caret'></span>");
	}
}


function e10report (button, event)
{
	var reportClass = button.attr('data-class');
	var print = false;

	if (event !== undefined && event.shiftKey)
		print = true;

	var urlPath = httpApiRootPath + "/api/report/" + reportClass + "/pdf?mismatch=1";
	if (print)
		urlPath += '&print=1';

	button.toggleClass('activeMainItem');

	if (print)
	{
		$.get (urlPath, function( data ) {
			button.toggleClass('activeMainItem');
		});
	}
	else
	{
		if (e10embedded)
		{
			window.location = urlPath;
		}
		else
		{
			var width = (screen.width * 0.85) | 0;
			var height = (screen.height * 0.85) | 0;
			window.open(urlPath, "test", "location=no,status=no,resizable,width=" + width + ",height=" + height);
			button.toggleClass('activeMainItem');
		}
	}
}


// -- websockets servers
function wsSetState (serverIndex, socketState)
{
	var ws = webSocketServers[serverIndex];
	var serverIcon = $('#wss-'+ws.id);
	serverIcon.attr('class','e10-wss e10-wss-'+socketState);
}


function e10CheckBarcode (barcode)
{
	var browserContent = $("#mainBrowserContent");
	var e = browserContent.find ("div.df2-viewer");

	var activeDetail = e.find ("div.e10-mv-ld-content");
	var detailViewer = activeDetail.find ("div.df2-viewer");
	if (detailViewer.is ('DIV') && detailViewer.hasClass('addByBarcode'))
	{
		viewerAddRowFromSensor (detailViewer, 'barcode', barcode);
		return;
	}

	var searchInput = e.find ("input.fulltext");
	searchInput.val (barcode);
	viewerIncSearch (searchInput);
}

function e10SwitchBarcodeKbd (event, btn)
{
	var current = $('#'+g_focusedInputId);
//alert (g_focusedInputId)
	var e = $('#wss-bc-kbd');
	if (e.hasClass ('e10-bc-kbd-off'))
		e.removeClass ('e10-bc-kbd-off').addClass ('e10-bc-kbd-on');
	else
		e.removeClass ('e10-bc-kbd-on').addClass ('e10-bc-kbd-off');
	current.focus ();
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
		var msg = {'deviceId': deviceId, 'sensorId': sensorId, 'cmd': 'unlockSensor'};
		url += encodeURI (JSON.stringify (msg));
		$.getJSON(url, function(data){});
	}
	else
	{
		var msg = {'deviceId': deviceId, 'sensorId': sensorId, 'cmd': 'lockSensor'};
		url += encodeURI (JSON.stringify (msg));
		$.getJSON(url, function(data){});
	}
	e.toggleClass ('e10-sensor-on');
}


function viewerAddRowFromSensor (viewer, sensorType, sensorValue)
{
	var viewerId = viewer.attr ("id");
	var tableName = viewer.attr ("data-table");
	if (!tableName)
		return;

	var viewerOptions = viewer.attr ("data-viewer-view-id");

	var urlPath = '';
	var rowsPageNumber = 0;

	urlPath = httpApiRootPath + '/api/viewer/' + tableName + '/' + viewerOptions + '/html' + "?callback=?&rowsPageNumber=" + rowsPageNumber;
	urlPath += "&sensorType="+sensorType+"&sensorValue="+sensorValue;

	var queryParams = viewer.attr ("data-queryparams");
	if (queryParams)
		urlPath += '&' + queryParams;

	var ap = viewer.attr ('data-addparams');
	if (ap != '')
		urlPath += "&"+ap;

	var jqxhr = $.getJSON(urlPath, function(data) {
		var viewerLines = $('#' + viewerId + 'Items');
		viewerLines.prepend (data.object.htmlItems);
		if (viewerLines.find('>li').length > 70)
			viewerLines.find('>li:last').detach();
	}).error(function() {alert("error 18: content not loaded (" + urlPath + ")");});
}


// -- mqtt clients
function initMQTT ()
{
	if (typeof Paho == 'undefined')
		return;

	for (var i in webSocketServers)
	{
		mqttStartClient (i);
	}
}


function mqttStartClient (serverIndex, disableMessage)
{
	var ws = webSocketServers[serverIndex];

	if (ws.fqdn === null || ws.fqdn === '')
		return;
	var portNumber = parseInt(ws.port);
	if (portNumber === 0)
		return;

	ws.retryTimer = 0;

	ws.mqttClient = new Paho.MQTT.Client(ws.fqdn, portNumber, deviceId+"-"+Math.random().toString(36));

	ws.mqttClient.onConnectionLost = function() {
		setTimeout (function(){wsSetState (serverIndex, 'error');}, 200);
		webSocketServers[serverIndex].retryTimer = setTimeout (function (){mqttStartClient(serverIndex, 1);}, 3000);
	};
	ws.mqttClient.onMessageArrived = function(message) {mqttOnMessage (serverIndex, message);};

	ws.mqttClient.connect({
		onSuccess:function(){wsSetState (serverIndex, 'open'); mqttSubscribeAll (serverIndex);},
		onFailure:function(){wsSetState (serverIndex, 'error'); webSocketServers[serverIndex].retryTimer = setTimeout (function (){mqttStartClient(serverIndex, 1);}, 3000);},
		useSSL: true
		}
		);

	/*
	ws.mqttClient = new mqtt.connect('wss://' + ws.fqdn + ':' + portNumber);
	ws.mqttClient.on("connect", function(){wsSetState (serverIndex, 'open'); mqttSubscribeAll (serverIndex);});
	ws.mqttClient.on("message", function (message) { mqttOnMessage(serverIndex, message); });
	*/
}

function mqttSubscribeAll (serverIndex)
{
	var ws = webSocketServers[serverIndex];
	if (ws.topics === undefined)
		return;

	for (var topic in ws.topics)
	{
		//var si = ws.topics[i];
		ws.mqttClient.subscribe(topic);
	}
}


function mqttOnMessage (serverIndex, data)
{
	//console.log("onMessageArrived: `"+data.destinationName+"` "+data.payloadString);
	var ws = webSocketServers[serverIndex];
	if (ws.topics === undefined)
		return;
	if (ws.topics[data.destinationName] === undefined)
		return;

	var sensorInfo = ws.topics[data.destinationName];
	//console.log(sensorInfo);

	var mainMenuElementId = 'mqtt-sensor-' + sensorInfo['sensorId'];
	var mainMenuElement = $('#' + mainMenuElementId);
	if (mainMenuElement.length)
	{
		mainMenuElement.find('span.value').text(data.payloadString);
		if (sensorInfo.flags['qt'] !== undefined && sensorInfo.flags['qt'] === 20) {
			if (g_openModals.length !== 0) {
				var modalId = g_openModals [g_openModals.length - 1];
				var modalElement = $('#' + modalId);
				var receiveSensors = modalElement.attr('data_receivesensors');
				if (receiveSensors !== undefined) {
					var sids = receiveSensors.split(' ');
					for (i = 0; i < sids.length; i++) {
						$('#' + sids[i]).text(data.payloadString);
					}
				}
			}
		}
		if (g_camerasBarTimer !== 0)
		{
			clearTimeout (g_camerasBarTimer);
			g_camerasBarTimer = 0;
			camerasReload();
		}
	}

	// --- kbd emulation
	if (sensorInfo.flags['kbd'] !== undefined && sensorInfo.flags['kbd'])
	{
		var currentInput = $('#' + g_focusedInputId);
		if (!currentInput.length)
			currentInput = $(':focus');

		if (currentInput.length) {
			currentInput.val(data.payloadString);
			currentInput.trigger('change');
			if (currentInput.hasClass('e10-viewer-search'))
				viewerIncSearch(currentInput, null, 1);
		}
	}
}



// -- wizards

function e10WizardNext (input)
{
  var id = input.attr ('data-form');
	var form = $("#" + id);
	var uploadedFiles = [];
	var viewersPks = [];

	var fileInput = form.find (':input.e10-att-input-file').first();
	if (fileInput)
	{
		var infoPanel = fileInput.parent().find ('div.e10-att-input-files');
		if (infoPanel.attr ('data-fip'))
		{
			var fip = parseInt (infoPanel.attr ('data-fip'));
			if (fip !== 0)
			{
				setTimeout (function () {e10WizardNext (input);}, 100);
				return;
			}
		}
		else
		if (fileInput.val ())
		{
			e10AttWidgetUploadFile(fileInput);
			setTimeout (function () {e10WizardNext (input);}, 100);
			return;
		}
		form.find ('div.e10-att-input-files >table td').each (function () {
							if ($(this).attr ("data-ufn"))
								uploadedFiles.push ($(this).attr ("data-ufn"));
				});
	}

	// -- viewers
	form.find ('ul.df2-viewer-list >li.active').each (function () {
		if ($(this).attr ("data-pk"))
			viewersPks.push ($(this).attr ("data-pk"));
	});

	var e = $("#" + id);
	var codeTarget = e.parent().parent();
  //var formElement = document.getElementById (id);

	var wizardClass = e.attr ("data-formid");
	var wizardPage = parseInt (e.attr ("data-wizardpage")) + 1;
	var url = "/api/wizard/" + wizardClass + "/" + wizardPage + "?callback=?&newFormId=" + id;
	if (input.attr('data-docstate') !== undefined)
		url += '&setNewDocState='+input.attr('data-docstate');

  var formData = df2collectEditFormData (e, $.myFormsData [id]);
	formData ['recData']['uploadedFiles'] = uploadedFiles;
	formData ['recData']['viewersPks'] = viewersPks;
	var focusedId = input.attr ('id');

	var btns = $("#" + id + 'Buttons');
	btns.html ("<i class='fa fa-spinner fa-spin fa-2x'></i> Čekejte, prosím...");

	e10.server.post(url, formData, function(data)
	{
			codeTarget.html (data.mainCode);

			var sidebar = $("#" + id + 'Sidebar');

			var header = $("#" + id + 'Header');
			header.html (data.htmlHeader);
			var buttons = $("#" + id + 'Buttons');
			buttons.show();
			buttons.html (data.buttonsCode);

			df2FormsSetData (id, data);

			var form = $("#" + id);
			e10DecorateFormWidgets (form);
			e10doSizeHints (form);

			if (data.sidebarCode != null)
			{
				sidebar.html (data.sidebarCode);
				sidebar.find ('div.df2-viewer').each (function () {
							var viewerId = $(this).attr ("id");
							initViewer (viewerId);
				});
			}

			if (data.flags.autofocus === undefined)
				$("#" + id + " input[type='text']:visible,textarea:visible,div.e10-inputDocLink:visible").first().focus();
			else
				$("#" + id + " input.autofocus").first().focus();

			if (data.stepResult && data.stepResult.close == 1)
			{
				var modalElement = $('#'+ id + 'Form');
				var srcObjectType = modalElement.attr ('data-srcObjectType');
				var srcObjectId = modalElement.attr ('data-srcObjectId');

				e10ViewerCancelForm (form);

				if (srcObjectType === 'viewer')
				{
						if (srcObjectId === 'default')
								srcObjectId = $('#mainBrowserContent div.e10-mainViewer').attr ('id');

					if (data.stepResult && data.stepResult.refreshDetail == 1) {
						var activeItem = $("#" + srcObjectId + "Items li.active");
						if (activeItem.is ('LI')) {
							viewerItemClick(activeItem);
							viewerItemClick(activeItem);
						}
					}
					else
						viewerRefresh($('#' + srcObjectId), data.recData ['ndx']);
				}
				else
				if (srcObjectType === 'widget')
					e10WidgetAction (null, null, srcObjectId);
				else if (srcObjectType == 'form-to-save')
				{
					e10SaveOnChange ($('#'+srcObjectId));
				}

				if (data.stepResult.addDocument == 1)
					e10DocumentAdd (0, data.stepResult.params);
				else
				if (data.stepResult.editDocument == 1)
					e10DocumentEdit(0, data.stepResult.params);
			}
			else
			if (data.stepResult && data.stepResult.restartApp == 1)
			{
				e10CloseModals ();
				location.replace(httpApiRootPath + '/app/dashboard');
			}
            else
            if (data.stepResult && data.stepResult.reloadPage == 1)
            {
                e10CloseModals ();
                location.reload();
            }

			$('#' + focusedId).focusNextInputField();
  });
}

// ---- cameras

var g_camerasBarTimer = 0;
function camerasReload ()
{
	if (g_appWindowsCamerasPictures === undefined || g_appWindowsCamerasPictures.servers === undefined)
		return;

	var pictsWidth = 380;
	var boxElement = $("#mainBrowserRightBarTop>div.camPicts");
	if (boxElement.length)
		pictsWidth = (boxElement.innerWidth() | 0) - 22;
	for (var si in g_appWindowsCamerasPictures.servers)
	{
		var srv = g_appWindowsCamerasPictures.servers[si];
		var urlPath = srv.url + "/cameras?callback=?";
		var jqxhr = $.getJSON(urlPath, function(data) {
			for (var ii in data)
			{
				var picFileName = srv.url + 'imgs/-w'+pictsWidth+'/-q70/' + ii + "/" + data[ii].image;
				var origPicFileName = srv.url + '/imgs/' + ii + "/" + data[ii].image;

				var rightPicture = $('#e10-cam-' + ii + '-right');
				if (rightPicture.length)
					rightPicture.attr ("src", picFileName).parent().attr ("data-pict", origPicFileName);
			}

			g_camerasBarTimer = setTimeout (camerasReload, 10000);
		});
	}
}


// --- clock

function bigClock()
{
	var thetime = new Date();
	var nhours= thetime.getHours();
	var nmins = thetime.getMinutes();
	var nsecs = thetime.getSeconds();
	if (nmins < 10)
		nmins = '0' + nmins;

	$('#big-clock').text (nhours + ':' + nmins);
	setTimeout (bigClock ,1000 * (60 - nsecs));
}




