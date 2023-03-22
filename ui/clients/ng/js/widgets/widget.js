class ShipardWidget {
  rootElm = null;
  rootId = '';

  init (rootElm)
  {
    this.rootElm = rootElm;
    this.rootId = this.rootElm.getAttribute('id');
    console.log("hello from ShipardWidget", this.rootId);

    this.on(this, 'click', '.shp-widget-action', function (e, ownerWidget){ownerWidget.widgetAction(e)});
  }

  widgetAction(e)
  {
    let actionId = e.getAttribute('data-action');
    this.doAction(actionId, e);
  }

  doAction (actionId, e)
  {
    return 0;
  }

	on(ownerWidget, eventType, selector, callback) {
		this.rootElm.addEventListener(eventType, function (event) {
			if (event.target.matches(selector)) {
				callback.call(event.target, event.target, ownerWidget);
			}
		});
	}

	onClick(ownerWidget, selector, callback) {this.on(ownerWidget, 'click', selector, callback)};


  nf (n, c)
  {
    var
        c = isNaN(c = Math.abs(c)) ? 2 : c,
        d = '.',
        t = ' ',
        s = n < 0 ? "-" : "",
        i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "",
        j = (j = i.length) > 3 ? j % 3 : 0;
    return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
  }

  parseFloat (n) {
    var str = n.replace (',', '.');
    return parseFloat(str);
  }

  round (value, decimals) {
    return Number(Math.round(value+'e'+decimals)+'e-'+decimals);
  }

  escapeHtml (str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  };

  elmHide(e)
  {
		e.classList.add('d-none');
  }

  elmShow(e)
  {
		e.classList.remove('d-none');
  }
}

