(function () {
  const box = document.getElementById('lockCountdown');
  if (!box) return;

  const timer  = document.getElementById('lockTimer');
  const button = document.getElementById('loginButton');

  const endTime = Date.now() + parseInt(box.dataset.seconds, 10) * 1000;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function tick() {
    const left = Math.max(0, Math.ceil((endTime - Date.now()) / 1000));

    timer.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);

    if (left <= 0) {
      clearInterval(interval);
      box.textContent = 'You can try logging in again now.';
      box.classList.add('lock-countdown--done');
      button.disabled = false;
    }
  }

  tick();
  const interval = setInterval(tick, 1000);
})();