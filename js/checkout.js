document.addEventListener('DOMContentLoaded', function () {
  var savedAddressSelect = document.getElementById('saved_address_id');
  if (savedAddressSelect) {
    savedAddressSelect.addEventListener('change', function () {
      var option = savedAddressSelect.options[savedAddressSelect.selectedIndex];
      if (!option) return;
      var fields = {
        shipping_name: option.dataset.recipient || '',
        shipping_mobile: option.dataset.mobile || '',
        shipping_street: option.dataset.street || '',
        shipping_city: option.dataset.city || '',
        shipping_province: option.dataset.province || '',
        shipping_zip: option.dataset.zip || ''
      };
      Object.keys(fields).forEach(function (id) {
        var field = document.getElementById(id);
        if (field) field.value = fields[id];
      });
    });
  }

  var paymentPills = document.querySelectorAll('.payment-method-pill');

  paymentPills.forEach(function (pill) {
    pill.addEventListener('click', function () {
      paymentPills.forEach(function (p) {
        p.classList.remove('active');
        var r = p.querySelector('input[type="radio"]');
        if (r) r.checked = false;
      });

      pill.classList.add('active');
      var radio = pill.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
    });
  });
});
