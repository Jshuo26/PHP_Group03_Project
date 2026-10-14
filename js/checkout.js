document.addEventListener('DOMContentLoaded', function () {
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