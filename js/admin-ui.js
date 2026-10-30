(function () {
  'use strict';
  document.querySelectorAll('.admin-author-flash.success,.operations-alert.success,.form-success').forEach(function (notice) {
    var message = (notice.innerText || '').trim();
    if (!message) return;
    var toast = document.createElement('div');
    toast.className = 'admin-toast'; toast.setAttribute('role', 'status');
    toast.innerHTML = '<span class="admin-toast-icon" aria-hidden="true">✓</span><span></span>';
    toast.lastElementChild.textContent = message;
    document.body.appendChild(toast); notice.remove();
    window.setTimeout(function () { toast.classList.add('is-leaving'); toast.remove(); }, 5000);
  });

  var staffFilters = document.querySelectorAll('[data-staff-filter]');
  if (staffFilters.length) {
    staffFilters.forEach(function (button) {
      button.addEventListener('click', function () {
        var role = button.dataset.staffFilter;
        staffFilters.forEach(function (item) { item.setAttribute('aria-pressed', item === button ? 'true' : 'false'); });
        document.querySelectorAll('.staff-account-card[data-role]').forEach(function (card) { card.hidden = role !== 'all' && card.dataset.role !== role; });
      });
    });
  }

  var securityStaffFilters = document.querySelectorAll('[data-security-staff-filter]');
  if (securityStaffFilters.length) {
    var applySecurityStaffFilter = function (status) {
      document.querySelectorAll('.security-staff-row[data-status]').forEach(function (row) {
        row.hidden = status !== 'all' && row.dataset.status !== status;
      });
    };
    securityStaffFilters.forEach(function (button) {
      button.addEventListener('click', function () {
        var status = button.dataset.securityStaffFilter;
        securityStaffFilters.forEach(function (item) { item.setAttribute('aria-pressed', item === button ? 'true' : 'false'); });
        applySecurityStaffFilter(status);
      });
    });
    var selectedSecurityFilter = Array.prototype.find.call(securityStaffFilters, function (button) { return button.getAttribute('aria-pressed') === 'true'; });
    if (selectedSecurityFilter) applySecurityStaffFilter(selectedSecurityFilter.dataset.securityStaffFilter);
  }

  var authorsClientFilter = document.querySelector('[data-authors-client-filter]');
  if (authorsClientFilter) authorsClientFilter.addEventListener('click', function () {
    var active = authorsClientFilter.getAttribute('aria-pressed') !== 'true';
    authorsClientFilter.setAttribute('aria-pressed', active ? 'true' : 'false');
    document.querySelectorAll('.admin-author-card[data-client-visible]').forEach(function (card) { card.hidden = active && card.dataset.clientVisible !== '1'; });
    authorsClientFilter.textContent = active ? 'Showing client-visible' : 'Client-visible authors';
  });

  var dialog = document.getElementById('admin-book-dialog');
  if (dialog) {
    var close = dialog.querySelector('[data-dialog-close]');
    var put = function (key, value, wide) {
      var box = document.createElement('div'); if (wide) box.className = 'wide';
      var dt = document.createElement('dt'); dt.textContent = key;
      var dd = document.createElement('dd'); dd.textContent = value || 'Not provided';
      box.append(dt, dd); dialog.querySelector('.admin-book-dialog-info').appendChild(box);
    };
    document.querySelectorAll('.catalog-card').forEach(function (card) {
      card.tabIndex = 0; card.setAttribute('role', 'button'); card.setAttribute('aria-haspopup', 'dialog');
      function open() {
        var data = card.dataset;
        dialog.querySelector('#admin-book-dialog-title').textContent = data.title || 'Book details';
        var cover = dialog.querySelector('.admin-book-dialog-cover'); cover.textContent = '';
        if (data.cover) { var image = document.createElement('img'); image.src = data.cover; image.alt = (data.title || 'Book') + ' cover'; cover.appendChild(image); }
        var info = dialog.querySelector('.admin-book-dialog-info'); info.textContent = '';
        put('Author(s)', data.authors); put('Edition / Format', data.format ? data.format.charAt(0).toUpperCase() + data.format.slice(1) : '');
        put('ISBN', data.isbn); put('Language', data.language);
        put('Publisher', data.publisher); put('Genre(s)', data.genres);
        put('Price', '₱' + data.price); put('Quantity in stock', data.stock);
        put('Book description', data.description, true);
        put('Catalog status', data.status + ' · ' + data.review, true);
        if (typeof dialog.showModal === 'function') dialog.showModal();
      }
      card.addEventListener('click', function (event) { if (event.target.closest('a,button,form,input,select')) return; open(); });
      card.addEventListener('keydown', function (event) { if (event.target !== card) return; if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } });
    });
    if (close) close.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
  }
})();
