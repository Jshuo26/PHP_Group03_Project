document.addEventListener('DOMContentLoaded', function () {

  document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.getElementById(button.dataset.target);
      if (!input) {
        return;
      }

      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';

      button.classList.toggle('is-visible', show);
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  var form = document.getElementById('loginForm');
  if (!form) {
    return;
  }

  var emailInput = document.getElementById('email');
  var passwordInput = document.getElementById('password');
  var emailError = document.getElementById('emailError');
  var passwordError = document.getElementById('passwordError');
  var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function setError(input, errorEl, message) {
    errorEl.textContent = message;
    input.classList.toggle('has-error', message !== '');
    input.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
  }

  function validateEmail() {
    var value = emailInput.value.trim();
    var message = '';

    if (value === '') {
      message = 'Email is required.';
    } else if (!emailPattern.test(value)) {
      message = 'Please enter a valid email address.';
    }

    setError(emailInput, emailError, message);
    return message === '';
  }

  function validatePassword() {
    var message = passwordInput.value === '' ? 'Password is required.' : '';
    setError(passwordInput, passwordError, message);
    return message === '';
  }

  emailInput.addEventListener('blur', validateEmail);
  passwordInput.addEventListener('blur', validatePassword);

  emailInput.addEventListener('input', function () {
    if (emailInput.classList.contains('has-error')) {
      validateEmail();
    }
  });

  passwordInput.addEventListener('input', function () {
    if (passwordInput.classList.contains('has-error')) {
      validatePassword();
    }
  });

  form.addEventListener('submit', function (event) {
    var emailOk = validateEmail();
    var passwordOk = validatePassword();

    if (!emailOk || !passwordOk) {
      event.preventDefault();
      (emailOk ? passwordInput : emailInput).focus();
    }
  });

});