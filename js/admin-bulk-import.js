(function () {
  'use strict';
  var state = window.bulkImportState;
  if (!state || !Array.isArray(state.rows)) return;
  var container = document.getElementById('bulk-rows');
  var message = document.getElementById('bulk-message');
  var progress = document.getElementById('bulk-progress');
  var scanStatus = document.getElementById('bulk-scan-status');
  var scanTitle = document.getElementById('bulk-scan-title');
  var scanIcon = document.getElementById('bulk-scan-icon');
  var duplicateWarning = document.getElementById('bulk-duplicate-warning');
  var categoryNote = document.getElementById('bulk-category-note');
  var importButton = document.getElementById('import-ready');
  var filters = document.getElementById('bulk-filters');
  var activeFilter = 'all';
  var lookupTimers = Object.create(null);
  var uploadedCoverUrls = Object.create(null);
  var scanDismissTimer = null;
  var scanHideTimer = null;
  var autoMatchingInProgress = false;
  var imported = state.status === 'imported';
  var formats = [['', 'Select format'], ['paperback', 'Paperback / softbound'], ['hardbound', 'Hardcover / hardbound'], ['ebook', 'Ebook']];
  var languageOptions = [['', 'Select language'], ['English', 'English'], ['Filipino', 'Filipino'], ['Other Languages', 'Other Languages']];
  var synopsisPlaceholder = 'A full synopsis will be added soon.';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }
  function norm(value) { return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' '); }
  function authorNorm(value) { return norm(value).split(';')[0]; }
  function languageCategory(value) {
    var raw = String(value || '').trim().toLowerCase();
    if (!raw) return '';
    var key = norm(raw.split(/[\\/_]/).pop());
    var shortCode = norm(raw.split(/[-_]/)[0]);
    if (['en', 'eng'].indexOf(shortCode) !== -1) return 'English';
    if (['fil', 'tl', 'tgl'].indexOf(shortCode) !== -1) return 'Filipino';
    if (!key) return '';
    if (['en', 'eng', 'english'].indexOf(key) !== -1) return 'English';
    if (['fil', 'filipino', 'tl', 'tgl', 'tagalog'].indexOf(key) !== -1) return 'Filipino';
    return 'Other Languages';
  }
  function inferFormat(item, info) {
    if (item.saleInfo && item.saleInfo.isEbook === true) return 'ebook';
    var formatText = norm([info.format, info.binding, info.editionType].filter(Boolean).join(' '));
    if (/\b(hardcover|hardback|hardbound)\b/.test(formatText)) return 'hardbound';
    if (/\b(ebook|e book|digital)\b/.test(formatText)) return 'ebook';
    if (/\b(paperback|softcover|softbound)\b/.test(formatText)) return 'paperback';
    if (String(info.printType || '').toUpperCase() === 'BOOK') return 'paperback';
    return '';
  }
  function metadataGenreIds(categories) {
    var aliases = {
      "Children's Literature": ['children', 'juvenile'],
      'Classics': ['classic'],
      'Contemporary Fiction': ['contemporary fiction'],
      'Fantasy & Speculative Fiction': ['fantasy', 'science fiction', 'speculative fiction'],
      'Food & Culture': ['food', 'cooking', 'cookery', 'culture'],
      'Graphic Novels & Comics': ['graphic novel', 'comics', 'comic book'],
      'Historical Fiction': ['historical fiction'],
      'History': ['history', 'biography', 'autobiography'],
      'Horror': ['horror'],
      'Literary Fiction': ['literary fiction'],
      'Mystery & Crime': ['mystery', 'crime', 'detective', 'thriller'],
      'Poetry': ['poetry', 'poems'],
      'Romance': ['romance'],
      'Young Adult': ['young adult', 'young adult fiction', 'ya fiction']
    };
    var normalized = (categories || []).map(norm).filter(Boolean);
    return state.genres.filter(function (genre) {
      if (Number(state.fallbackGenreId) > 0 && Number(genre.id) === Number(state.fallbackGenreId)) return false;
      var choices = aliases[genre.name] || [genre.name];
      return normalized.some(function (category) {
        var categoryWords = category.split(' ');
        return choices.some(function (choice) {
          var phrase = norm(choice);
          return category.indexOf(phrase) !== -1 || phrase.indexOf(category) !== -1 || phrase.split(' ').every(function (word) { return categoryWords.indexOf(word) !== -1; });
        });
      });
    }).map(function (genre) { return genre.id; });
  }
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
    var initialValue = rowValue(rowIndex, key, opts.default || '');
    input.value = key === 'language' ? languageCategory(initialValue) : initialValue;
    if (opts.placeholder) input.placeholder = opts.placeholder;
    if (opts.disabled || imported) input.disabled = true;
    wrap.appendChild(input);
    return { wrap: wrap, input: input };
  }
  function rowValue(index, key, fallback) { var value = state.rows[index][key]; return value === undefined || value === null ? fallback : String(value); }
  function selectedGenres(index) { return Array.isArray(state.rows[index].genre_ids) ? state.rows[index].genre_ids.map(String) : []; }
  function hasFallbackGenre(row) { return Number(state.fallbackGenreId) > 0 && Array.isArray(row.genre_ids) && row.genre_ids.map(Number).indexOf(Number(state.fallbackGenreId)) !== -1; }
  function isDetectedFallbackGenre(row) { return row.genre_assignment_source === 'fallback' && row.genre_lookup_complete && hasFallbackGenre(row); }
  function isSynopsisPlaceholder(value) {
    var text = String(value || '').trim().toLowerCase();
    return text === synopsisPlaceholder.toLowerCase() || text === 'synopsis to be updated.';
  }
  function coverPlaceholder(title, extraClass) {
    var placeholder = el('span', 'bulk-book-cover-placeholder' + (extraClass ? ' ' + extraClass : ''));
    placeholder.appendChild(el('small', '', 'Cover unavailable'));
    placeholder.appendChild(el('strong', '', title || 'Untitled book'));
    return placeholder;
  }
  function rightArrowIcon() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'currentColor'); svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('fill-rule', 'evenodd'); path.setAttribute('clip-rule', 'evenodd');
    path.setAttribute('d', 'M12.97 3.97a.75.75 0 0 1 1.06 0l7.5 7.5a.75.75 0 0 1 0 1.06l-7.5 7.5a.75.75 0 1 1-1.06-1.06l6.22-6.22H3a.75.75 0 0 1 0-1.5h16.19l-6.22-6.22a.75.75 0 0 1 0-1.06Z');
    svg.appendChild(path); return svg;
  }
  function makeRow(row, index) {
    var card = el('article', 'bulk-row');
    card.dataset.rowState = index;
    var openButton = el('button', 'bulk-book-open'); openButton.type = 'button'; openButton.dataset.openRow = index;
    openButton.setAttribute('aria-label', 'Open details for ' + (row.title || 'book row ' + (index + 1)));
    var coverFrame = el('span', 'bulk-book-cover'); coverFrame.dataset.cardCover = index;
    if (row.cover_url) { var tileCover = el('img'); tileCover.src = row.cover_url; tileCover.alt = ''; tileCover.loading = 'lazy'; coverFrame.appendChild(tileCover); }
    else coverFrame.appendChild(coverPlaceholder(row.title));
    openButton.appendChild(coverFrame);
    var cardCopy = el('span', 'bulk-book-copy');
    cardCopy.appendChild(el('small', 'bulk-row-number', 'Book ' + String(index + 1).padStart(2, '0')));
    var titleSummary = el('span', 'bulk-row-title');
    titleSummary.appendChild(el('strong', '', row.title || 'Untitled row'));
    titleSummary.appendChild(el('small', 'bulk-row-byline', row.author || 'Author unknown'));
    cardCopy.appendChild(titleSummary);
    var cardStatus = el('span', 'bulk-card-status', 'Checking…'); cardStatus.dataset.cardStatus = index; cardCopy.appendChild(cardStatus);
    openButton.appendChild(cardCopy);
    card.appendChild(openButton);
    var cardFooter = el('div', 'bulk-card-footer');
    var skipLabel = el('label', 'bulk-skip bulk-card-skip'); var skip = el('input'); skip.type = 'checkbox'; skip.dataset.row = index; skip.dataset.field = 'skip'; skip.checked = !!row.skip; skip.disabled = imported;
    skipLabel.appendChild(skip); skipLabel.appendChild(el('span', '', 'Skip'));
    cardFooter.appendChild(skipLabel);
    var detailButton = el('button', 'bulk-card-details', 'Details'); detailButton.type = 'button'; detailButton.dataset.openRow = index; detailButton.appendChild(rightArrowIcon());
    cardFooter.appendChild(detailButton); card.appendChild(cardFooter);
    var dialog = el('dialog', 'bulk-row-dialog'); dialog.setAttribute('aria-label', 'Book details: ' + (row.title || 'book row ' + (index + 1)));
    var dialogHead = el('header', 'bulk-row-dialog-head');
    var dialogTitle = el('div', 'bulk-row-dialog-title'); dialogTitle.appendChild(el('small', '', 'Book ' + String(index + 1).padStart(2, '0')));
    var dialogTitleText = el('h2', '', row.title || 'Untitled row'); dialogTitleText.dataset.dialogTitle = index; dialogTitle.appendChild(dialogTitleText);
    dialogHead.appendChild(dialogTitle);
    var closeButton = el('button', 'bulk-row-dialog-close', '×'); closeButton.type = 'button'; closeButton.setAttribute('aria-label', 'Close book details');
    closeButton.addEventListener('click', function () { dialog.close(); }); dialogHead.appendChild(closeButton); dialog.appendChild(dialogHead);
    var body = el('div', 'bulk-row-body');
    var grid = el('div', 'bulk-review-grid');
    var fTitle = inputField(index, 'title', 'Book Title', { required: true }); fTitle.wrap.classList.add('bulk-field-title');
    var fAuthor = inputField(index, 'author', 'Author(s)', { required: true, placeholder: 'Separate contributors with semicolons' });
    fAuthor.wrap.classList.add('bulk-field-author');
    var authorCheck = el('small', 'bulk-author-check', 'Filipino identity was checked during batch preparation.'); authorCheck.dataset.authorCheck = index; authorCheck.setAttribute('aria-live', 'polite');
    fAuthor.wrap.appendChild(authorCheck);
    var fDescription = inputField(index, 'description', 'Book Synopsis/Description', { type: 'textarea', rows: 5, required: true });
    fDescription.wrap.classList.add('bulk-field-synopsis');
    var synopsisHint = el('small', 'bulk-hint', 'Use the publisher’s official synopsis or another trusted catalog description. If none is available, write a short factual summary; keep this row in Needs Details until it is complete.');
    synopsisHint.dataset.synopsisHint = index; fDescription.wrap.appendChild(synopsisHint);
    var fAuthorBio = inputField(index, 'author_description', 'Author Description (Optional)', { type: 'textarea', rows: 5 });
    fAuthorBio.wrap.classList.add('bulk-field-author-description');
    var fIsbn = inputField(index, 'isbn', 'ISBN (optional)');
    var fPublisher = inputField(index, 'publisher', 'Publisher (Optional)');
    var fFormat = inputField(index, 'format', 'Edition / Format', { type: 'select', options: formats, required: true });
    fFormat.wrap.appendChild(el('small', 'bulk-hint', 'Ebooks are selected when identified; print books default to paperback. Confirm the binding you intend to stock.'));
    var fLanguage = inputField(index, 'language', 'Language', { type: 'select', options: languageOptions, required: true });
    var languageHint = el('small', 'bulk-hint', 'Automatically grouped from the metadata language.');
    languageHint.dataset.languageHint = index;
    fLanguage.wrap.appendChild(languageHint);
    var fCost = inputField(index, 'unit_cost', 'Unit Cost (Optional)', { type: 'number', min: '0', step: '0.01' });
    var fMarkup = inputField(index, 'markup_percent', 'Markup (%)', { type: 'number', min: '0', max: '500', step: '0.01', default: '25' });
    fMarkup.wrap.appendChild(el('small', 'bulk-hint', 'Applied to cost, online price, or fallback price basis.'));
    var fPrice = inputField(index, 'price', 'Website Selling Price (₱)', { type: 'number', min: '0.01', step: '0.01', required: true });
    fPrice.input.readOnly = !!String(row.unit_cost || '').trim();
    fPrice.wrap.appendChild(el('small', 'bulk-hint bulk-price-source', row.price_source || 'Uses cost + markup, an online PHP price + markup, or the fallback basis + markup.'));
    var fQuantity = inputField(index, 'quantity', 'Quantity', { type: 'number', min: '1', max: '9999999', required: true, default: '1' });
    var fCover = inputField(index, 'cover_url', 'Book Cover Image (Optional)', { placeholder: 'Filled from metadata when an image is available' });
    fCover.wrap.classList.add('bulk-field-cover-url');
    fCover.wrap.appendChild(el('small', 'bulk-hint', 'If no cover is available, the shop will display a title-based cover placeholder.'));
    var fEvidence = inputField(index, 'filipino_evidence_url', 'Filipino Identity Evidence URL', { placeholder: 'Public author statement or profile', wide: true });
    fEvidence.wrap.appendChild(el('small', 'bulk-hint', 'Unknown authors remain excluded until staff verify them in author review.'));
    var titleAuthorRow = el('div', 'bulk-review-row bulk-row-title-author');
    titleAuthorRow.append(fTitle.wrap, fAuthor.wrap); grid.appendChild(titleAuthorRow);
    var descriptionRow = el('div', 'bulk-review-row bulk-row-descriptions');
    descriptionRow.append(fDescription.wrap, fAuthorBio.wrap); grid.appendChild(descriptionRow);
    var metadataRow = el('div', 'bulk-review-row bulk-row-metadata');
    [fIsbn, fLanguage, fFormat].forEach(function (field) { metadataRow.appendChild(field.wrap); });
    var genreWrap = el('label', 'bulk-field bulk-field-genre');
    var genreLabel = el('span', 'bulk-field-label', 'Genres'); genreLabel.appendChild(el('span', 'bulk-asterisk', '*')); genreWrap.className += ' is-required'; genreWrap.appendChild(genreLabel);
    var genreSelect = el('select', 'form-input bulk-genre-select'); genreSelect.multiple = true; genreSelect.size = 3; genreSelect.dataset.row = index; genreSelect.dataset.field = 'genre_ids'; genreSelect.required = true; genreSelect.disabled = imported;
    state.genres.forEach(function (genre) {
      var option = el('option', '', genre.name); option.value = String(genre.id); option.selected = selectedGenres(index).indexOf(String(genre.id)) !== -1; genreSelect.appendChild(option);
    });
    genreWrap.appendChild(genreSelect);
    var genreHelp = el('small', 'bulk-hint', 'Select or confirm a store category.'); genreHelp.dataset.genreHint = index; genreWrap.appendChild(genreHelp);
    metadataRow.append(genreWrap, fPublisher.wrap); grid.appendChild(metadataRow);
    var priceRow = el('div', 'bulk-review-row bulk-row-pricing');
    [fCost, fMarkup, fPrice, fQuantity].forEach(function (field) { priceRow.appendChild(field.wrap); }); grid.appendChild(priceRow);
    var coverRow = el('div', 'bulk-review-row bulk-row-cover');
    coverRow.appendChild(fCover.wrap);
    var coverUploadGroup = el('div', 'bulk-cover-upload-group');
    var localCoverWrap = el('label', 'bulk-field bulk-field-cover-upload'); localCoverWrap.appendChild(el('span', 'bulk-field-label', 'Or Upload an Authorized Cover File'));
    var localCover = el('input', 'form-input'); localCover.type = 'file'; localCover.accept = 'image/jpeg,image/png,image/webp'; localCover.dataset.row = index; localCover.dataset.field = 'cover_file'; localCover.disabled = imported;
    localCoverWrap.appendChild(localCover); localCoverWrap.appendChild(el('small', 'bulk-hint', 'JPEG, PNG, or WebP up to 5 MB. Use a file you created or are permitted to display.'));
    coverUploadGroup.appendChild(localCoverWrap);
    var preview = el('div', 'bulk-cover-preview'); preview.dataset.preview = index;
    if (row.cover_url) {
      var image = el('img'); image.src = row.cover_url; image.alt = 'Suggested cover for ' + (row.title || 'book'); image.loading = 'lazy'; preview.appendChild(image);
    }
    var previewHelp = el('small', 'bulk-hint'); previewHelp.dataset.coverHelp = index;
    updateCoverHelp(index, previewHelp);
    preview.appendChild(previewHelp);
    coverRow.append(coverUploadGroup, preview); grid.appendChild(coverRow);

    var coverConsent = el('label', 'bulk-consent bulk-field-wide bulk-cover-consent');
    var consentBox = el('input'); consentBox.type = 'checkbox'; consentBox.dataset.row = index; consentBox.dataset.field = 'cover_permission_confirmed'; consentBox.checked = row.cover_permission_confirmed === undefined ? true : !!row.cover_permission_confirmed; consentBox.disabled = imported;
    coverConsent.appendChild(consentBox); coverConsent.appendChild(el('span', '', 'I have confirmed this cover is appropriate and permitted for this project’s display.'));
    coverConsent.appendChild(el('small', 'bulk-hint bulk-field-wide', 'Prechecked for review speed. Inspect the image and uncheck this if it is wrong or not permitted.'));
    coverConsent.hidden = !row.cover_url && !row.cover_file_present && !row.reuse_catalog_cover;
    grid.appendChild(coverConsent);
    grid.appendChild(fEvidence.wrap);
    body.appendChild(grid);

    var source = el('p', 'bulk-source'); source.dataset.source = index;
    source.textContent = row.metadata_source_url ? 'Metadata source: ' + row.metadata_source_url : 'No metadata source selected.';
    body.appendChild(source);

    var catalogCandidates = el('div', 'bulk-candidates'); catalogCandidates.dataset.catalogCandidates = index; body.appendChild(catalogCandidates);
    var onlineCandidates = el('div', 'bulk-candidates'); onlineCandidates.dataset.onlineCandidates = index; body.appendChild(onlineCandidates);
    var rowNotice = el('div', 'bulk-row-notice'); rowNotice.dataset.notice = index; body.appendChild(rowNotice);
    dialog.appendChild(body);
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    openButton.addEventListener('click', function () { dialog.showModal(); });
    detailButton.addEventListener('click', function () { dialog.showModal(); });
    card.appendChild(dialog); container.appendChild(card);
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
    var tile = container.querySelector('[data-card-cover="' + index + '"]');
    var consent = container.querySelector('[data-row="' + index + '"][data-field="cover_permission_confirmed"]');
    if (consent) consent.closest('.bulk-consent').hidden = !row.cover_url && !row.cover_file_present && !row.reuse_catalog_cover;
    var img = preview ? preview.querySelector('img') : null;
    if (row.cover_url) {
      if (preview) {
        var oldPlaceholder = preview.querySelector('.bulk-cover-preview-placeholder'); if (oldPlaceholder) oldPlaceholder.remove();
        if (!img) { img = el('img'); img.loading = 'lazy'; preview.prepend(img); }
        img.src = row.cover_url; img.alt = 'Suggested cover for ' + (row.title || 'book');
      }
      if (tile) {
        var tileImg = tile.querySelector('img');
        if (!tileImg) { tileImg = el('img'); tileImg.loading = 'lazy'; tile.textContent = ''; tile.appendChild(tileImg); }
        tileImg.src = row.cover_url; tileImg.alt = '';
      }
    } else {
      if (img) img.remove();
      if (preview && !preview.querySelector('.bulk-cover-preview-placeholder')) preview.prepend(coverPlaceholder(row.title, 'bulk-cover-preview-placeholder'));
      if (tile) {
        var staleTileImg = tile.querySelector('img');
        if (staleTileImg) staleTileImg.remove();
        var placeholder = tile.querySelector('.bulk-book-cover-placeholder');
        if (!placeholder) { placeholder = coverPlaceholder(row.title); tile.appendChild(placeholder); }
        var placeholderTitle = placeholder.querySelector('strong'); if (placeholderTitle) placeholderTitle.textContent = row.title || 'Untitled book';
      }
    }
    var note = preview && preview.querySelector('[data-cover-help]'); if (note) updateCoverHelp(index, note);
  }
  function updateCoverHelp(index, node) {
    var row = state.rows[index];
    node.textContent = '';
    if (row.cover_url) {
      node.appendChild(document.createTextNode('Cover image found. Source: '));
      var source = row.cover_source_url || row.cover_url;
      var link = el('a', '', 'view source'); link.href = source; link.target = '_blank'; link.rel = 'noopener noreferrer'; node.appendChild(link);
    } else if (row.metadata_source_url) {
      node.appendChild(document.createTextNode('No cover image was returned. Metadata record: '));
      var record = el('a', '', 'open book record'); record.href = row.metadata_source_url; record.target = '_blank'; record.rel = 'noopener noreferrer'; node.appendChild(record);
    } else {
      node.textContent = 'No cover image selected yet.';
    }
  }
  function updateUploadedCover(index, file) {
    if (uploadedCoverUrls[index]) URL.revokeObjectURL(uploadedCoverUrls[index]);
    uploadedCoverUrls[index] = file ? URL.createObjectURL(file) : '';
    var preview = container.querySelector('[data-preview="' + index + '"]');
    var consent = container.querySelector('[data-row="' + index + '"][data-field="cover_permission_confirmed"]');
    if (consent) consent.closest('.bulk-consent').hidden = !file && !state.rows[index].cover_url && !state.rows[index].reuse_catalog_cover;
    if (file) {
      var previewImg = preview && preview.querySelector('img');
      var previewPlaceholder = preview && preview.querySelector('.bulk-cover-preview-placeholder'); if (previewPlaceholder) previewPlaceholder.remove();
      if (preview && !previewImg) { previewImg = el('img'); preview.prepend(previewImg); }
      if (previewImg) { previewImg.src = uploadedCoverUrls[index]; previewImg.alt = 'Uploaded cover preview'; }
      var tile = container.querySelector('[data-card-cover="' + index + '"]');
      if (tile) {
        var tileImg = tile.querySelector('img');
        if (!tileImg) { tileImg = el('img'); tile.textContent = ''; tile.appendChild(tileImg); }
        tileImg.src = uploadedCoverUrls[index]; tileImg.alt = '';
      }
    } else updateCover(index);
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
    var dialogTitle = container.querySelector('[data-dialog-title="' + index + '"]');
    if (dialogTitle && title) dialogTitle.textContent = title.value || 'Untitled row';
    var openButton = container.querySelector('[data-open-row="' + index + '"]');
    if (openButton && title) openButton.setAttribute('aria-label', 'Open details for ' + (title.value || 'book row ' + (index + 1)));
    if (rowCard && title) { var dialog = rowCard.querySelector('.bulk-row-dialog'); if (dialog) dialog.setAttribute('aria-label', 'Book details: ' + (title.value || 'book row ' + (index + 1))); }
  }
  function isExisting(row) { return !!(row.existing_catalog_selected || row.server_excluded_existing || row.local_match || row.lookup_status === 'Existing catalog book' || row.lookup_status === 'Existing catalog record selected'); }
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
    var hasCover = !!String(row.cover_url || '').trim() || !!row.cover_file_present || !!row.reuse_catalog_cover;
    return required.filter(function (key) { return key !== 'cover_url'; }).every(function (key) { return String(row[key] || '').trim() !== ''; })
      && chosen && (!hasCover || !!row.cover_permission_confirmed || !!row.reuse_catalog_cover);
  }
  function refreshSummary() {
    readRows();
    var ready = 0, skipped = 0, needs = 0, existing = 0, unknown = 0, seen = {};
    state.rows.forEach(function (row, index) {
      var card = container.querySelector('[data-row-state="' + index + '"]');
    if (card) {
        card.classList.toggle('is-existing-book', isExisting(row));
        card.classList.toggle('is-unknown-author', isUnknownAuthor(row));
        card.classList.toggle('is-skipped', !!row.skip);
        card.classList.toggle('is-ready', !row.skip && rowReady(row, index));
        card.classList.toggle('is-needs-details', !row.skip && !isExisting(row) && !isUnknownAuthor(row) && !rowReady(row, index));
        var cardStatus = card.querySelector('[data-card-status]');
        if (cardStatus) cardStatus.textContent = row.skip ? 'Skipped' : isExisting(row) ? 'Already in catalog' : isUnknownAuthor(row) ? 'Author review' : rowReady(row, index) ? 'Ready to import' : 'Needs details';
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
    if (coverField && coverField.closest('.bulk-field')) coverField.closest('.bulk-field').classList.remove('is-missing');
    var consent = container.querySelector('[data-row="' + index + '"][data-field="cover_permission_confirmed"]');
    var hasCover = !!String(row.cover_url || '').trim() || !!row.cover_file_present || !!row.reuse_catalog_cover;
    if (consent) consent.closest('.bulk-consent').classList.toggle('is-missing', hasCover && !consent.checked && !isExisting(row));
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
  function recalculatePrice(row, input) {
    var markup = Number(row.markup_percent || 0), cost = Number(row.unit_cost);
    if (String(row.unit_cost || '').trim() && Number.isFinite(cost)) {
      row.price = (cost * (1 + markup / 100)).toFixed(2);
      row.price_source_type = 'unit_cost';
      row.price_source = 'Unit cost + ' + markup + '% markup';
    } else if (row.price_source_type === 'unit_cost') {
      if (Number(row.reference_price) > 0) {
        row.price_source_type = 'online_reference';
        row.price = (Number(row.reference_price) * (1 + markup / 100)).toFixed(2);
        row.price_source = 'Google Books PHP retail reference + ' + markup + '% markup';
      } else if (String(row.csv_price || '').trim()) {
        row.price_source_type = 'csv';
        row.price = String(row.csv_price);
        row.price_source = 'Website price provided in CSV';
      } else {
        row.price_source_type = 'fallback_baseline';
        row.price = (Number(row.fallback_price_basis || 500) * (1 + markup / 100)).toFixed(2);
        row.price_source = '₱' + Number(row.fallback_price_basis || 500).toFixed(2) + ' fallback price + ' + markup + '% markup';
      }
    } else if (row.price_source_type === 'online_reference' && Number(row.reference_price) > 0) {
      row.price = (Number(row.reference_price) * (1 + markup / 100)).toFixed(2);
      row.price_source = 'Google Books PHP retail reference + ' + markup + '% markup';
    } else if (row.price_source_type === 'fallback_baseline') {
      row.price = (Number(row.fallback_price_basis || 500) * (1 + markup / 100)).toFixed(2);
      row.price_source = '₱' + Number(row.fallback_price_basis || 500).toFixed(2) + ' fallback price + ' + markup + '% markup';
    }
    if (input && row.price_source_type !== 'manual' && row.price_source_type !== 'csv') input.value = row.price || '';
    var sourceLabel = input ? container.querySelector('[data-row="' + input.dataset.row + '"] .bulk-price-source') : null;
    if (sourceLabel && row.price_source) sourceLabel.textContent = row.price_source;
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
  function openLibraryWorkKey(item) {
    var raw = item && item.id || item && item.volumeInfo && item.volumeInfo.infoLink || '';
    try {
      var url = new URL(raw, 'https://openlibrary.org');
      var match = url.pathname.match(/^\/works\/(OL[0-9]+W)$/);
      return url.hostname === 'openlibrary.org' && match ? '/works/' + match[1] : '';
    } catch (_) { return ''; }
  }
  async function fetchOpenLibraryCover(index, item) {
    var row = state.rows[index], workKey = openLibraryWorkKey(item);
    if (!workKey || row.cover_url) return;
    try {
      var response = await fetch('/api/book_metadata.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ query: row.title || 'cover lookup', title: row.title, author: row.author, provider: 'open_library_cover', work_key: workKey, csrf_token: state.csrf })
      });
      var data = await response.json();
      if (!response.ok || !data.success || !data.cover_url) return;
      readRows(); row = state.rows[index];
      if (row.cover_url || row.skip || isExisting(row)) return;
      row.cover_url = data.cover_url;
      row.cover_permission_confirmed = true;
      row.cover_source_url = data.cover_source_url || ('https://openlibrary.org' + workKey);
      renderRowFields(index); updateCover(index); refreshSummary();
      setNotice(index, 'Open Library supplied a cover image. Review that it matches this edition and is permitted for display.', 'info');
    } catch (_) {
      // A missing or unavailable cover must not block metadata review.
    }
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
  function showScanStatus(text, kind) {
    if (!scanStatus || !message) return;
    if (scanDismissTimer) clearTimeout(scanDismissTimer);
    if (scanHideTimer) clearTimeout(scanHideTimer);
    scanHideTimer = null;
    scanStatus.hidden = false;
    scanStatus.classList.remove('is-dismissing');
    scanStatus.classList.remove('is-info', 'is-success', 'is-warning', 'is-error');
    scanStatus.classList.add(kind === 'success' ? 'is-success' : kind === 'warning' ? 'is-warning' : kind === 'error' ? 'is-error' : 'is-info');
    if (scanTitle) scanTitle.textContent = kind === 'success' ? 'Book check complete' : kind === 'error' || kind === 'warning' ? 'Review needed' : 'Checking book records';
    if (scanIcon) scanIcon.textContent = kind === 'success' ? '✓' : kind === 'error' || kind === 'warning' ? '!' : 'i';
    message.textContent = text;
  }
  function dismissScanStatusAfter(ms) {
    if (!scanStatus) return;
    if (scanDismissTimer) clearTimeout(scanDismissTimer);
    scanDismissTimer = setTimeout(function () {
      scanStatus.classList.add('is-dismissing');
      scanHideTimer = setTimeout(function () { scanStatus.hidden = true; scanStatus.classList.remove('is-dismissing'); scanHideTimer = null; }, 250);
    }, ms === undefined ? 5000 : ms);
  }
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
  async function applyCandidate(index, item, confidence) {
    var row = state.rows[index], info = item.volumeInfo || {};
    var formatWasProvided = ['csv', 'manual'].indexOf(row.format_source) !== -1;
    if (!formatWasProvided) {
      var detectedFormat = inferFormat(item, info);
      row.format = detectedFormat || 'paperback';
      row.format_source = detectedFormat ? 'metadata' : 'default';
    }
    row.title = info.title || row.title;
    if (Array.isArray(info.authors) && info.authors.length) row.author = info.authors.join('; ');
    row.isbn = isbnFrom(item) || row.isbn || '';
    row.publisher = info.publisher || row.publisher || '';
    row.description = String(info.description || '').trim() || (isSynopsisPlaceholder(row.description) ? '' : row.description || '');
    if (['csv', 'manual'].indexOf(row.language_source) === -1) {
      var detectedLanguage = languageCategory(info.language || '');
      row.language = detectedLanguage || 'Other Languages';
      row.language_source = detectedLanguage ? 'metadata' : 'fallback';
    }
    var retail = item.saleInfo && item.saleInfo.listPrice;
    if (!String(row.unit_cost || '').trim() && (!String(row.price || '').trim() || row.price_source_type === 'fallback_baseline') && retail && String(retail.currencyCode || '').toUpperCase() === 'PHP' && Number.isFinite(Number(retail.amount)) && Number(retail.amount) > 0) {
      row.reference_price = Number(retail.amount);
      var markup = Number(row.markup_percent || 25);
      row.price = (Number(retail.amount) * (1 + markup / 100)).toFixed(2);
      row.price_source = 'Google Books PHP retail reference + ' + markup + '% markup';
      row.price_source_type = 'online_reference';
    }
    var metadataCover = coverFrom(item);
    row.cover_url = metadataCover || row.cover_url || '';
    if (metadataCover) row.cover_permission_confirmed = true;
    row.metadata_source_url = item.synopsisSourceUrl || item.volumeInfo && item.volumeInfo.infoLink || item.selfLink || '';
    if (row.cover_url) row.cover_source_url = row.metadata_source_url || row.cover_url;
    row.metadata_provider = item.synopsisSourceProvider || item.provider || 'Google Books';
    if (item.provider === 'Open Library') { row.open_library_checked = true; row.genre_lookup_complete = true; }
    if (item.synopsisSourceProvider === 'Open Library' || (item.provider === 'Open Library' && String(info.description || '').trim() && !item.synopsisSourceProvider)) row.open_library_synopsis_found = true;
    if (item.synopsisSourceProvider === 'Google Books' || (item.provider !== 'Open Library' && String(info.description || '').trim())) row.google_synopsis_found = true;
    row.lookup_status = confidence >= 90 ? 'High-confidence match' : 'Staff-confirmed match';
    var guesses = metadataGenreIds(info.categories || []);
    if (guesses.length && (!row.genre_ids.length || row.genre_assignment_source === 'fallback' || hasFallbackGenre(row))) { row.genre_ids = guesses; row.genre_assignment_source = 'metadata'; }
    else if (!row.genre_ids.length && row.genre_lookup_complete && Number(state.fallbackGenreId) > 0) { row.genre_ids = [Number(state.fallbackGenreId)]; row.genre_assignment_source = 'fallback'; }
    var source = container.querySelector('[data-source="' + index + '"]');
    if (source) source.textContent = (item.synopsisSourceUrl ? 'Synopsis source (' + row.metadata_provider + '): ' : 'Metadata source (' + row.metadata_provider + '): ') + row.metadata_source_url;
    renderRowFields(index);
    var priceSource = container.querySelector('[data-row="' + index + '"] .bulk-price-source');
    if (priceSource) priceSource.textContent = row.price_source || 'Uses cost + markup, an online PHP price + markup, or the fallback basis + markup.';
    updateRowHeading(index, confidence >= 90 ? 'matched' : 'suggestion', row.lookup_status);
    updateCover(index); refreshSummary(); checkCatalog(index); scheduleAuthorCheck(index);
    if (!formatWasProvided && row.format) setNotice(index, 'Format preselected as ' + row.format + (row.format === 'paperback' ? ' (default; verify the binding before import).' : ' from the metadata ebook signal.'), 'info');
    if (item.provider === 'Open Library' && !row.cover_url) fetchOpenLibraryCover(index, item);
    if (!String(row.description || '').trim()) await completeSynopsisFallback(index, item);
  }
  async function completeSynopsisFallback(index, selectedItem) {
    var row = state.rows[index];
    if (String(row.description || '').trim() && !isSynopsisPlaceholder(row.description)) return;
    if (isSynopsisPlaceholder(row.description)) { row.description = ''; renderRowFields(index); }
    if (row.google_books_checked && !row.open_library_checked) {
      var requestedTitle = row.title, requestedAuthor = row.author;
      var query = 'intitle:' + requestedTitle + (requestedAuthor ? '+inauthor:' + requestedAuthor.split(';')[0].trim() : '');
      setNotice(index, 'No synopsis found in Google Books. Checking Open Library…', 'info');
      try {
        var data = await googleBooksFetch(query, row, 'open_library');
        readRows();
        row = state.rows[index];
        if (row.title !== requestedTitle || row.author !== requestedAuthor) return;
        if (String(row.description || '').trim() && !isSynopsisPlaceholder(row.description)) { refreshSummary(); return; }
        row.open_library_checked = true;
        var candidates = (Array.isArray(data.items) ? data.items : []).filter(function (candidate) {
          var match = scoreCandidate(row, candidate);
          return match.exactTitle && match.exactAuthor && String((candidate.volumeInfo || {}).description || '').trim();
        }).sort(function (a, b) { return scoreCandidate(row, b).score - scoreCandidate(row, a).score; });
        var synopsis = candidates.length ? String(candidates[0].volumeInfo.description).trim() : '';
        row.open_library_synopsis_found = !!synopsis;
        if (synopsis) {
          row.description = synopsis;
          row.description_source = 'open_library';
          row.metadata_source_url = candidates[0].volumeInfo.infoLink || candidates[0].selfLink || row.metadata_source_url;
          row.metadata_provider = 'Open Library synopsis';
          var source = container.querySelector('[data-source="' + index + '"]');
          if (source) source.textContent = 'Synopsis source (Open Library): ' + row.metadata_source_url;
          setNotice(index, 'Synopsis filled from an exact title-and-author Open Library match. Review before publishing.', 'info');
        }
      } catch (_) {
        state.rows[index].open_library_lookup_failed = true;
        applySynopsisPlaceholder(state.rows[index]);
        renderRowFields(index);
        setNotice(index, 'No synopsis was available in the selected record. A placeholder was added; replace it with a trusted description before publishing.', 'warning');
        refreshSummary();
        return;
      }
    }
    row = state.rows[index];
    if (String(row.description || '').trim() && !isSynopsisPlaceholder(row.description)) { renderRowFields(index); refreshSummary(); return; }
    applySynopsisPlaceholder(row);
    renderRowFields(index);
    setNotice(index, 'No synopsis was available in the selected metadata. A placeholder was added; replace it with a trusted description before publishing.', 'warning');
    refreshSummary();
  }
  function applySynopsisPlaceholder(row) {
    row.description = synopsisPlaceholder;
    row.description_source = 'placeholder';
  }
  function showCandidates(index, items) {
    var box = container.querySelector('[data-online-candidates="' + index + '"]');
    box.textContent = '';
    if (!items.length) { setNotice(index, 'No metadata match found. Fill the required fields manually or skip this row.', 'warning'); return; }
    box.appendChild(el('h4', '', 'Choose the exact title and edition'));
    items.slice(0, 5).forEach(function (item) {
      var info = item.volumeInfo || {}, score = scoreCandidate(state.rows[index], item);
      var card = el('article', 'bulk-candidate' + (coverFrom(item) ? '' : ' bulk-candidate-no-cover'));
      var cover = coverFrom(item);
      if (cover) { var img = el('img'); img.src = cover; img.alt = ''; img.loading = 'lazy'; card.appendChild(img); }
      var details = el('div', 'bulk-candidate-info');
      details.appendChild(el('strong', 'bulk-candidate-title', info.title || 'Untitled result'));
      details.appendChild(el('small', 'bulk-candidate-provider', item.provider || 'Google Books'));
      details.appendChild(el('small', 'bulk-candidate-author', (info.authors || []).join(', ') || 'Author not listed'));
      details.appendChild(el('small', 'bulk-candidate-edition', [info.publisher, info.publishedDate, isbnFrom(item) ? 'ISBN ' + isbnFrom(item) : 'No ISBN'].filter(Boolean).join(' · ')));
      details.appendChild(el('small', 'bulk-confidence bulk-candidate-match', score.titleConflict ? 'Title conflict · do not auto-select' : score.score >= 90 ? 'High-confidence candidate · verify edition' : 'Possible match · compare details'));
      details.appendChild(el('small', 'bulk-candidate-signals', 'Match signals: ' + [score.isbnMatch ? 'ISBN exact' : '', score.exactTitle ? 'title exact' : '', score.exactAuthor ? 'author exact' : score.authorPartial ? 'author similar' : '', score.publisherMatch ? 'publisher exact' : ''].filter(Boolean).join(' · ') || 'no exact identifier/title/author signal'));
      var editionDetails = [info.printType ? info.printType.toLowerCase() : '', info.pageCount ? info.pageCount + ' pages' : '', (info.industryIdentifiers || []).filter(function (id) { return id.identifier; }).map(function (id) { return id.type.replace('_', '-') + ' ' + id.identifier; }).join(' · ')].filter(Boolean).join(' · ');
      if (editionDetails) details.appendChild(el('small', 'bulk-candidate-edition', editionDetails));
      if (info.description) details.appendChild(el('p', 'bulk-candidate-description', info.description));
      var previewUrl = googleBooksPreviewLink(item);
      if (previewUrl) {
        var previewLink = el('a', 'btn btn-link btn-sm', 'Preview book details'); previewLink.href = previewUrl; previewLink.target = '_blank'; previewLink.rel = 'noopener noreferrer'; details.appendChild(previewLink);
      } else details.appendChild(el('small', '', 'Google Books search link is unavailable for this result.'));
      if (score.titleConflict && !score.isbnMatch) {
        details.appendChild(el('p', 'bulk-candidate-conflict', 'This result has a different title, so it cannot be selected. Check another result or complete this row manually.'));
      } else {
        var buttonLabel = score.titleConflict ? 'Confirm ISBN Match' : 'Use This Record';
        var button = el('button', 'btn btn-secondary btn-sm', buttonLabel); button.type = 'button';
        button.addEventListener('click', async function () {
          if (score.titleConflict && !window.confirm('The ISBN matches, but the online title is different from the CSV title. Using this record will replace the CSV title with “' + (info.title || 'Untitled result') + '”. Continue only if you verified this is the same edition.')) return;
          await applyCandidate(index, item, score.score); box.textContent = '';
        });
        details.appendChild(button);
      }
      card.appendChild(details); box.appendChild(card);
    });
    setNotice(index, 'Select the exact book record. The system will not publish a low-confidence result automatically.', 'info');
  }
  async function lookupRow(index) {
    readRows(); var row = state.rows[index];
    if (row.skip || isExisting(row)) return;
    if (row.genre_assignment_source === 'fallback' && hasFallbackGenre(row)) {
      row.genre_ids = row.genre_ids.filter(function (id) { return Number(id) !== Number(state.fallbackGenreId); });
      row.genre_assignment_source = '';
      renderRowFields(index); refreshSummary();
    }
    row.open_library_checked = false;
    row.open_library_lookup_failed = false;
    row.genre_lookup_complete = false;
    row.google_books_checked = false;
    row.google_synopsis_found = false;
    row.open_library_synopsis_found = false;
    if (!row.title || !row.author) { setNotice(index, 'Enter both the book title and author before searching metadata.', 'warning'); return; }
    updateRowHeading(index, 'searching', 'Searching online…');
    setNotice(index, 'Searching Google Books for metadata suggestions…', 'info');
    var query = row.isbn ? 'isbn:' + row.isbn + ' intitle:' + row.title + (row.author ? ' inauthor:' + row.author.split(';')[0].trim() : '') : 'intitle:' + row.title + (row.author ? '+inauthor:' + row.author.split(';')[0].trim() : '');
    try {
      var data, items = [];
      try {
        data = await googleBooksFetch(query, row);
        row.google_books_checked = data.provider !== 'Open Library';
        items = Array.isArray(data.items) ? data.items : [];
      } catch (googleError) {
        // Google quota/network errors should trigger the configured Open Library fallback.
        try {
          data = await googleBooksFetch(query, row, 'open_library');
          row.open_library_checked = true;
          row.genre_lookup_complete = true;
          items = Array.isArray(data.items) ? data.items : [];
        } catch (_) { row.open_library_lookup_failed = true; throw googleError; }
      }
      if (data.provider === 'Open Library') row.open_library_checked = true;
      if (data.provider === 'Open Library') row.genre_lookup_complete = true;
      items.forEach(function (item) { item.provider = item.provider || data.provider || 'Google Books'; });
      if (data.provider === 'Open Library') {
        row.open_library_synopsis_found = items.some(function (item) {
          var match = scoreCandidate(row, item);
          return match.exactTitle && match.exactAuthor && !match.titleConflict && String((item.volumeInfo || {}).description || '').trim() !== '';
        });
      }
      if (!items.length && row.isbn) {
        data = await googleBooksFetch('intitle:' + row.title + (row.author ? '+inauthor:' + row.author.split(';')[0].trim() : ''), row);
        row.google_books_checked = data.provider !== 'Open Library';
        items = Array.isArray(data.items) ? data.items : [];
        items.forEach(function (item) { item.provider = item.provider || data.provider || 'Google Books'; });
      }
      var googleSynopsisItems = items.filter(function (item) { return item.provider !== 'Open Library'; });
      var googleSynopsisMatch = row.google_books_checked && googleSynopsisItems.some(function (item) {
        var score = scoreCandidate(row, item);
        return !score.titleConflict && score.exactTitle && score.exactAuthor && String((item.volumeInfo || {}).description || '').trim() !== '';
      });
      row.google_synopsis_found = googleSynopsisMatch;
      var googleSynopsisCandidate = googleSynopsisItems.find(function (item) {
        var score = scoreCandidate(row, item);
        return !score.titleConflict && score.exactTitle && score.exactAuthor && String((item.volumeInfo || {}).description || '').trim() !== '';
      });
      if (googleSynopsisCandidate) {
        googleSynopsisItems.forEach(function (candidate) {
          var score = scoreCandidate(row, candidate);
          if (score.exactTitle && score.exactAuthor && !score.titleConflict && !String((candidate.volumeInfo || {}).description || '').trim()) {
            candidate.volumeInfo.description = googleSynopsisCandidate.volumeInfo.description;
            candidate.synopsisSourceUrl = googleSynopsisCandidate.volumeInfo.infoLink || googleSynopsisCandidate.selfLink || '';
            candidate.synopsisSourceProvider = 'Google Books';
          }
        });
      }
      var googleGenreMatch = items.some(function (item) {
        var score = scoreCandidate(row, item);
        return !score.titleConflict && score.exactTitle && score.exactAuthor && metadataGenreIds((item.volumeInfo || {}).categories || []).length > 0;
      });
      row.genre_lookup_complete = row.genre_lookup_complete || googleGenreMatch;
      if (data.provider !== 'Open Library' && (!googleSynopsisMatch || !googleGenreMatch)) {
        try {
          var fallback = await googleBooksFetch(query, row, 'open_library');
          row.open_library_checked = true;
          row.genre_lookup_complete = true;
          var fallbackItems = Array.isArray(fallback.items) ? fallback.items : [];
          row.open_library_synopsis_found = fallbackItems.some(function (item) {
            var match = scoreCandidate(row, item);
            return match.exactTitle && match.exactAuthor && !match.titleConflict && String((item.volumeInfo || {}).description || '').trim() !== '';
          });
          fallbackItems.forEach(function (item) {
            item.provider = 'Open Library';
            var info = item.volumeInfo || {};
            var score = scoreCandidate(row, item);
            if (!score.exactTitle || !score.exactAuthor || score.titleConflict) return;
            var googleMatch = items.find(function (candidate) {
              var candidateScore = scoreCandidate(row, candidate);
              return candidateScore.exactTitle && candidateScore.exactAuthor && !candidateScore.titleConflict;
            });
            if (googleMatch) {
              var googleInfo = googleMatch.volumeInfo || {};
              if (String(info.description || '').trim() && !String(googleInfo.description || '').trim()) {
                googleInfo.description = info.description;
                googleMatch.synopsisSourceUrl = info.infoLink || item.selfLink || '';
                googleMatch.synopsisSourceProvider = 'Open Library';
              }
              if (!String(info.description || '').trim() && String(googleInfo.description || '').trim()) {
                info.description = googleInfo.description;
                item.synopsisSourceUrl = googleMatch.volumeInfo.infoLink || googleMatch.selfLink || '';
                item.synopsisSourceProvider = 'Google Books';
              }
              if (!metadataGenreIds(googleInfo.categories || []).length && metadataGenreIds(info.categories || []).length) {
                googleInfo.categories = info.categories;
                googleMatch.genreSourceProvider = 'Open Library';
              }
            }
          });
          items = items.concat(fallbackItems);
        } catch (_) { row.open_library_lookup_failed = true; /* Keep Google Books candidates visible if the fallback is unavailable. */ }
      }
      if (!items.length) {
        row.lookup_status = 'No result'; updateRowHeading(index, 'no-result', 'No result');
        if (row.genre_lookup_complete && Number(state.fallbackGenreId) > 0 && (!row.genre_ids || !row.genre_ids.length)) {
          row.genre_ids = [Number(state.fallbackGenreId)]; row.genre_assignment_source = 'fallback';
        }
        renderRowFields(index);
        if ((row.google_books_checked || row.open_library_checked) && !row.google_synopsis_found && !row.open_library_synopsis_found && (!String(row.description || '').trim() || isSynopsisPlaceholder(row.description))) {
          applySynopsisPlaceholder(row); renderRowFields(index);
          setNotice(index, 'No synopsis was found in the metadata checked. A placeholder was added; replace it with a trusted description before publishing.', 'warning');
        } else setNotice(index, data.fallback_notice || 'No matching records were found in Google Books or Open Library. Complete this row manually.', 'warning');
        showCandidates(index, []); refreshSummary(); return;
      }
      var seenCandidates = Object.create(null);
      items = items.filter(function (item) {
        var key = String(isbnFrom(item) || norm((item.volumeInfo || {}).title) + '|' + authorNorm(((item.volumeInfo || {}).authors || []).join('; '))).toUpperCase();
        if (seenCandidates[key]) return false; seenCandidates[key] = true; return true;
      });
      items.sort(function (a, b) { return scoreCandidate(row, b).score - scoreCandidate(row, a).score; });
      var best = scoreCandidate(row, items[0]);
      if (!best.titleConflict && (best.isbnMatch || (best.exactTitle && best.exactAuthor && best.score >= 90))) await applyCandidate(index, items[0], best.score);
      else {
        row.lookup_status = 'Needs match'; updateRowHeading(index, 'needs-review', 'Select a match'); showCandidates(index, items); refreshSummary();
        refreshSummary();
      }
    } catch (error) {
      row.lookup_status = 'Lookup unavailable'; updateRowHeading(index, 'lookup-error', 'Lookup unavailable');
      setNotice(index, 'Online lookup could not complete. ' + error.message + ' Complete this row manually.', 'warning');
      refreshSummary();
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
        var genreHint = container.querySelector('[data-genre-hint="' + index + '"]');
        if (genreHint) {
          var fallbackGenre = isDetectedFallbackGenre(row);
          genreHint.textContent = fallbackGenre ? 'Other / General is a demonstration fallback, not an identified genre. Replace it with a specific genre when known.' : row.genre_assignment_source === 'metadata' ? 'Suggested from the book’s catalog subjects. Adjust if needed.' : 'Choose one or more real genres. If sources do not identify one, select the best-supported category manually.';
          genreHint.classList.toggle('is-fallback', fallbackGenre);
        }
      } else if (key === 'description') {
        if (document.activeElement !== field && row[key] !== undefined) field.value = String(row[key]);
        updateSynopsisFallbackMarker(index, field);
      } else if (field.type === 'checkbox') field.checked = !!row[key];
      else if (field.tagName === 'SELECT' || document.activeElement !== field) {
        if (row[key] !== undefined) field.value = String(row[key]);
      }
      if (key === 'language') {
        var languageWrap = field.closest('.bulk-field');
        var languageHint = languageWrap && languageWrap.querySelector('.bulk-hint');
        var isLanguageFallback = row.language_source === 'fallback';
        if (languageWrap) languageWrap.classList.toggle('is-language-fallback', isLanguageFallback);
        if (languageHint) {
          languageHint.textContent = isLanguageFallback
            ? 'Language was not found in the metadata. “Other Languages” is a placeholder; choose the actual language when known.'
            : 'Automatically filled from book metadata when available. You can change it if needed.';
          languageHint.classList.toggle('is-fallback', isLanguageFallback);
        }
      }
    });
  }
  function updateSynopsisFallbackMarker(index, field) {
    field = field || container.querySelector('[data-row="' + index + '"][data-field="description"]');
    if (!field) return;
    var row = state.rows[index];
    var isPlaceholder = row.description_source === 'placeholder' && isSynopsisPlaceholder(row.description);
    var wrap = field.closest('.bulk-field');
    if (wrap) {
      wrap.classList.toggle('is-placeholder', isPlaceholder);
    }
    var hint = container.querySelector('[data-synopsis-hint="' + index + '"]');
    if (hint) hint.textContent = isPlaceholder
      ? 'Placeholder synopsis: replace it with an official or staff-written factual synopsis before publication.'
      : 'Use the publisher’s official synopsis or another trusted catalog description. If none is available, write a short factual summary; keep this row in Needs Details until it is complete.';
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
      setNotice(index, (matched.format_notice ? matched.format_notice + ' ' : '') + 'This book is already in the shop catalog. It is view-only here and will not be added or changed by this new-book import.' + (editionsSummary ? ' Catalog formats: ' + editionsSummary + '.' : ''), 'success');
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
    row.language = languageCategory(book.language || row.language || '');
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
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      var response;
      try {
        response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ action: 'match_many', csrf_token: state.csrf, rows: checks }), signal: controller.signal });
      } finally { clearTimeout(timeout); }
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
        var row = state.rows[index];
        var priceInput = container.querySelector('[data-row="' + index + '"][data-field="price"]');
        if (priceInput) priceInput.readOnly = row.unit_cost !== '';
        recalculatePrice(row, priceInput);
      }
      if (key === 'price') {
        state.rows[index].price_source_type = 'manual';
        state.rows[index].price_source = 'Website selling price entered by staff';
        var priceSource = container.querySelector('[data-row="' + index + '"] .bulk-price-source');
        if (priceSource) priceSource.textContent = state.rows[index].price_source;
      }
      if (key === 'cover_url' || key === 'title') updateCover(index);
      if (key === 'description') {
        if (!isSynopsisPlaceholder(target.value)) state.rows[index].description_source = 'manual';
        updateSynopsisFallbackMarker(index, target);
      }
      if (['title', 'author', 'isbn'].indexOf(key) !== -1) scheduleCatalogCheck(index);
      if (key === 'author') scheduleAuthorCheck(index);
      if (key === 'title' || key === 'author' || key === 'isbn') scheduleAutomaticLookup(index);
      updateRowHeading(index, state.rows[index].lookup_status || 'needs-details'); refreshSummary();
    });
    container.addEventListener('change', function (event) {
      if (event.target.dataset.field === 'genre_ids') { var genreIndex = Number(event.target.dataset.row); state.rows[genreIndex].genre_assignment_source = 'manual'; renderRowFields(genreIndex); refreshSummary(); }
      if (event.target.dataset.field === 'language') { var languageIndex = Number(event.target.dataset.row); state.rows[languageIndex].language_source = 'manual'; renderRowFields(languageIndex); refreshSummary(); }
      if (event.target.dataset.field === 'format') { state.rows[Number(event.target.dataset.row)].format_source = 'manual'; refreshSummary(); }
      if (event.target.type === 'checkbox') refreshSummary();
      if (event.target.dataset.field === 'format') scheduleCatalogCheck(Number(event.target.dataset.row));
      if (event.target.dataset.field === 'cover_file') {
        var index = Number(event.target.dataset.row), file = event.target.files && event.target.files[0];
        if (file) {
          state.rows[index].cover_file_present = true;
          updateUploadedCover(index, file);
        } else { state.rows[index].cover_file_present = false; updateUploadedCover(index, null); }
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
  function concurrentLookup(indices, onProgress) {
    var completed = 0;
    return Promise.all(indices.map(function (index) {
      return enqueueLookup(index).catch(function (error) {
        state.rows[index].lookup_status = 'Lookup unavailable';
        setNotice(index, 'Online lookup could not complete. ' + (error.message || 'Check this row manually.'), 'warning');
      }).then(function () {
        completed++;
        if (onProgress) onProgress(completed);
      });
    }));
  }
  function scheduleAutomaticLookup(index) {
    if (lookupTimers[index]) clearTimeout(lookupTimers[index]);
    lookupTimers[index] = setTimeout(function () {
      if (state.rows[index].skip) return;
      if (!autoMatchingInProgress) showScanStatus('Refreshing the metadata match for “' + state.rows[index].title + '”…', 'info');
      enqueueLookup(index).then(function () { if (!autoMatchingInProgress) dismissScanStatusAfter(5000); });
    }, 700);
  }

  state.rows.forEach(function (row) {
    if (!imported) {
      row.google_books_checked = false;
      row.open_library_checked = false;
      row.google_synopsis_found = false;
      row.open_library_synopsis_found = false;
      row.genre_lookup_complete = false;
    }
    if (isSynopsisPlaceholder(row.description) && !row.description_source) row.description_source = 'placeholder';
    // Older batches stored the fallback value but not its source marker. Keep
    // those rows visibly flagged instead of misclassifying the placeholder as
    // language supplied in the CSV.
    if (String(row.language || '').trim() && !row.language_source) {
      row.language_source = String(row.language).trim().toLowerCase() === 'other languages' ? 'fallback' : 'csv';
    }
    if (!String(row.language || '').trim() && !imported) { row.language = 'Other Languages'; row.language_source = 'fallback'; }
    if (String(row.format || '').trim() && !row.format_source) row.format_source = 'csv';
    if (!String(row.format || '').trim()) { row.format = 'paperback'; row.format_source = 'default'; }
    if (hasFallbackGenre(row) && !row.genre_assignment_source) row.genre_assignment_source = 'fallback';
  });
  state.rows.forEach(makeRow);
  state.rows.forEach(function (_, index) { renderRowFields(index); });
  attachInputListeners(); refreshSummary();
  state.rows.forEach(function (_, index) { renderPreparedAuthorCheck(index); });
  if (imported) progress.hidden = true;
  if (!imported) {
    autoMatchingInProgress = true;
    showScanStatus('Checking book titles and authors against the shop catalog…', 'info');
    checkCatalogBatch().then(function () {
      var pending = state.rows.map(function (row, index) { return index; }).filter(function (index) { return !state.rows[index].skip && !(state.rows[index].local_match && state.rows[index].lookup_status === 'Existing catalog book'); });
      if (pending.length) {
        progress.max = pending.length; progress.value = 0; progress.hidden = false;
        showScanStatus('Searching online metadata: 0 of ' + pending.length + ' books checked…', 'info');
        return concurrentLookup(pending, function (completed) {
          progress.value = completed;
          message.textContent = 'Searching online metadata: ' + completed + ' of ' + pending.length + ' books checked…';
        }).then(function () { showScanStatus('All ' + pending.length + ' books checked. Review matches and any missing details.', 'success'); });
      }
      progress.hidden = true;
      showScanStatus('Catalog checks complete. Existing records are shown below; review remaining details for new books.', 'success');
    }).catch(function (error) {
      progress.hidden = true;
      showScanStatus('Book checks finished with an issue. Review the row messages and try again.', 'warning');
      if (window.console && console.error) console.error('Bulk import checks failed:', error);
    }).then(function () { autoMatchingInProgress = false; dismissScanStatusAfter(5000); });
  }
  if (imported) { showScanStatus('This batch is already complete. Reloading it is safe and will not add stock again.', 'success'); dismissScanStatusAfter(5000); }
  importButton.addEventListener('click', async function () {
    var result = refreshSummary();
    if (!result.ready || result.duplicateCount) { showScanStatus('Complete the required fields and resolve duplicate ISBN rows before importing.', 'warning'); dismissScanStatusAfter(5000); return; }
    readRows(); importButton.disabled = true; progress.hidden = true; showScanStatus('Saving the batch safely…', 'info');
    try {
      var rowsJson = JSON.stringify(state.rows);
      var payload = new FormData(); payload.append('action', 'commit'); payload.append('batch_id', state.batchId); payload.append('rows_json', rowsJson); payload.append('csrf_token', state.csrf);
      var coverBytes = 0, oversizedCover = false;
      container.querySelectorAll('input[type="file"][data-field="cover_file"]').forEach(function (fileInput) {
        if (fileInput.files && fileInput.files[0]) { coverBytes += fileInput.files[0].size; if (fileInput.files[0].size > 1500000) oversizedCover = true; payload.append('cover_' + fileInput.dataset.row, fileInput.files[0]); }
      });
      if (oversizedCover || coverBytes + new Blob([rowsJson]).size > 6000000) { showScanStatus('Each uploaded cover must be 1.5 MB or smaller, and all batch data must stay below 6 MB. Resize covers or split a very large batch.', 'error'); dismissScanStatusAfter(5000); importButton.disabled = false; return; }
      var response = await fetch('/api/book_bulk_import.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: payload });
      var responseText = await response.text();
      var data;
      try { data = JSON.parse(responseText); }
      catch (_) { throw new Error('The server returned a non-JSON page (HTTP ' + response.status + '). You may have been redirected to login or PHP reported an error; check the PHP server terminal.'); }
      if (!response.ok || !data.success) {
        var issues = Array.isArray(data.issues) ? data.issues : [];
        issues.forEach(function (issue) {
          var issueIndex = Number(issue.index);
          setNotice(issueIndex, issue.message, 'warning');
          updateRowHeading(issueIndex, 'needs-review', 'Needs attention');
          var card = container.querySelector('[data-row-state="' + issueIndex + '"]');
          if (card) card.classList.add('is-needs-details');
        });
        if (issues.length) {
          var firstIssueIndex = Number(issues[0].index);
          var issueDetails = issues.slice(0, 3).map(function (issue) {
            return 'Book ' + (Number(issue.index) + 1) + ': ' + String(issue.message || 'Review this book.');
          });
          if (issues.length > 3) issueDetails.push('And ' + (issues.length - 3) + ' more book' + (issues.length - 3 === 1 ? '' : 's') + ' need attention.');
          showScanStatus(issueDetails.join(' '), 'error');
          var firstCard = container.querySelector('[data-row-state="' + firstIssueIndex + '"]');
          var firstDialog = firstCard && firstCard.querySelector('.bulk-row-dialog');
          if (firstDialog && !firstDialog.open) firstDialog.showModal();
        } else {
          showScanStatus(data.message || 'Import did not complete. Review the book details and try again.', 'error');
        }
        dismissScanStatusAfter(5000);
        importButton.disabled = false; return;
      }
      imported = true;
      window.location.assign('/admin/bulk_import.php?batch=' + encodeURIComponent(state.batchId));
    } catch (error) {
      showScanStatus('Import request failed: ' + (error.message || 'network error') + ' The batch is safe to retry after resolving the issue.', 'error'); dismissScanStatusAfter(5000); importButton.disabled = false;
    }
  });
})();
