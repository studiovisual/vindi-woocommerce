class vindiPix extends HTMLElement {

  constructor() {
    // establish prototype chain
    super();

    this.dialog           = document.getElementById('vindi-pix-dialog');
    this.image            = this.dialog?.querySelector('.vindi-pix-dialog__image');
    this.input            = this.dialog?.querySelector('.vindi-pix-dialog__input');
    this.button           = this.dialog?.querySelector('.vindi-pix-dialog__button');
    this.eventSuccess     = new CustomEvent('success');
    this.eventCopySuccess = new CustomEvent('copysuccess');
    this.eventCopyError   = new CustomEvent('copyerror');

    this.button?.addEventListener('click', (event) => this.copy(event));
  }

  showDialog(bill) {
    if(!bill)
      return;

    const response = bill.charges[0].last_transaction['gateway_response_fields'];

    if(this.image)
      this.image.src = response['qrcode_path'];
    if(this.input)
      this.input.value = response['qrcode_original_path'];
    
    this.dialog?.showModal();
    // this.registerEventSource(bill);
  }

  async copy(event) {
    event.preventDefault();

    try {
        await navigator.clipboard.writeText(this.input.value);

        this.dispatchEvent(this.eventCopySuccess);
    }
    catch(error) {
        console.error('copy error', error);

        this.dispatchEvent(this.eventCopyError);
    }
  }

  registerEventSource(bill) {
    if(!bill)
      return;

    const evtSource = new EventSource(`${window.vindi_woocommerce_pix.pix_handler}?id=${bill['id']}`);

    evtSource.onerror = (err) => console.error("EventSource failed:", err);

    evtSource.onmessage = (event) => {
      if(event.data == 'paid') {
        this.dialog?.close();
        evtSource.close();

        this.dispatchEvent(this.eventSuccess);
      }
    };
  }

}

customElements.define('vindi-pix', vindiPix);
