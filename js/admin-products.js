(function () {
  'use strict';
  var button = document.getElementById('search-book');
  var form = document.getElementById('book-form');
  if (!button || !form) return;
  var token = form.querySelector('[name="csrf_token"]').value;
  var status = document.getElementById('lookup-status');
  var results = document.getElementById('lookup-results');
  var fields = {
    title: document.getElementById('title'), author: document.getElementById('author'),
    description: document.getElementById('description'), isbn: document.getElementById('isbn'),
    language: document.getElementById('language'), publisher: document.getElementById('publisher'),
    format: document.getElementById('format'), price: document.getElementById('price'),
    quantity: document.getElementById('stock_quantity'), authorBio: document.getElementById('author_description'),
    cover: document.getElementById('cover_url'), coverSource: document.getElementById('cover_source_url'),
    metadataSource: document.getElementById('metadata_source_url'), coverWrap: document.getElementById('cover-preview-wrap'),
    coverPreview: document.getElementById('cover-preview'), coverConsent: document.getElementById('cover_permission_confirmed'),
    coverDisplay: document.getElementById('cover_url_display')
  };
  var genreSelect = document.getElementById('genre_ids');
  var genres = Array.prototype.map.call(genreSelect.options, function (o) { return { id: o.value, name: o.textContent }; });
  var defaultButtonText = button.innerHTML;

  function setStatus(message, kind) {
    status.textContent = message;
    status.className = 'lookup-status' + (kind ? ' is-' + kind : '');
  }
  function normalize(text) {
    return String(text || '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
  }
  function jsonFetch(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) }).then(function (response) {
      return response.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (_) { throw new Error('The lookup service returned an unreadable response.'); }
        if (!response.ok || data.success === false || data.ok === false) throw new Error(data.message || 'The lookup could not be completed.');
        return data;
      });
    });
  }
  function scanAuthor(author) {
    return fetch('/admin/author_scan.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' }, body: new URLSearchParams({ csrf_token: token, author: author }) }).then(function (r) {
      return r.json().then(function (data) { if (!r.ok || !data.ok) throw new Error(data.message || 'Author verification is unavailable.'); return data; });
    });
  }
  function titleOf(item) { return item && item.volumeInfo ? String(item.volumeInfo.title || '') : ''; }
  function authorsOf(item) { return item && item.volumeInfo && Array.isArray(item.volumeInfo.authors) ? item.volumeInfo.authors.join('; ') : ''; }
  function coverOf(item) {
    var v = item && item.volumeInfo ? item.volumeInfo : {};
    var url = v.imageLinks && (v.imageLinks.extraLarge || v.imageLinks.large || v.imageLinks.thumbnail || v.imageLinks.smallThumbnail) || '';
    if (url.indexOf('http://books.google.com/') === 0) url = url.replace('http://', 'https://');
    return /^https:\/\/(books\.google\.com|books\.googleusercontent\.com|[^/]+\.books\.googleusercontent\.com|covers\.openlibrary\.org)\//i.test(url) ? url : '';
  }
  function candidateScore(item, title, author) {
    var t = normalize(titleOf(item)), a = normalize(authorsOf(item));
    var wantedTitle = normalize(title), wantedAuthor = normalize(author);
    var titleMatch = !!t && (t === wantedTitle || t.indexOf(wantedTitle) >= 0 || wantedTitle.indexOf(t) >= 0);
    var authorMatch = !!a && (a === wantedAuthor || a.indexOf(wantedAuthor) >= 0 || wantedAuthor.indexOf(a) >= 0);
    return { score: (t === wantedTitle ? 60 : titleMatch ? 35 : 0) + (a === wantedAuthor ? 40 : authorMatch ? 20 : 0), exact: t === wantedTitle && authorMatch };
  }
  function chooseGenres(categories) {
    var normalizedCategories = (categories || []).map(normalize);
    var chosen = genres.filter(function (genre) {
      var n = normalize(genre.name);
      if (!n || n === 'other general') return false;
      return normalizedCategories.some(function (c) { return c === n || c.indexOf(n) >= 0 || n.indexOf(c) >= 0; });
    });
    if (!chosen.length) chosen = genres.filter(function (genre) { return normalize(genre.name) === 'other general'; });
    Array.prototype.forEach.call(genreSelect.options, function (option) { option.selected = chosen.some(function (g) { return g.id === option.value; }); });
  }
  function fill(item, scan, authorBio) {
    var v = item.volumeInfo || {};
    fields.title.value = titleOf(item) || fields.title.value.trim();
    fields.author.value = authorsOf(item) || fields.author.value.trim();
    fields.description.value = String(v.description || '').trim() || 'A full synopsis will be added soon.';
    var ids = Array.isArray(v.industryIdentifiers) ? v.industryIdentifiers : [];
    var isbn = ids.find(function (i) { return /ISBN_13|ISBN_10/.test(i.type || ''); });
    fields.isbn.value = isbn ? String(isbn.identifier || '').replace(/[\s-]/g, '') : '';
    var lang = String(v.language || '').toLowerCase();
    fields.language.value = (/^(fil|tl|tgl)/.test(lang) ? 'Filipino' : /^en/.test(lang) ? 'English' : 'Other Languages');
    fields.publisher.value = String(v.publisher || '').slice(0, 150);
    var printType = String(v.printType || '').toLowerCase();
    fields.format.value = printType.indexOf('ebook') >= 0 ? 'ebook' : 'paperback';
    var amount = item.saleInfo && item.saleInfo.retailPrice && item.saleInfo.retailPrice.currencyCode === 'PHP' ? Number(item.saleInfo.retailPrice.amount) : 500;
    fields.price.value = (amount * 1.25).toFixed(2);
    fields.quantity.value = '10';
    chooseGenres(v.categories || []);
    fields.authorBio.value = authorBio || '';
    fields.metadataSource.value = String(v.infoLink || '');
    fields.cover.value = coverOf(item);
    fields.coverDisplay.value = fields.cover.value;
    fields.coverSource.value = String(v.infoLink || '');
    if (fields.cover.value) {
      fields.coverWrap.hidden = false;
      fields.coverConsent.checked = true;
      fields.coverPreview.innerHTML = '';
      var image = document.createElement('img'); image.src = fields.cover.value; image.alt = 'Selected book cover';
      var note = document.createElement('span'); note.textContent = 'Cover image found. Confirm it is suitable and permitted for this project’s display.';
      fields.coverPreview.append(image, note);
    } else {
      fields.coverWrap.hidden = true; fields.coverConsent.checked = false; fields.coverPreview.textContent = '';
    }
    document.getElementById('scan_claimed').value = scan.can_auto_approve ? '1' : '0';
    document.getElementById('author-scan-results').textContent = (scan.summary.verified === scan.summary.total)
      ? 'Author verified in the Filipino-author catalog.'
      : 'Author needs review. The book will remain hidden until an administrator verifies the author.';
    setStatus('Metadata selected. Review the details, price, and cover before adding.', 'success');
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function renderItems(items, title, author, scan, authorBio, provider) {
    results.textContent = '';
    var ranked = items.map(function (item) { var match = candidateScore(item, title, author); return { item: item, score: match.score, exact: match.exact }; })
      .filter(function (entry) { return entry.score > 0; }).sort(function (a, b) { return b.score - a.score; });
    if (!ranked.length) { setStatus((provider || 'Online catalogs') + ' returned no confident title-and-author match. You can complete the form manually.', 'warning'); return; }
    if (ranked[0].exact && (ranked.length === 1 || ranked[0].score > ranked[1].score)) { fill(ranked[0].item, scan, authorBio); return; }
    setStatus('Choose the matching edition. We did not select a low-confidence result automatically.', 'warning');
    ranked.forEach(function (entry) {
      var item = entry.item, v = item.volumeInfo || {};
      var card = document.createElement('article'); card.className = 'lookup-result';
      var cover = coverOf(item);
      if (cover) { var img = document.createElement('img'); img.src = cover; img.alt = ''; img.loading = 'lazy'; card.appendChild(img); }
      else { var ph = document.createElement('span'); ph.className = 'lookup-result-placeholder'; ph.setAttribute('aria-hidden', 'true'); card.appendChild(ph); }
      var body = document.createElement('div'); var titleNode = document.createElement('strong'); titleNode.textContent = titleOf(item) || 'Untitled result'; body.appendChild(titleNode);
      var by = document.createElement('p'); by.textContent = authorsOf(item) || 'Author not listed'; body.appendChild(by);
      var details = document.createElement('p'); details.textContent = [item.provider || provider, v.publisher, v.publishedDate].filter(Boolean).join(' · '); body.appendChild(details);
      var select = document.createElement('button'); select.type = 'button'; select.className = 'btn btn-secondary'; select.textContent = 'Use This Record';
      select.addEventListener('click', function () { fill(item, scan, authorBio); results.textContent = ''; }); body.appendChild(select); card.appendChild(body); results.appendChild(card);
    });
  }
  button.addEventListener('click', function () {
    var title = fields.title.value.trim();
    var author = fields.author.value.trim();
    results.textContent = ''; document.getElementById('author-scan-results').textContent = '';
    if (!title || !author) { setStatus('Enter both a book title and author to search.', 'error'); return; }
    button.disabled = true; button.textContent = 'Checking author and catalogs…';
    setStatus('Checking author identity and current catalog…');
    scanAuthor(author).then(function (scan) {
      var authorNames = scan.authors.map(function (a) { return a.label + ': ' + a.input; }).join(' · ');
      return jsonFetch('/api/book_bulk_import.php', { action: 'match', csrf_token: token, title: title, author: author, format: 'paperback' }).then(function (catalog) {
        if (catalog.match) {
          var match = catalog.match;
          setStatus('Already in the catalog: “' + match.title + '” by ' + match.author + '. Open the existing edition to update it; this form will not create another copy.', 'warning');
          var link = document.createElement('a'); link.className = 'btn btn-secondary'; link.textContent = 'View Existing Book';
          if (match.edition_id) link.href = '/admin/products.php?edit=' + encodeURIComponent(match.edition_id);
          else link.href = '/admin/products.php#catalog-title';
          results.appendChild(link);
          return null;
        }
        setStatus(authorNames + '. Searching Google Books and Open Library…', scan.summary.needs_review ? 'warning' : '');
        return jsonFetch('/api/book_metadata.php', { csrf_token: token, title: title, author: author, query: 'intitle:' + title + '+inauthor:' + author, provider: 'google_books' }).then(function (metadata) {
          if (!metadata.items || !metadata.items.length) { setStatus('No usable metadata result was found. Complete the details manually. ' + (metadata.fallback_notice || ''), 'warning'); return; }
          renderItems(metadata.items, title, author, scan, catalog.author_description || '', metadata.provider);
        });
      });
    }).catch(function (error) { setStatus(error.message || 'The lookup could not be completed. Complete the form manually.', 'error'); })
      .finally(function () { button.disabled = false; button.innerHTML = defaultButtonText; });
  });

  var filter = document.getElementById('catalog-filter');
  var formatFilter = document.getElementById('catalog-format-filter');
  var reviewFilter = document.getElementById('catalog-review-filter');
  var stockFilter = document.getElementById('catalog-stock-filter');
  function applyCatalogFilters() {
    var query = normalize(filter ? filter.value : ''), format = formatFilter ? formatFilter.value : 'all';
    var review = reviewFilter ? reviewFilter.value : 'all', stock = stockFilter ? stockFilter.value : 'all', shown = 0;
    document.querySelectorAll('.catalog-card').forEach(function (card) {
      var visible = card.getAttribute('data-search').indexOf(query) >= 0
        && (format === 'all' || card.dataset.format === format)
        && (review === 'all' || card.dataset.review === review)
        && (stock === 'all' || (stock === 'risk' && card.dataset.risk === '1') || (stock === 'out' && card.dataset.out === '1'));
      card.hidden = !visible; if (visible) shown++;
    });
    var empty = document.getElementById('catalog-empty'); if (empty) empty.hidden = shown !== 0;
  }
  [filter, formatFilter, reviewFilter, stockFilter].forEach(function (control) { if (control) control.addEventListener('input', applyCatalogFilters); });
})();
