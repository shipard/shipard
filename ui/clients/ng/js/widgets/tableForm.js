class ShipardTableForm extends ShipardWidget
{
  formData = null;

  init(e)
  {
    console.log("ShipardTableForm::init");
    super.init(e);
    this.rootElm.style.display = 'grid';

    let apiParams = {
      'cgType': 2,
      'formOp': e.formOp,
    };

    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    this.apiCall('createForm', apiParams);
  }

  doAction (actionId, e)
  {
    //console.log("form action!", actionId);
    switch (actionId)
    {
      case 'saveForm': return this.saveForm(e);
      case 'saveform': return this.saveForm(e);
      case 'closeForm': return this.closeForm(e);
    }

    return super.doAction(actionId, e);
  }

  saveForm(e)
  {
    const noClose = parseInt(e.getAttribute('data-noclose'));

    this.getFormData();

    let apiParams = {
      'cgType': 2,
      'formOp': 'save',
      'formData': this.formData,
      'noCloseForm': noClose,
    };


    this.elementPrefixedAttributes (this.rootElm, 'data-action-param-', apiParams);
    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    this.apiCall('saveForm', apiParams);

    return 0;
  }

  checkForm(changedInput)
  {
    this.getFormData();

    let apiParams = {
      'cgType': 2,
      'formOp': 'check',
      'formData': this.formData,
      'noCloseForm': 1,
    };


    this.elementPrefixedAttributes (this.rootElm, 'data-action-param-', apiParams);
    //this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    this.apiCall('checkForm', apiParams);

    return 0;
  }


  doWidgetResponse(data)
  {
    //console.log("doWidgerResponse / FORM: ", data['response']['type']);

    if (data['response']['type'] === 'createForm')
    {
      this.rootElm.innerHTML = data['response']['hcFull'];
      this.setFormData(data['response']['formData']);

      this.on(this, 'change', 'input', function (e, ownerWidget){ownerWidget.inputValueChanged(e)});

      return;
    }
    if (data['response']['type'] === 'saveForm')
    {
      //console.log("---SAVE-FORM---", data['response']);

      let noCloseForm = data['response']['saveResult']['noCloseForm'] ?? 0;
      //console.log('noCloseForm: ', noCloseForm);

      if (!noCloseForm)
      {
        const parentWidgetType = this.rootElm.getAttribute('data-parent-widget-type');
        //console.log('parentWidgetType: ', parentWidgetType);
        if (parentWidgetType === 'viewer')
        {
          const parentWidgetId = this.rootElm.getAttribute('data-parent-widget-id');
          if (parentWidgetId)
          {
            const parentElement = document.getElementById(parentWidgetId);
            if (parentElement)
              parentElement.shpWidget.refreshData();
          }
        }
        else if (parentWidgetType === 'board')
        {
          const parentWidgetId = this.rootElm.getAttribute('data-parent-widget-id');
          if (parentWidgetId)
          {
            const parentElement = document.getElementById(parentWidgetId);
            if (parentElement)
              parentElement.shpWidget.refreshData();
          }
        }

        this.closeForm();
        return;
      }

      this.rootElm.innerHTML = data['response']['hcFull'];
      this.setFormData(data['response']['formData']);
      return;
    }

    if (data['response']['type'] === 'checkForm')
    {
      //console.log("---CHECK-FORM---", data['response']);
      this.rootElm.innerHTML = data['response']['hcFull'];
      this.setFormData(data['response']['formData']);
      return;
    }

    super.doWidgetResponse(data);
  }

  setFormData(data)
  {
    this.formData = data;
    //console.log('setFormData', data);
    const inputs = this.rootElm.querySelectorAll('input, textarea, select');

    inputs.forEach(input => {
      this.setFormInputValue(input);
    });
  }

  setFormInputValue(input)
  {
    const inputId = input.getAttribute('name');
    if (!inputId)
      return;

    const iv = this.dataInputValue(inputId);
    //console.log('setFormInputValue', inputId, iv);

    if (input.classList.contains('e10-inputDateN'))
    {
      let siv = iv;
      if (iv === null || iv === '0000-00-00')
        siv = '';

      input.value = siv;
      return;
    }
    if (input.classList.contains('e10-inputLogical'))
    {
      input.checked = parseInt(iv) == 1;
      return;
    }

    //console.log('set input value ', iv, input);
    input.value = iv;
  }

  dataInputValue (inputId)
  {
    var iidParts = inputId.split ('.');

		if (iidParts.length == 1)
    {
      return this.formData['recData'][inputId] ? this.formData['recData'][inputId] : null;
    }

    return null;
  }

  getFormData()
  {
    const inputs = this.rootElm.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
      this.getFormInputValue(input);
    });
  }

  getFormInputValue(input)
  {
    const inputId = input.getAttribute('name');
    if (!inputId)
      return;

    const iv = input.value;
    //console.log('getFormInputValue', inputId, iv);

    let siv = iv;

    if (input.classList.contains('e10-inputDateN'))
    {
      if (iv === null || iv === '0000-00-00' || iv === '')
        siv = null;
    }
    else if (input.classList.contains('e10-inputLogical'))
    {
      siv = input.checked ? 1 : 0;
    }

    this.setDataInputValue(inputId, siv);
  }

  setDataInputValue (inputId, value)
  {
    var iidParts = inputId.split ('.');

		if (iidParts.length == 1)
    {
      this.formData['recData'][inputId] = value;
    }
  }

  closeForm(e)
  {
    this.rootElm.remove();

    return 0;
  }

  inputValueChanged(e)
  {
    //console.log("--INPUT-CHANGED--", e);
    if (e.classList.contains('e10-ino-checkOnChange'))
    {
      this.checkForm(e);
    }
  }
}
