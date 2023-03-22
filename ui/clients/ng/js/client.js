
//const g_isMobile = window.matchMedia("(any-pointer:coarse)").matches;


class ShipardClient {
	server = new ShipardServer();
	mqtt = new ShipardMqtt();
	iot = new ShipardClientIoT();

	appVersion = '2.0.1';
	CLICK_EVENT = 'click';
	g_formId = 1;
	openModals = [];
	progressCount = 0;
	viewerScroll = 0;
	disableKeyDown = 0;

	userInfo = null;

	on(eventType, selector, callback) {
		document.addEventListener(eventType, function (event) {
			if (event.target.matches(selector)) {
				callback.call(event.target);
			}
		});
	}

	onClick(selector, callback) {this.on('click', selector, callback)};



	/**
	 * simple tabs
	 */
	simpleTabsEvent(e)
	{
		let tabsId = e.getAttribute('data-tabs');
		let tabsElement = document.getElementById(tabsId+'-tabs');
		let oldActiveTabElement = tabsElement.querySelector('a.active');
		oldActiveTabElement.classList.remove('active');

		let oldActiveContentId = oldActiveTabElement.getAttribute('data-tab-id');
		let oldActiveContentElement = document.getElementById(oldActiveContentId);
		oldActiveContentElement.classList.add('d-none');

		e.classList.add('active');
		let newActiveContentId = e.getAttribute('data-tab-id');
		let newActiveContentElement = document.getElementById(newActiveContentId);
		newActiveContentElement.classList.remove('d-none');
	}


	/**
	 * upload files & camera support
	 */
	searchParentAttr (e, attr) {
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

	searchObjectAttr (e, attr) {
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

	init () {
		//console.log("ShipardClient INIT...");
		this.server.setHttpServerRoot(httpApiRootPath);
		//console.log("server initialized...");



		this.onClick ('a.shp-simple-tabs-item', function () {shc.simpleTabsEvent(this);});



	}
}






