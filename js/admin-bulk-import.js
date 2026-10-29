(function () {
  'use strict';
  var state = window.bulkImportState;
  if (!state || !Array.isArray(state.rows)) return;
  var container = document.getElementById('bulk-rows');
  var message = document.getElementById('bulk-message');
  var progress = document.getElementById('bulk-progress');
  var duplicateWarning = document.getElementById('bulk-duplicate-warning');
  var categoryNote = document.getElementById('bulk-category-note');
  var importButton = document.getElementById('import-ready');
  var filters = document.getElementById('bulk-filters');
  var activeFilter = 'all';
  var lookupTimers = Object.create(null);
  var imported = state.status === 'imported';
  var formats = [['', 'Select format'], ['paperback', 'Paperback / softbound'], ['hardbound', 'Hardcover / hardbound'], ['ebook', 'Ebook']];
  var languageNames = { en: 'English', eng: 'English', fil: 'Filipino', tl: 'Tagalog', tgl: 'Tagalog', ceb: 'Cebuano', ilo: 'Ilocano', es: 'Spanish', spa: 'Spanish', zh: 'Chinese', ja: 'Japanese' };

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }
  function norm(value) { return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' '); }
  function authorNorm(value) { return norm(value).split(';')[0]; }
  function inputField(rowIndex, key, label, opts) {
    opts = opts || {};
    var wrap = el('label', 'bulk-field' + (opts.wide ? ' bulk-field-wide' : '') + (opts.required ? ' is-required' : ''));
    var caption = el('span', 'bulk-field-label', label);
    if (opts.required) caption.appendChild(el('span', 'bulk-asterisk', '*'));
    wrap.appendChild(caption);
    var input;
    if (opts.type === 'textarea') {
      input = el('textarea', 'form-input'); input.rows = opts.rows || 3;
    } else if (opts.type === 'select') {
      input = el('select', 'form-input');
      (opts.options || []).forEach(function (pair) {
        var option = el('option', '', pair[1]); option.value = pair[0]; input.appendChild(option);
      });
    } else {
      input = el('input', 'form-input'); input.type = opts.type || 'text';
      if (opts.step) input.step = opts.step;
      if (opts.min !== undefined) input.min = opts.min;
      if (opts.max !== undefined) input.max = opts.max;
    }
    input.dataset.row = rowIndex; input.dataset.field = key;
    if (opts.required) input.required = true;
    input.value = rowValue(rowIndex, key, opts.default || '');
    if (opts.placeholder) input.placeholder = opts.placeholder;
    if (opts.disabled || imported) input.disabled = true;
    wrap.appendChild(input);
    return { wrap: wrap, input: input };
  }
  function rowValue(index, key, fallback) { var value = state.rows[index][key]; return value === undefined || value === null ? fallback : String(value); }
  function selectedGenres(index) { return Array.isArray(state.rows[index].genre_ids) ? state.rows[index].genre_ids.map(String) : []; }
  function makeRow(row, index) {
    var details = el('details', 'bulk-row');
    details.dataset.rowState = index;
    details.open = false;
    var head = el('summary', 'bulk-row-summary');
    head.appendChild(el('span', 'bulk-row-number', String(index + 1).padStart(2, '0')));
    var titleSummary = el('span', 'bulk-row-title');
    titleSummary.appendChild(el('strong', '', row.title || 'Untitled row'));
    titleSummary.appendChild(el('small', 'bulk-row-byline', row.author || 'Author unknown'));
    head.appendChild(titleSummary);
    details.appendChild(head);
    var body = el('div', 'bulk-row-body');
    var grid = el('div', 'bulk-field-grid');
    var fTitle = inputField(index, 'title', 'Book title', { required: true });
    var fAuthor = inputField(index, 'author', 'Author(s)', { required: true, placeholder: 'Separate contributors with semicolons' });
    var authorCheck = el('small', 'bulk-author-check', 'Filipino identity was checked during batch preparation.'); authorCheck.dataset.authorCheck = index; authorCheck.setAttribute('aria-live', 'polite');
    fAuthor.wrap.appendChild(authorCheck);
    var fAuthorBio = inputField(index, 'author_description', 'Primary author description (optional)', { type: 'textarea', rows: 3, wide: true });
    var fIsbn = inputField(index, 'isbn', 'ISBN (optional)');
    var fPublisher = inputField(index, 'publisher', 'Publisher');
    var fFormat = inputField(index, 'format', 'Edition / format', { type: 'select', options: formats, required: true });
    fFormat.wrap.appendChild(el('small', 'bulk-hint', 'Each row adds stock for one format. Choose the format you intend to stock; metadata can list editions but cannot decide which ones you ordered.'));
    var fLanguage = inputField(index, 'language', 'Language', { required: true, placeholder: 'Filipino, English…' });
    var fDescription = inputField(index, 'description', 'Synopsis / description', { type: 'textarea', rows: 4, required: true, wide: true });
    var fCost = inputField(index, 'unit_cost', 'Unit cost (optional)', { type: 'number', min: '0', step: '0.01' });
    var fMarkup = inputField(index, 'markup_percent', 'Markup (%)', { type: 'number', min: '0', max: '500', step: '0.01', default: '25' });
    fMarkup.wrap.appendChild(el('small', 'bulk-hint', 'Applied to unit cost, or to a Google Books reference price when it is listed in PHP.'));
    var fPrice = inputField(index, 'price', 'Website selling price (₱)', { type: 'number', min: '0.01', step: '0.01', required: true });
    fPrice.input.readOnly = !!String(row.unit_cost || '').trim();
    fPrice.wrap.appendChild(el('small', 'bulk-hint bulk-price-source', row.price_source || 'Uses catalog price, cost + markup, or a PHP online reference + markup. Without one, enter a price.'));
    var fQuantity = inputField(index, 'quantity', 'Copies to add to stock', { type: 'number', min: '1', max: '9999999', required: true, default: '1' });
    var fCover = inputField(index, 'cover_url', 'Book cover image', { wide: true, required: true, placeholder: 'Filled from selected metadata or existing catalog record' });
    var fEvidence = inputField(index, 'filipino_evidence_url', 'Filipino identity evidence URL', { wide: true, placeholder: 'Public author statement or profile' });
    fEvidence.wrap.appendChild(el('small', 'bulk-hint', 'Unknown authors remain excluded until staff verify them in author review.'));
    [fTitle, fAuthor, fAuthorBio, fIsbn, fPublisher, fFormat, fLanguage, fDescription, fCost, fMarkup, fPrice, fQuantity, fCover, fEvidence].forEach(function (field) { grid.appendChild(field.wrap); });
    var localCoverWrap = el('label', 'bulk-field bulk-field-wide'); localCoverWrap.appendChild(el('span', '', 'Or upload an authorized cover file'));
    var localCover = el('input', 'form-input'); localCover.type = 'file'; localCover.accept = 'image/jpeg,image/png,image/webp'; localCover.dataset.row = index; localCover.dataset.field = 'cover_file'; localCover.disabled = imported;
    localCoverWrap.appendChild(localCover); localCoverWrap.appendChild(el('small', 'bulk-hint', 'JPEG, PNG, or WebP up to 5 MB. Use a file you created or are permitted to display.'));
    grid.appendChild(localCoverWrap);

    var genreWrap = el('label', 'bulk-field bulk-field-wide');
    var genreLabel = el('span', 'bulk-field-label', 'Store genres'); genreLabel.appendChild(el('span', 'bulk-asterisk', '*')); genreWrap.className += ' is-required'; genreWrap.appendChild(genreLabel);
    var genreSelect = el('select', 'form-input bulk-genre-select'); genreSelect.multiple = true; genreSelect.size = 4; genreSelect.dataset.row = index; genreSelect.dataset.field = 'genre_ids'; genreSelect.required = true; genreSelect.disabled = imported;
    state.genres.forEach(function (genre) {
      var option = el('option', '', genre.name); option.value = String(genre.id); option.selected = selectedGenres(index).indexOf(String(genre.id)) !== -1; genreSelect.appendChild(option);
    });
    genreWrap.appendChild(genreSelect); genreWrap.appendChild(el('small', 'bulk-hint', 'Hold Ctrl or Command to select more than one. Metadata categories suggest matches but do not replace your store categories.'));
    grid.appendChild(genreWrap);

    var coverConsent = el('label', 'bulk-consent bulk-field-wide');
    var consentBox = el('input'); consentBox.type = 'checkbox'; consentBox.dataset.row = index; consentBox.dataset.field = 'cover_permission_confirmed'; consentBox.checked = row.cover_permission_confirmed === undefined ? true : !!row.cover_permission_confirmed; consentBox.disabled = imported;
    coverConsent.appendChild(consentBox); coverConsent.appendChild(el('span', '', 'I have confirmed this cover is appropriate and permitted for this project’s display.'));
    coverConsent.appendChild(el('small', 'bulk-hint bulk-field-wide', 'Prechecked for review speed. Inspect the image and uncheck this if it is wrong or not permitted.'));
    grid.appendChild(coverConsent);
    body.appendChild(grid);

    var preview = el('div', 'bulk-cover-preview'); preview.dataset.preview = index;
    if (row.cover_url) {
      var image = el('img'); image.src = row.cover_url; image.alt = 'Suggested cover for ' + (row.title || 'book'); image.loading = 'lazy'; preview.appendChild(image);
    }
    var previewHelp = el('small', 'bulk-hint', 'Cover source: ' + (row.cover_source_url || 'not selected yet'));
    preview.appendChild(previewHelp); body.appendChild(preview);
    var source = el('p', 'bulk-source'); source.dataset.source = index;
    source.textContent = row.metadata_source_url ? 'Metadata source: ' + row.metadata_source_url : 'No metadata source selected.';
    body.appendChild(source);

    var actions = el('div', 'bulk-row-actions');
    var skipLabel = el('label', 'bulk-skip'); var skip = el('input'); skip.type = 'checkbox'; skip.dataset.row = index; skip.dataset.field = 'skip'; skip.checked = !!row.skip; skip.disabled = imported;
    skipLabel.appendChild(skip); skipLabel.appendChild(el('span', '', 'Skip this row'));
    actions.appendChild(skipLabel); body.appendChild(actions);
    var catalogCandidates = el('div', 'bulk-candidates'); catalogCandidates.dataset.catalogCandidates = index; body.appendChild(catalogCandidates);
    var onlineCandidates = el('div', 'bulk-candidates'); onlineCandidates.dataset.onlineCandidates = index; body.appendChild(onlineCandidates);
    var rowNotice = el('div', 'bulk-row-notice'); rowNotice.dataset.notice = index; body.appendChild(rowNotice);
    details.appendChild(body); container.appendChild(details);
  }

  function allInputs(rowIndex) { return Array.prototype.slice.call(container.querySelectorAll('[data-row="' + rowIndex + '"]')); }
  function readRows() {
    state.rows.forEach(function (row, index) {
      allInputs(index).forEach(function (input) {
        var key = input.dataset.field;
        if (!key) return;
        if (input.type === 'file') { if (key === 'cover_file') row.cover_file_present = !!(input.files && input.files.length); return; }
        if (key === 'genre_ids') row.genre_ids = Array.prototype.slice.call(input.selectedOptions).map(function (o) { return Number(o.value); });
        else if (input.type === 'checkbox') row[key] = input.checked;
        else row[key] = input.value.trim();
      });
    });
  }
  function updateCover(index) {
    var row = state.rows[index], preview = container.querySelector('[data-preview="' + index + '"]');
    if (!preview) return;
    var img = preview.querySelector('img');
    if (row.cover_url) {
      if (!img) { img = el('img'); img.loading = 'lazy'; preview.prepend(img); }
      img.src = row.cover_url; img.alt = 'Suggested cover for ' + (row.title || 'book');
    } else if (img) img.remove();
    var note = preview.querySelector('small'); if (note) note.textContent = 'Cover source: ' + (row.cover_source_url || 'not selected yet');
  }
  function updateRowHeading(index, status, text) {
    var rowCard = container.querySelector('[data-row-state="' + index + '"]');
    if (rowCard) rowCard.dataset.status = status;
    var title = container.querySelector('[data-row="' + index + '"][data-field="title"]');
    var author = container.querySelector('[data-row="' + index + '"][data-field="author"]');
    var byline = container.querySelectorAll('.bulk-row-byline')[index];
    var heading = container.querySelectorAll('.bulk-row-title strong')[index];
    if (heading && title) heading.textContent = title.value || 'Untitled row';
    if (byline && author) byline.textContent = author.value || 'Author unknown';
  }
  function isExisting(row) { return !!(row.existing_catalog_selected || row.local_match || row.lookup_status === 'Existing catalog book' || row.lookup_status === 'Existing catalog record selected'); }
  function authorVerified(row) { return Array.isArray(row.filipino_author_checks) && row.filipino_author_checks.length > 0 && row.filipino_author_checks.every(function (check) { return check.status === 'verified'; }); }
  function isUnknownAuthor(row) { return !isExisting(row) && !row.skip && !authorVerified(row); }
  function setRowEditable(index, editable) {
    var card = container.querySelector('[data-row-state="' + index + '"]');
    if (!card) return;
    card.classList.toggle('is-readonly', !editable);
    card.querySelectorAll('[data-row="' + index + '"]').forEach(function (field) { field.disabled = !editable || imported; });
  }
  function rowReady(row, index) {
    if (row.skip || isExisting(row) || !authorVerified(row)) return false;
    var chosen = Array.isArray(row.genre_ids) ? row.genre_ids.length > 0 : false;
    var required = ['title', 'author', 'description', 'language', 'format', 'price', 'quantity'];
    return required.filter(function (key) { return key !== 'cover_url'; }).every(function (key) { return String(row[key] || '').trim() !== ''; })
      && (!!String(row.cover_url || '').trim() || !!row.cover_file_present || !!row.reuse_catalog_cover) && chosen && (!!row.cover_permission_confirmed || !!row.reuse_catalog_cover);
  }
  function refreshSummary() {
    readRows();
    var ready = 0, skipped = 0, needs = 0, existing = 0, unknown = 0, seen = {};
    state.rows.forEach(function (row, index) {
      var card = container.querySelector('[data-row-state="' + index + '"]');
      if (card) {
        card.classList.toggle('is-existing-book', isExisting(row));
        card.classList.toggle('is-unknown-author', isUnknownAuthor(row));
        card.classList.toggle('is-ready', !row.skip && rowReady(row, index));
      }
      if (row.skip) skipped++;
      else if (isExisting(row)) existing++;
      else if (isUnknownAuthor(row)) unknown++;
      else if (rowReady(row, index)) ready++;
      else needs++;
      markMissingFields(row, index);
      var isbn = String(row.isbn || '').replace(/[\s-]+/g, '').toUpperCase();
      if (isbn) seen[isbn] = (seen[isbn] || 0) + 1;
    });
    var duplicateCount = Object.keys(seen).filter(function (isbn) { return seen[isbn] > 1; }).length;
    renderFilters(ready, needs, skipped, existing, unknown);
    if (duplicateWarning) { duplicateWarning.hidden = !duplicateCount; duplicateWarning.textContent = duplicateCount ? duplicateCount + ' ISBN duplicate group(s): combine rows before import.' : ''; }
    if (importButton) importButton.disabled = imported || ready === 0 || duplicateCount > 0;
    return { ready: ready, skipped: skipped, duplicateCount: duplicateCount, existing: existing, unknown: unknown };
  }
  function renderFilters(ready, needs, skipped, existing, unknown) {
    if (!filters) return;
    var counts = { all: state.rows.length, ready: ready, needs: needs, skipped: skipped, existing: existing, unknown: unknown };
    var labels = [['all', 'All'], ['ready', 'Ready for Import'], ['needs', 'Needs Details'], ['skipped', 'Skipped'], ['existing', 'Existing Book'], ['unknown', 'Unknown Author']];
    filters.textContent = '';
    labels.forEach(function (entry) {
      var button = el('button', 'bulk-filter bulk-filter-' + entry[0] + (activeFilter === entry[0] ? ' is-active' : ''), entry[1] + ' · ' + counts[entry[0]]);
      button.type = 'button'; button.setAttribute('aria-pressed', activeFilter === entry[0] ? 'true' : 'false');
      button.addEventListener('click', function () { activeFilter = entry[0]; applyFilter(); renderFilters(ready, needs, skipped, existing, unknown); });
      filters.appendChild(button);
    });
    applyFilter();
  }
  function applyFilter() {
    state.rows.forEach(function (row, index) {
      var card = container.children[index];
      if (!card) return;
      var isReady = !row.skip && rowReady(row, index);
      var show = activeFilter === 'all' || (activeFilter === 'ready' && isReady) || (activeFilter === 'needs' && !row.skip && !isExisting(row) && !isUnknownAuthor(row) && !isReady) || (activeFilter === 'skipped' && !!row.skip) || (activeFilter === 'existing' && isExisting(row)) || (activeFilter === 'unknown' && isUnknownAuthor(row));
      card.hidden = !show;
    });
    if (categoryNote) {
      var copy = {
        all: 'Only new books with verified Filipino authors and complete required details can be imported.',
        ready: 'These new books have a verified Filipino author and all required listing details.',
        needs: 'Complete the highlighted required fields for these books before importing.',
        skipped: 'Skipped rows are excluded from this batch.',
        existing: 'These books already exist in the shop catalog. They are read-only here and will not be added or changed by this import.',
        unknown: 'These authors are not verified in the Filipino-author database. Their books stay out of import until staff verify the author.'
      };
      categoryNote.textContent = copy[activeFilter] || copy.all;
      categoryNote.dataset.filter = activeFilter;
    }
  }
  function markMissingFields(row, index) {
    var fields = ['title', 'author', 'description', 'language', 'format', 'price', 'quantity'];
    fields.forEach(function (key) {
      var field = container.querySelector('[data-row="' + index + '"][data-field="' + key + '"]');
      if (!field) return;
      var missing = !String(row[key] || '').trim() || (key === 'author' && !authorVerified(row));
      var wrap = field.closest('.bulk-field');
      if (wrap) wrap.classList.toggle('is-missing', missing && !isExisting(row));
    });
    var genre = container.querySelector('[data-row="' + index + '"][data-field="genre_ids"]');
    if (genre && genre.closest('.bulk-field')) genre.closest('.bulk-field').classList.toggle('is-missing', (!row.genre_ids || !row.genre_ids.length) && !isExisting(row));
    var coverField = container.querySelector('[data-row="' + index + '"][data-field="cover_url"]');
    if (coverField && coverField.closest('.bulk-field')) coverField.closest('.bulk-field').classList.toggle('is-missing', !row.cover_url && !row.cover_file_present && !row.reuse_catalog_cover && !isExisting(row));
    var consent = container.querySelector('[data-row="' + index + '"][data-field="cover_permission_confirmed"]');
    if (consent) consent.closest('.bulk-consent').classList.toggle('is-missing', !consent.checked && !isExisting(row));
  }
  function renderPreparedAuthorCheck(index) {
    var note = container.querySelector('[data-author-check="' + index + '"]');
    if (!note) return;
    var checks = state.rows[index].filipino_author_checks || [];
    if (!checks.length) { note.className = 'bulk-author-check is-review'; note.textContent = 'No author identity result was saved during preparation. Evidence and staff review may be needed.'; return; }
    var allVerified = checks.every(function (check) { return check.status === 'verified'; });
    note.className = 'bulk-author-check ' + (allVerified ? 'is-verified' : 'is-review');
    note.textContent = checks.map(function (check) {
      if (check.status === 'verified') return check.name + ': matched verified Filipino author ' + (check.matched_name || '') + '.';
      if (check.status === 'possible_match') return check.name + ': possible name variation of ' + (check.matched_name || 'a verified author') + '; review identity.';
      if (check.status === 'ambiguous') return check.name + ': multiple local author matches; resolve identity.';
      if (check.status === 'rejected') return check.name + ': identity was rejected in author review. Admin/staff review is required.';
      return check.name + ': no verified local match. This is unknown, not proof the author is non-Filipino; verify identity before import.';
    }).join(' ');
  }
  function setNotice(index, text, kind) {
    var box = container.querySelector('[data-notice="' + index + '"]');
    if (!box) return;
    box.className = 'bulk-row-notice' + (kind ? ' is-' + kind : ''); box.textContent = text || '';
  }

  function isbnFrom(item) {
    var list = item.volumeInfo && item.volumeInfo.industryIdentifiers || [];
    var preferred = list.find(function (it) { return it.type === 'ISBN_13'; }) || list.find(function (it) { return it.type === 'ISBN_10'; });
    return preferred ? preferred.identifier : '';
  }
  function coverFrom(item) {
    var links = item.volumeInfo && item.volumeInfo.imageLinks || {};
    var raw = links.extraLarge || links.large || links.medium || links.thumbnail || links.smallThumbnail || '';
    if (!raw) return '';
    try { var url = new URL(raw); if (!(url.hostname === 'books.google.com' || url.hostname === 'books.googleusercontent.com' || url.hostname.endsWith('.books.googleusercontent.com') || url.hostname === 'covers.openlibrary.org')) return ''; if (url.protocol === 'http:') url.protocol = 'https:'; if (url.protocol !== 'https:') return ''; return url.href; }
    catch (_) { return ''; }
  }
  function googleBooksPreviewLink(item) {
    var raw = item.volumeInfo && (item.volumeInfo.previewLink || item.volumeInfo.infoLink) || '';
    try {
      var url = new URL(raw);
      if ((url.protocol === 'https:' || url.protocol === 'http:') && (url.hostname === 'books.google.com' || url.hostname === 'play.google.com' || url.hostname === 'openlibrary.org')) { url.protocol = 'https:'; return url.href; }
    } catch (_) {}
    if (item.provider === 'Open Library') return 'https://openlibrary.org/search?' + new URLSearchParams({ q: [(item.volumeInfo && item.volumeInfo.title) || '', ((item.volumeInfo && item.volumeInfo.authors) || []).join(' ')].filter(Boolean).join(' ') }).toString();
    if (item.id) return 'https://books.google.com/books?id=' + encodeURIComponent(item.id);
    var info = item.volumeInfo || {};
    return 'https://books.google.com/books?' + new URLSearchParams({ q: [info.title || '', (info.authors || []).join(' ')].filter(Boolean).join(' ') }).toString();
  }
  function delay(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
  async function googleBooksFetch(query, row, provider) {
    for (var attempt = 0; attempt < 3; attempt++) {
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      try {
        var response = await fetch('/api/book_metadata.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ query: query, title: row.title, author: row.author, isbn: row.isbn, provider: provider || 'google_books', csrf_token: state.csrf }), signal: controller.signal
        });
        var responseText = await response.text();
        var data;
        try { data = JSON.parse(responseText); }
        catch (_) { throw new Error('Metadata service returned a non-JSON page (HTTP ' + response.status + '). Sign in again or check the PHP server terminal.'); }
        if (response.status === 429 && attempt < 2) {
          clearTimeout(timeout);
          await delay(1000 * Math.pow(2, attempt));
          continue;
        }
        if (!response.ok || !data.success) throw new Error(data.message || 'Google Books lookup failed (HTTP ' + response.status + ').');
        return data;
      } catch (error) {
        if (error.name === 'AbortError') throw new Error('Google Books took too long to respond. Try again or fill this row manually.');
        throw error;
      } finally {
        clearTimeout(timeout);
      }
    }
    throw new Error('Google Books lookup could not be completed.');
  }
  function scoreCandidate(row, item) {
    var info = item.volumeInfo || {}, score = 0;
    var wantedIsbn = String(row.isbn || '').replace(/[\s-]+/g, '').toUpperCase();
    var foundIsbn = isbnFrom(item).replace(/[\s-]+/g, '').toUpperCase();
    var isbnMatch = !!wantedIsbn && wantedIsbn === foundIsbn;
    var exactTitle = !!norm(row.title) && norm(info.title) === norm(row.title);
    var titleConflict = !!norm(row.title) && !!norm(info.title) && !exactTitle;
    var foundAuthors = (info.authors || []).map(norm), wantedAuthor = authorNorm(row.author);
    var exactAuthor = !!wantedAuthor && foundAuthors.indexOf(wantedAuthor) !== -1;
    var authorPartial = !!wantedAuthor && !exactAuthor && foundAuthors.some(function (name) { return name.includes(wantedAuthor) || wantedAuthor.includes(name); });
    if (isbnMatch) score += 100;
    if (exactTitle) score += 55;
    if (exactAuthor) score += 35;
    else if (authorPartial) score += 20;
    var wantedPublisher = norm(row.publisher), foundPublisher = norm(info.publisher);
    var publisherMatch = !!wantedPublisher && !!foundPublisher && wantedPublisher === foundPublisher;
    if (publisherMatch) score += 10;
    if (titleConflict) score -= 100;
    return { score: score, exactTitle: exactTitle, exactAuthor: exactAuthor, isbnMatch: isbnMatch, titleConflict: titleConflict, publisherMatch: publisherMatch, authorPartial: authorPartial };
  }
  function applyCandidate(index, item, confidence) {
    var row = state.rows[index], info = item.volumeInfo || {};
    var formatWasProvided = !!String(row.format || '').trim();
    if (!formatWasProvided) {
      if (item.saleInfo && item.saleInfo.isEbook === true) row.format = 'ebook';
      else if (String(info.printType || '').toUpperCase() === 'BOOK') row.format = 'paperback';
    }
    row.title = info.title || row.title;
    if (Array.isArray(info.authors) && info.authors.length) row.author = info.authors.join('; ');
    row.isbn = isbnFrom(item) || row.isbn || '';
    row.publisher = info.publisher || row.publisher || '';
    row.description = info.description || row.description || '';
    row.language = languageNames[String(info.language || '').toLowerCase()] || info.language || row.language || '';
    var retail = item.saleInfo && item.saleInfo.listPrice;
    if (!String(row.unit_cost || '').trim() && !String(row.price || '').trim() && retail && String(retail.currencyCode || '').toUpperCase() === 'PHP' && Number.isFinite(Number(retail.amount)) && Number(retail.amount) > 0) {
      row.reference_price = Number(retail.amount);
      var markup = Number(row.markup_percent || 25);
      row.price = (Number(retail.amount) * (1 + markup / 100)).toFixed(2);
      row.price_source = 'Google Books PHP retail reference + ' + markup + '% markup';
    }
    row.cover_url = coverFrom(item) || row.cover_url || '';
    row.cover_source_url = item.volumeInfo && item.volumeInfo.infoLink || item.selfLink || '';
    row.metadata_source_url = row.cover_source_url;
    row.metadata_provider = item.provider || 'Google Books';
    row.lookup_status = confidence >= 90 ? 'High-confidence match' : 'Staff-confirmed match';
    var categories = (info.categories || []).map(norm);
    var guesses = state.genres.filter(function (genre) {
      var g = norm(genre.name);
      return categories.some(function (category) { return category === g || category.includes(g) || g.includes(category); });
    }).map(function (g) { return g.id; });
    if (guesses.length && !row.genre_ids.length) row.genre_ids = guesses;
    var source = container.querySelector('[data-source="' + index + '"]');
    if (source) source.textContent = 'Metadata source (' + row.metadata_provider + '): ' + row.metadata_source_url;
    renderRowFields(index);
    var priceSource = container.querySelector('[data-row="' + index + '"] .bulk-price-source');
    if (priceSource) priceSource.textContent = row.price_source || (row.reference_price ? 'Google Books reference price + configured markup.' : 'Uses existing catalog price, cost + markup, or a PHP online reference + markup. Without one, enter a price.');
    updateRowHeading(index, confidence >= 90 ? 'matched' : 'suggestion', row.lookup_status);
    updateCover(index); refreshSummary(); checkCatalog(index); scheduleAuthorCheck(index);
    if (!formatWasProvided && row.format) setNotice(index, 'Format preselected as ' + row.format + (row.format === 'paperback' ? ' (default for print books; verify the binding before import).' : ' from the metadata ebook signal.'), 'info');
  }
  function showCandidates(index, items) {
    var box = container.querySelector('[data-online-candidates="' + index + '"]');
    box.textContent = '';
    if (!items.length) { setNotice(index, 'No metadata match found. Fill the required fields manually or skip this row.', 'warning'); return; }
    box.appendChild(el('h4', '', 'Choose the exact title and edition'));
    items.slice(0, 5).forEach(function (item) {
      var info = item.volumeInfo || {}, score = scoreCandidate(state.rows[index], item);
      var card = el('article', 'bulk-candidate');
      var cover = coverFrom(item);
      if (cover) { var img = el('img'); img.src = cover; img.alt = ''; img.loading = 'lazy'; card.appendChild(img); }
      var details = el('div', 'bulk-candidate-info');
      details.appendChild(el('strong', '', info.title || 'Untitled result'));
      details.appendChild(el('small', 'bulk-confidence', item.provider || 'Google Books'));
      details.appendChild(el('small', '', (info.authors || []).join(', ') || 'Author not listed'));
      details.appendChild(el('small', '', [info.publisher, info.publishedDate, isbnFrom(item) ? 'ISBN ' + isbnFrom(item) : 'No ISBN'].filter(Boolean).join(' · ')));
      details.appendChild(el('small', 'bulk-confidence', score.titleConflict ? 'Title conflict · do not auto-select' : score.score >= 90 ? 'High-confidence candidate · verify edition' : 'Possible match · compare details'));
      details.appendChild(el('small', '', 'Match signals: ' + [score.isbnMatch ? 'ISBN exact' : '', score.exactTitle ? 'title exact' : '', score.exactAuthor ? 'author exact' : score.authorPartial ? 'author similar' : '', score.publisherMatch ? 'publisher exact' : ''].filter(Boolean).join(' · ') || 'no exact identifier/title/author signal'));
      var editionDetails = [info.printType ? info.printType.toLowerCase() : '', info.pageCount ? info.pageCount + ' pages' : '', (info.industryIdentifiers || []).filter(function (id) { return id.identifier; }).map(function (id) { return id.type.replace('_', '-') + ' ' + id.identifier; }).join(' · ')].filter(Boolean).join(' · ');
      if (editionDetails) details.appendChild(el('small', '', editionDetails));
      if (info.description) details.appendChild(el('p', 'bulk-candidate-description', info.description));
      var previewUrl = googleBooksPreviewLink(item);
      if (previewUrl) {
        var previewLink = el('a', 'btn btn-link btn-sm', 'Preview book details'); previewLink.href = previewUrl; previewLink.target = '_blank'; previewLink.rel = 'noopener noreferrer'; details.appendChild(previewLink);
      } else details.appendChild(el('small', '', 'Google Books search link is unavailable for this result.'));
      var button = el('button', 'btn btn-secondary btn-sm', 'Use this record'); button.type = 'button'; button.addEventListener('click', function () { applyCandidate(index, item, score.score); box.textContent = ''; });
      details.appendChild(button); card.appendChild(details); box.appendChild(card);
    });
    setNotice(index, 'Select the exact book record. The system will not publish a low-confidence result automatically.', 'info');
  }
  async function lookupRow(index) {
    readRows(); var row = state.rows[index];
    if (row.skip || isExisting(row)) return;
    if (!row.title || !row.author) { setNotice(index, 'Enter both the book title and author before searching metadata.', 'warning'); return; }
    updateRowHeading(index, 'searching', 'Searching online…');
    setNotice(index, 'Searching Google Books for metadata suggestions…', 'info');
    var query = row.isbn ? 'isbn:' + row.isbn + ' intitle:' + row.title + (row.author ? ' inauthor:' + row.author.split(';')[0].trim() : '') : 'intitle:' + row.title + (row.author ? '+inauthor:' + row.author.split(';')[0].trim() : '');
    try {
      var data, items = [];
      try {
        data = await googleBooksFetch(query, row);
        items = Array.isArray(data.items) ? data.items : [];
      } catch (googleError) {
        // Google quota/network errors should trigger the configured Open Library fallback.
        try {
          data = await googleBooksFetch(query, row, 'open_library');
          items = Array.isArray(data.items) ? data.items : [];
        } catch (_) { throw googleError; }
      }
      items.forEach(function (item) { item.provider = item.provider || data.provider || 'Google Books'; });
      if (!items.length && row.isbn) {
        data = await googleBooksFetch('intitle:' + row.title + (row.author ? '+inauthor:' + row.author.split(';')[0].trim() : ''), row);
        items = Array.isArray(data.items) ? data.items : [];
        items.forEach(function (item) { item.provider = item.provider || data.provider || 'Google Books'; });
      }
      if (data.provider !== 'Open Library' && (!items.length || !items.some(function (item) { var score = scoreCandidate(row, item); return !score.titleConflict && (score.isbnMatch || (score.exactTitle && score.exactAuthor && score.score >= 90)); }))) {
        try {
          var fallback = await googleBooksFetch(query, row, 'open_library');
          var fallbackItems = Array.isArray(fallback.items) ? fallback.items : [];
          fallbackItems.forEach(function (item) { item.provider = 'Open Library'; });
          items = items.concat(fallbackItems);
        } catch (_) { /* Keep Google Books candidates visible if the fallback is unavailable. */ }
      }
      if (!items.length) { row.lookup_status = 'No result'; updateRowHeading(index, 'no-result', 'No result'); setNotice(index, data.fallback_notice || 'No matching records were found in Google Books or Open Library. Complete this row manually.', 'warning'); showCandidates(index, []); refreshSummary(); return; }
      var seenCandidates = Object.create(null);
      items = items.filter(function (item) {
        var key = String(isbnFrom(item) || norm((item.volumeInfo || {}).title) + '|' + authorNorm(((item.volumeInfo || {}).authors || []).join('; '))).toUpperCase();
        if (seenCandidates[key]) return false; seenCandidates[key] = true; return true;
      });
      items.sort(function (a, b) { return scoreCandidate(row, b).score - scoreCandidate(row, a).score; });
      var best = scoreCandidate(row, items[0]);
      if (!best.titleConflict && (best.isbnMatch || (best.exactTitle && best.exactAuthor && best.score >= 90))) applyCandidate(index, items[0], best.score);
      else { row.lookup_status = 'Needs match'; updateRowHeading(index, 'needs-review', 'Select a match'); showCandidates(index, items); refreshSummary(); }
    } catch (error) {
      row.lookup_status = 'Lookup unavailable'; updateRowHeading(index, 'lookup-error', 'Lookup unavailable');
      setNotice(index, 'Online lookup could not complete. ' + error.message, 'warning'); refreshSummary();
    }
  }
  function renderRowFields(index) {
    // Candidate selection can change authors, format, and metadata; keep current edits in state and redraw simple controls.
    var row = state.rows[index];
    var fields = container.querySelectorAll('[data-row="' + index + '"][data-field]');
    fields.forEach(function (field) {
      var key = field.dataset.field;
      if (key === 'genre_ids') {
        Array.prototype.slice.call(field.options).forEach(function (option) { option.selected = (row.genre_ids || []).map(String).indexOf(option.value) !== -1; });
      } else if (field.type === 'checkbox') field.checked = !!row[key];
      else if (document.activeElement !== field && row[key] !== undefined) field.value = String(row[key]);
    });
  }
  var catalogCheckTimers = Object.create(null);
  var catalogCheckVersions = Object.create(null);
  var authorCheckPending = Object.create(null);
  var authorCheckTimer = null;
  function scheduleAuthorCheck(index) {
    readRows();
    var names = String(state.rows[index].author || '').split(';').map(function (name) { return name.trim(); }).filter(Boolean);
    var note = container.querySelector('[data-author-check="' + index + '"]');
    if (!names.length) { if (note) { note.className = 'bulk-author-check'; note.textContent = 'Add an author to check against the verified Filipino-author catalog.'; } return; }
    state.rows[index].filipino_author_checks = [];
    renderPreparedAuthorCheck(index);
    refreshSummary();
    authorCheckPending[index] = names;
    if (note) { note.className = 'bulk-author-check is-checking'; note.textContent = 'Checking author against the verified Filipino-author catalog…'; }
    if (authorCheckTimer) clearTimeout(authorCheckTimer);
    authorCheckTimer = setTimeout(sendAuthorChecks, 250);
  }
  async function sendAuthorChecks() {
    var pending = authorCheckPending; authorCheckPending = Object.create(null); authorCheckTimer = null;
    if (!Object.keys(pending).length) return;
    try {
      var response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ action: 'check_authors', csrf_token: state.csrf, authors: pending }) });
      var data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Author check failed.');
      Object.keys(data.results || {}).forEach(function (index) {
        var note = container.querySelector('[data-author-check="' + index + '"]');
        if (!note) return;
        var checks = data.results[index] || [];
        state.rows[index].filipino_author_checks = checks.map(function (check) {
          return { name: check.name, status: check.status === 'not_found' ? 'needs_evidence' : check.status, matched_name: check.matched_name || (check.matches || [])[0] || '' };
        });
        var allVerified = checks.length > 0 && checks.every(function (check) { return check.status === 'verified'; });
        note.className = 'bulk-author-check ' + (allVerified ? 'is-verified' : 'is-review');
        note.textContent = checks.map(function (check) {
          if (check.status === 'verified') return check.name + ': already verified in the Filipino-author catalog.';
          if (check.status === 'possible_match') return check.name + ': possible name variant of ' + (check.matches || []).join(', ') + '; review the identity.';
          if (check.status === 'ambiguous') return check.name + ': multiple catalog matches (' + (check.matches || []).join(', ') + '); resolve before import.';
          if (check.status === 'not_found') return check.name + ': no verified catalog match. Metadata alone cannot establish Filipino identity; add a self-identification source for admin review.';
          return check.name + ': catalog record is ' + check.status.replace(/_/g, ' ') + '; admin review is required.';
        }).join(' ');
      });
      refreshSummary();
    } catch (error) {
      Object.keys(pending).forEach(function (index) {
        var note = container.querySelector('[data-author-check="' + index + '"]');
        if (note) { note.className = 'bulk-author-check is-review'; note.textContent = 'Could not complete the author check. The server will still verify this author when the row is imported.'; }
      });
    }
  }
  function showCatalogResult(index, data) {
    var row = state.rows[index];
    row.local_match = data.match || null;
    var candidateBox = container.querySelector('[data-catalog-candidates="' + index + '"]');
    if (candidateBox) candidateBox.textContent = '';
    if (!row.author_description && data.author_description) {
      row.author_description = data.author_description;
      var bioField = container.querySelector('[data-row="' + index + '"][data-field="author_description"]');
      if (bioField) bioField.value = data.author_description;
    }
    if (data.match) {
      var matched = data.match;
      if (!String(row.format || '').trim() && matched.suggested_format) row.format = matched.suggested_format;
      ['title', 'author', 'isbn', 'description', 'language', 'publisher'].forEach(function (key) {
        if (matched[key]) row[key] = matched[key];
      });
      if ((!Array.isArray(row.genre_ids) || !row.genre_ids.length) && Array.isArray(matched.genre_ids)) row.genre_ids = matched.genre_ids;
      if (data.author_description) row.author_description = data.author_description;
      row.catalog_author_bio_available = !!row.author_description;
      if (matched.cover_url && (matched.cover_license_note || /^https:\/\//i.test(matched.cover_url))) row.cover_url = matched.cover_url;
      if (matched.cover_url && matched.cover_license_note) row.reuse_catalog_cover = true;
      if (matched.cover_url && matched.cover_license_note) row.cover_permission_confirmed = true;
      if (matched.cover_source_url && !row.cover_source_url) row.cover_source_url = matched.cover_source_url;
      if (matched.cover_license_note && !row.cover_license_note) row.cover_license_note = matched.cover_license_note;
      if (matched.edition_id && !String(row.price || '').trim() && matched.price) row.price = String(matched.price);
      renderRowFields(index); updateCover(index);
      row.lookup_status = 'Existing catalog book';
      row.existing_catalog_selected = true;
      updateRowHeading(index, 'existing', 'Existing book');
      renderRowFields(index);
      setRowEditable(index, false);
      var editionsSummary = matched.catalog_record ? Object.keys(matched.catalog_record.editions || {}).map(function (format) {
        var existingEdition = matched.catalog_record.editions[format];
        return format + (existingEdition.isbn ? ' · ISBN ' + existingEdition.isbn : '') + ' · stock ' + existingEdition.stock_quantity + ' · ₱' + Number(existingEdition.price).toFixed(2);
      }).join('; ') : '';
      setNotice(index, 'This book is already in the shop catalog. It is view-only here and will not be added or changed by this new-book import.' + (editionsSummary ? ' Catalog formats: ' + editionsSummary + '.' : ''), 'success');
      if (candidateBox && matched.catalog_record) {
        var existingCard = el('article', 'bulk-candidate bulk-existing-record');
        var matchingEdition = (matched.catalog_record.editions || {})[matched.format];
        if (matchingEdition && matchingEdition.cover_url) { var existingCover = el('img'); existingCover.src = matchingEdition.cover_url; existingCover.alt = ''; existingCover.loading = 'lazy'; existingCard.appendChild(existingCover); }
        var existingDetails = el('div', 'bulk-candidate-info');
        existingDetails.appendChild(el('strong', '', 'Existing shop catalog record'));
        existingDetails.appendChild(el('small', '', matched.title + ' — ' + (matched.author || 'Author not listed')));
        if (matched.catalog_record.description) existingDetails.appendChild(el('p', 'bulk-candidate-description', matched.catalog_record.description));
        existingDetails.appendChild(el('small', '', editionsSummary || 'No formats recorded yet'));
        var existingLink = el('a', 'btn btn-link btn-sm', 'View current shop listing'); existingLink.href = '/book.php?id=' + encodeURIComponent(matched.book_id); existingLink.target = '_blank'; existingLink.rel = 'noopener noreferrer'; existingDetails.appendChild(existingLink);
        existingCard.appendChild(existingDetails); candidateBox.appendChild(existingCard);
      }
    } else if (data.possible_matches && data.possible_matches.length) {
      updateRowHeading(index, 'possible-match', 'Same title found');
      setNotice(index, (data.notice ? data.notice + ' ' : '') + 'Same title found. Compare the author, ISBN, and format below. Select “Use this catalog record” only if it is the same book; a different edition ISBN will be stored on its own format. A conflicting ISBN for an already-existing format is blocked for review.', 'warning');
      if (candidateBox) {
        candidateBox.appendChild(el('h4', '', 'Possible records in this shop catalog'));
        data.possible_matches.forEach(function (book) {
          var card = el('article', 'bulk-candidate');
          var formatKeys = Object.keys(book.editions || {});
          var firstEdition = formatKeys.length ? book.editions[formatKeys[0]] : null;
          if (firstEdition && firstEdition.cover_url) { var cover = el('img'); cover.src = firstEdition.cover_url; cover.alt = ''; cover.loading = 'lazy'; card.appendChild(cover); }
          var details = el('div', 'bulk-candidate-info');
          details.appendChild(el('strong', '', book.title));
          details.appendChild(el('small', '', (book.authors || []).join(', ') || 'Author not listed'));
          var editionsText = Object.keys(book.editions || {}).map(function (format) { var edition = book.editions[format]; return format + (edition.isbn ? ' · ISBN ' + edition.isbn : '') + ' (stock ' + edition.stock_quantity + ')'; }).join(', ');
          details.appendChild(el('small', '', editionsText || 'No edition recorded'));
          if (book.isbn) details.appendChild(el('small', '', 'ISBN ' + book.isbn));
          details.appendChild(el('small', '', [book.publisher, book.language].filter(Boolean).join(' · ') || 'Publisher or language not recorded'));
          if (book.description) details.appendChild(el('p', 'bulk-candidate-description', book.description));
          var catalogPreview = el('a', 'btn btn-link btn-sm', 'View this book in the shop'); catalogPreview.href = '/book.php?id=' + encodeURIComponent(book.book_id); catalogPreview.target = '_blank'; catalogPreview.rel = 'noopener noreferrer'; details.appendChild(catalogPreview);
          var selectButton = el('button', 'btn btn-secondary btn-sm', 'Use this catalog record'); selectButton.type = 'button';
          selectButton.addEventListener('click', function () { applyCatalogCandidate(index, book); });
          details.appendChild(selectButton); card.appendChild(details); candidateBox.appendChild(card);
        });
      }
    } else if (data.notice) {
      updateRowHeading(index, 'needs-review', 'Catalog needs review');
      setNotice(index, data.notice, 'warning');
    } else {
      updateRowHeading(index, 'catalog-clear', 'No exact catalog match');
      setNotice(index, 'No exact ISBN or title-and-author match found. The server will check again before importing.', 'info');
    }
    refreshSummary();
  }
  function applyCatalogCandidate(index, book) {
    readRows();
    var row = state.rows[index];
    row.title = book.title || row.title;
    row.author = (book.authors || []).join('; ') || row.author;
    var availableEditionKeys = Object.keys(book.editions || {});
    var chosenFormat = row.format || (availableEditionKeys.length === 1 ? availableEditionKeys[0] : '');
    var chosenEdition = chosenFormat ? (book.editions || {})[chosenFormat] : null;
    row.isbn = (chosenEdition && chosenEdition.isbn) || (availableEditionKeys.length <= 1 ? (book.isbn || row.isbn || '') : row.isbn || '');
    row.description = book.description || row.description || '';
    row.language = book.language || row.language || '';
    row.publisher = book.publisher || row.publisher || '';
    row.genre_ids = Array.isArray(book.genre_ids) ? book.genre_ids : row.genre_ids;
    var primary = (book.authors || [])[0];
    row.author_description = (book.author_bios || {})[primary] || '';
    row.catalog_author_bio_available = !!row.author_description;
    var format = row.format || 'paperback';
    var edition = (book.editions || {})[format];
    if (!edition) {
      var formatsAvailable = Object.keys(book.editions || {});
      if (formatsAvailable.length === 1) { format = formatsAvailable[0]; row.format = format; edition = book.editions[format]; }
      else if (!String(row.format || '').trim() && formatsAvailable.indexOf('paperback') !== -1) { format = 'paperback'; row.format = format; edition = book.editions[format]; }
    }
    if (edition) {
      row.cover_url = edition.cover_url && (edition.cover_license_note || /^https:\/\//i.test(edition.cover_url)) ? edition.cover_url : '';
      row.cover_source_url = edition.cover_source_url || row.cover_source_url || '';
      row.cover_license_note = edition.cover_license_note || row.cover_license_note || '';
      row.reuse_catalog_cover = !!edition.cover_url && !!edition.cover_license_note;
      if (row.reuse_catalog_cover) row.cover_permission_confirmed = true;
      if (edition.price && !row.unit_cost) row.price = String(edition.price);
    }
    row.lookup_status = 'Existing catalog record selected';
    row.local_match = book;
    row.existing_catalog_selected = true;
    renderRowFields(index); updateCover(index); updateRowHeading(index, 'existing', 'Catalog record selected');
    setRowEditable(index, false);
    var box = container.querySelector('[data-catalog-candidates="' + index + '"]'); if (box) box.textContent = '';
    setNotice(index, 'This title is already in the shop catalog. It is view-only and excluded from this new-book import.', 'success');
    refreshSummary(); checkCatalog(index);
  }
  function scheduleCatalogCheck(index) {
    if (catalogCheckTimers[index]) clearTimeout(catalogCheckTimers[index]);
    var version = (catalogCheckVersions[index] || 0) + 1;
    catalogCheckVersions[index] = version;
    catalogCheckTimers[index] = setTimeout(function () { checkCatalog(index, version); }, 500);
  }
  async function checkCatalog(index, version) {
    readRows(); var row = state.rows[index];
    if (!row.title || row.skip) return;
    if (version === undefined) {
      version = (catalogCheckVersions[index] || 0) + 1;
      catalogCheckVersions[index] = version;
    }
    setNotice(index, 'Checking this title against the current catalog…', 'info');
    try {
      var response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ action: 'match', csrf_token: state.csrf, title: row.title, author: row.author, isbn: row.isbn, format: row.format }) });
      var data = await response.json();
      if (catalogCheckVersions[index] !== version) return;
      if (!response.ok || !data.success) throw new Error(data.message || 'Catalog check failed.');
      showCatalogResult(index, data);
    } catch (error) {
      if (catalogCheckVersions[index] !== version) return;
      updateRowHeading(index, 'catalog-error', 'Catalog check failed');
      setNotice(index, 'Could not check the local catalog: ' + error.message + '. The server will still perform a duplicate check before import.', 'warning');
    }
  }
  async function checkCatalogBatch() {
    var checks = {}, versions = {};
    state.rows.forEach(function (row, index) {
      if (!row.skip && row.title) {
        if (catalogCheckTimers[index]) clearTimeout(catalogCheckTimers[index]);
        versions[index] = catalogCheckVersions[index] || 0;
        catalogCheckVersions[index] = versions[index];
        checks[index] = { title: row.title, author: row.author || '', isbn: row.isbn || '', format: row.format || '' };
      }
    });
    if (!Object.keys(checks).length) return;
    Object.keys(checks).forEach(function (index) { setNotice(Number(index), 'Checking this title against the current catalog…', 'info'); });
    try {
      var response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ action: 'match_many', csrf_token: state.csrf, rows: checks }) });
      var data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Catalog check failed.');
      Object.keys(data.results || {}).forEach(function (index) {
        if (catalogCheckVersions[index] === versions[index]) showCatalogResult(Number(index), data.results[index]);
      });
    } catch (error) {
      Object.keys(checks).forEach(function (index) {
        if (catalogCheckVersions[index] === versions[index]) setNotice(Number(index), 'Could not check the local catalog: ' + error.message + '. The server will still check for duplicates before import.', 'warning');
      });
    }
  }

  function attachInputListeners() {
    container.addEventListener('input', function (event) {
      var target = event.target, index = Number(target.dataset.row), key = target.dataset.field;
      if (!key || !Number.isInteger(index) || !state.rows[index]) return;
      readRows();
      if (key === 'unit_cost' || key === 'markup_percent') {
        var row = state.rows[index], cost = parseFloat(row.unit_cost), markup = parseFloat(row.markup_percent);
        var priceInput = container.querySelector('[data-row="' + index + '"][data-field="price"]');
        if (priceInput) priceInput.readOnly = row.unit_cost !== '';
        if (row.unit_cost !== '' && Number.isFinite(cost) && Number.isFinite(markup)) { row.price = (cost * (1 + markup / 100)).toFixed(2); if (priceInput) priceInput.value = row.price; }
        else if (row.reference_price && Number.isFinite(markup) && row.price_source) { row.price = (row.reference_price * (1 + markup / 100)).toFixed(2); if (priceInput) priceInput.value = row.price; row.price_source = 'Google Books PHP retail reference + ' + markup + '% markup'; }
        var sourceLabel = container.querySelector('[data-row="' + index + '"] .bulk-price-source');
        if (sourceLabel) sourceLabel.textContent = row.price_source || 'Uses catalog price, cost + markup, or a PHP online reference + markup. Without one, enter a price.';
      }
      if (key === 'cover_url') updateCover(index);
      if (['title', 'author', 'isbn'].indexOf(key) !== -1) scheduleCatalogCheck(index);
      if (key === 'author') scheduleAuthorCheck(index);
      if (key === 'title' || key === 'author' || key === 'isbn') scheduleAutomaticLookup(index);
      updateRowHeading(index, state.rows[index].lookup_status || 'needs-details'); refreshSummary();
    });
    container.addEventListener('change', function (event) {
      if (event.target.dataset.field === 'genre_ids' || event.target.type === 'checkbox') refreshSummary();
      if (event.target.dataset.field === 'format') scheduleCatalogCheck(Number(event.target.dataset.row));
      if (event.target.dataset.field === 'cover_file') {
        var index = Number(event.target.dataset.row), file = event.target.files && event.target.files[0];
        if (file) {
          state.rows[index].cover_file_present = true;
          var preview = container.querySelector('[data-preview="' + index + '"]'), existing = preview && preview.querySelector('img');
          if (preview) { if (!existing) { existing = el('img'); preview.prepend(existing); } existing.src = URL.createObjectURL(file); existing.alt = 'Uploaded cover preview'; }
        } else state.rows[index].cover_file_present = false;
        refreshSummary();
      }
    });
  }
  var lookupQueue = Promise.resolve();
  var lookupPending = Object.create(null);
  function enqueueLookup(index) {
    if (lookupPending[index]) return lookupPending[index];
    var task = lookupQueue.then(function () { return lookupRow(index); });
    lookupPending[index] = task.finally(function () { delete lookupPending[index]; });
    lookupQueue = task.catch(function () {}).then(function () { return delay(1200); });
    return lookupPending[index];
  }
  async function concurrentLookup(indices) {
    for (var i = 0; i < indices.length; i++) await enqueueLookup(indices[i]);
  }
  function scheduleAutomaticLookup(index) {
    if (lookupTimers[index]) clearTimeout(lookupTimers[index]);
    lookupTimers[index] = setTimeout(function () { if (!state.rows[index].skip) enqueueLookup(index); }, 700);
  }

  state.rows.forEach(makeRow);
  attachInputListeners(); refreshSummary();
  state.rows.forEach(function (_, index) { renderPreparedAuthorCheck(index); });
  if (imported) progress.hidden = true;
  if (!imported) {
    checkCatalogBatch().then(function () {
      var pending = state.rows.map(function (row, index) { return index; }).filter(function (index) { return !state.rows[index].skip && !(state.rows[index].local_match && state.rows[index].lookup_status === 'Existing catalog book'); });
      if (pending.length) {
        progress.max = pending.length; progress.value = 0; progress.hidden = false;
        message.className = 'bulk-message is-info'; message.textContent = 'Automatically matching book titles and authors online: 0 of ' + pending.length + ' checked…';
        return concurrentLookup(pending.map(function (index, position) {
          var task = enqueueLookup(index);
          task.then(function () { progress.value = position + 1; message.textContent = 'Automatically matching book titles and authors online: ' + (position + 1) + ' of ' + pending.length + ' checked…'; });
          return task;
        })).then(function () { message.textContent = 'Automatic online matching is complete. Review any uncertain matches and fill remaining required details.'; });
      }
      progress.hidden = true;
      message.textContent = 'Catalog checks are complete. Existing records are shown below; review remaining details for new books.';
    });
  }
  if (imported) { message.textContent = 'This batch is already complete. Reloading it is safe and will not add stock again.'; }
  importButton.addEventListener('click', async function () {
    var result = refreshSummary();
    if (!result.ready || result.duplicateCount) { message.textContent = 'Complete the required fields and resolve duplicate ISBN rows before importing.'; return; }
    readRows(); importButton.disabled = true; message.className = 'bulk-message is-info'; message.textContent = 'Saving the batch safely…';
    try {
      var rowsJson = JSON.stringify(state.rows);
      var payload = new FormData(); payload.append('action', 'commit'); payload.append('batch_id', state.batchId); payload.append('rows_json', rowsJson); payload.append('csrf_token', state.csrf);
      var coverBytes = 0, oversizedCover = false;
      container.querySelectorAll('input[type="file"][data-field="cover_file"]').forEach(function (fileInput) {
        if (fileInput.files && fileInput.files[0]) { coverBytes += fileInput.files[0].size; if (fileInput.files[0].size > 1500000) oversizedCover = true; payload.append('cover_' + fileInput.dataset.row, fileInput.files[0]); }
      });
      if (oversizedCover || coverBytes + new Blob([rowsJson]).size > 6000000) { message.className = 'bulk-message is-error'; message.textContent = 'Each uploaded cover must be 1.5 MB or smaller, and all batch data must stay below 6 MB. Resize covers or split a very large batch.'; importButton.disabled = false; return; }
      var response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: payload });
      var responseText = await response.text();
      var data;
      try { data = JSON.parse(responseText); }
      catch (_) { throw new Error('The server returned a non-JSON page (HTTP ' + response.status + '). You may have been redirected to login or PHP reported an error; check the PHP server terminal.'); }
      if (!response.ok || !data.success) {
        message.className = 'bulk-message is-error'; message.textContent = data.message || 'Import did not complete.';
        (data.issues || []).forEach(function (issue) { setNotice(issue.index, issue.message, 'warning'); updateRowHeading(issue.index, 'needs-review', 'Needs attention'); });
        importButton.disabled = false; return;
      }
      imported = true; message.className = 'bulk-message is-success';
      var s = data.summary || {};
      message.textContent = data.already_imported ? data.message : 'Done: ' + (s.created || 0) + ' new books added, ' + (s.skipped || 0) + ' skipped. Existing books and unverified authors were excluded. Reloading this batch will not apply it twice.';
      importButton.textContent = 'Batch Imported';
      container.querySelectorAll('input,select,textarea,button').forEach(function (field) { field.disabled = true; });
    } catch (error) {
      message.className = 'bulk-message is-error'; message.textContent = 'Import request failed: ' + (error.message || 'network error') + ' The batch is safe to retry after resolving the issue.'; importButton.disabled = false;
    }
  });
})();
