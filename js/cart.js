(() => {
  'use strict';

  document.querySelectorAll('[data-cart-toast]').forEach((toast) => {
    window.setTimeout(() => {
      toast.classList.add('is-leaving');
      window.setTimeout(() => toast.remove(), 220);
    }, 5000);
  });
})();
