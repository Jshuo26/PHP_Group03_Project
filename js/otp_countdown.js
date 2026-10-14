(function () {

  const input = document.getElementById('otp_code');

  if (input) {
    input.addEventListener('input', function () {
      input.value = input.value.replace(/\D/g, '').slice(0, 6);
    });
  }

  const box = document.getElementById('otpCountdown');

  if (!box) {
    return;
  }

  const timer  = document.getElementById('otpTimer');
  const button = document.getElementById('otpVerifyButton');
  const endTime = Date.now() + parseInt(box.dataset.seconds, 10) * 1000;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function tick() {

    const left = Math.max(0, Math.ceil((endTime - Date.now()) / 1000));

    timer.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);

    if (left <= 0) {

      clearInterval(interval);

      box.textContent = 'This code has expired. Click "Resend code" to get a new one.';

      if (button) {
        button.disabled = true;
      }
    }
  }

  tick();

  const interval = setInterval(tick, 1000);

})();