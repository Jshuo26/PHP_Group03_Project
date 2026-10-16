document.addEventListener('DOMContentLoaded', function () {

  var banner = document.getElementById('cookieBanner');
  var acceptBtn = document.getElementById('cookieAccept');
  var declineBtn = document.getElementById('cookieDecline');

  if (!banner || !acceptBtn || !declineBtn) {
    return;
  }

  var ONE_YEAR = 60 * 60 * 24 * 365;

  function saveChoice(choice) {
    var cookie = 'cookie_consent=' + choice +
      '; max-age=' + ONE_YEAR +
      '; path=/; SameSite=Lax';

    if (window.location.protocol === 'https:') {
      cookie += '; Secure';
    }

    document.cookie = cookie;
    banner.hidden = true;

    document.dispatchEvent(new CustomEvent('cookieconsent', {
      detail: { choice: choice }
    }));
  }

  acceptBtn.addEventListener('click', function () {
    saveChoice('accepted');
  });

  declineBtn.addEventListener('click', function () {
    saveChoice('declined');
  });

});