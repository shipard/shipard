class ShipardTouchNumPad extends ShipardWidget
{
  elmDisplay = null;
  gnId = 'gn1234';
  gnValue = '';
  options = null;

  init (rootElm)
  {
    super.init(rootElm);

    var c = "<div class='shp-numpad-container' id='" + this.gnId + "'>";
    c += "<table class='shp-numpad-keyboard'>";

    c += "<tr>";
    c += "<td class='c shp-widget-action' data-action='pressCancel'>✕</td><td class='m' colspan='3'>";
    if (this.options.title)
      c += "<div class='title'>"+this.escapeHtml(this.options.title)+"</div>";
    if (this.options.subtitle)
      c += "<div class='e10-small'>"+this.escapeHtml(this.options.subtitle)+"</div>";
    c += "</td>";
    c += "</tr>";


    c += "<tr>";
    c += "<td class='d shp-widget-action' colspan='3'></td><td class='b shp-widget-action' data-action='pressBackspace'>←</td>";
    c += "</tr>";


    c += "<tr>";
    c += "<td class='n shp-widget-action' data-action='pressKey'>7</td><td class='n shp-widget-action' data-action='pressKey'>8</td><td class='n shp-widget-action' data-action='pressKey'>9</td><td class='ok shp-widget-action' data-action='pressOK' rowspan='4'>✔︎</td>";
    c += "</tr>";

    c += "<tr>";
    c += "<td class='n shp-widget-action' data-action='pressKey'>4</td><td class='n shp-widget-action' data-action='pressKey'>5</td><td class='n shp-widget-action' data-action='pressKey'>6</td>";
    c += "</tr>";

    c += "<tr>";
    c += "<td class='n shp-widget-action'  data-action='pressKey'>1</td><td class='n shp-widget-action' data-action='pressKey'>2</td><td class='n shp-widget-action' data-action='pressKey'>3</td>";
    c += "</tr>";

    c += "<tr>";
    c += "<td class='n shp-widget-action' colspan='2' data-action='pressKey'>0</td><td class='n shp-widget-action' data-action='pressKey'>,</td>";
    c += "</tr>";

    c += "</table>";

    c += "</div>";

    this.rootElm.innerHTML = c;
    this.elmDisplay = this.rootElm.querySelector('td.d');
  }

  doAction (actionId, e)
  {
    switch (actionId)
    {
      case 'pressKey': return this.kbdPressKey(e);
      case 'pressBackspace': return this.kbdPressBackspace(e);
      case 'pressCancel': return this.kbdPressCancel(e);
      case 'pressOK': return this.kbdPressOK(e);
    }
    return super.doAction (actionId, e);
  }

  kbdPressKey(e)
  {
    const c = e.innerText;
    this.gnValue += c;
    this.elmDisplay.innerText = this.gnValue;

    return 0;
  }

  kbdPressBackspace(e)
  {
    if (!this.gnValue.length)
      return;

    this.gnValue = this.gnValue.slice(0, -1);
    this.elmDisplay.innerText = this.gnValue;

    return 0;
  }

  kbdPressCancel(e)
  {
    this.rootElm.remove();
  }

  kbdPressOK(e)
  {
    this.options.success(null);
  }
}
