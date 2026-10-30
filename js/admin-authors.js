(() => {
  const state = window.adminAuthorLookupState || {};
  const editDialog = document.getElementById('author-edit-dialog');
  const detailsDialog = document.getElementById('author-details-dialog');
  const detailsContent = document.getElementById('author-details-content');
  let currentAuthorId = null;
  const byId = (id) => document.getElementById(id);
  const editName = byId('edit-author-name');
  const editBio = byId('edit-author-bio');
  const photoUrl = byId('edit-photo-url');
  const preview = byId('edit-photo-preview');
  const fileInput = byId('edit-author-file');
  const editForm = byId('author-edit-form');
  const results = byId('author-lookup-results');
  const message = byId('author-lookup-message');

  function setPreview(src) {
    preview.replaceChildren();
    if (!src) {
      const empty = document.createElement('span');
      empty.textContent = 'Portrait unavailable';
      preview.append(empty);
      return;
    }
    const image = document.createElement('img');
    image.src = src;
    image.alt = 'Selected author portrait';
    image.onerror = () => setPreview('');
    preview.append(image);
  }

  function closeDialogs() {
    if (editDialog.open) editDialog.close();
    if (detailsDialog.open) detailsDialog.close();
  }

  function openEdit(button) {
    byId('edit-author-id').value = button.dataset.id || '';
    editName.value = button.dataset.name || '';
    editBio.value = button.dataset.bio || '';
    photoUrl.value = '';
    fileInput.value = '';
    editDialog.querySelector('[name="image_confirmed"]').checked = false;
    setPreview(button.dataset.photo || '');
    results.replaceChildren();
    message.textContent = '';
    if (detailsDialog.open) detailsDialog.close();
    editDialog.showModal();
  }

  document.querySelectorAll('[data-edit-author]').forEach((button) => {
    button.addEventListener('click', () => openEdit(button));
  });

  function detailText(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = text;
    return element;
  }

  function openAuthorDetails(authorId) {
    const author = (state.authors || {})[String(authorId)];
    if (!author) return;
    currentAuthorId = String(authorId);
    detailsContent.replaceChildren();
    const intro = document.createElement('div');
    intro.className = 'author-detail-intro';
    if (author.photo) {
      const img = document.createElement('img');
      img.src = author.photo;
      img.alt = `Portrait of ${author.name}`;
      intro.append(img);
    } else {
      intro.append(detailText('div', 'author-detail-placeholder', (author.name || '?').split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase()));
    }
    const identity = document.createElement('div');
    identity.append(detailText('h3', '', author.name));
    identity.append(detailText('span', `admin-author-status status-${author.status}`, String(author.status || '').replaceAll('_', ' ')));
    intro.append(identity);
    detailsContent.append(intro);

    const bioSection = document.createElement('section');
    bioSection.className = 'author-detail-section';
    bioSection.append(detailText('h3', '', 'Biography'));
    bioSection.append(detailText('p', 'author-detail-bio', author.bio || 'No biography has been added yet.'));
    detailsContent.append(bioSection);

    const booksSection = document.createElement('section');
    booksSection.className = 'author-detail-section';
    const books = Array.isArray(author.books) ? author.books : [];
    booksSection.append(detailText('h3', '', `Available in the shop · ${books.length} ${books.length === 1 ? 'book' : 'books'}`));
    if (!books.length) {
      booksSection.append(detailText('p', 'author-detail-empty', 'No active, approved books are currently listed for this author.'));
    } else {
      const list = document.createElement('div');
      list.className = 'author-detail-books';
      books.forEach((book) => {
        const card = document.createElement('article');
        card.className = 'author-detail-book';
        const copy = document.createElement('div');
        copy.append(detailText('h4', '', book.title));
        const meta = [book.publisher, book.language, book.isbn ? `ISBN ${book.isbn}` : ''].filter(Boolean).join(' · ');
        if (meta) copy.append(detailText('p', 'author-detail-book-meta', meta));
        if (book.description) copy.append(detailText('p', 'author-detail-book-description', book.description));
        const editionList = document.createElement('div');
        editionList.className = 'author-detail-editions';
        (book.editions || []).forEach((edition) => {
          const stockLabel = Number(edition.stock) > 0 ? `${edition.stock} in stock` : 'Out of stock';
          editionList.append(detailText('span', 'author-detail-edition', `${edition.format} · ₱${Number(edition.price).toFixed(2)} · ${stockLabel}`));
        });
        card.append(copy, editionList);
        list.append(card);
      });
      booksSection.append(list);
    }
    detailsContent.append(booksSection);

    const evidenceSection = document.createElement('section');
    evidenceSection.className = 'author-detail-section';
    const evidence = Array.isArray(author.evidence) ? author.evidence : [];
    evidenceSection.append(detailText('h3', '', `Identity Evidence · ${evidence.length}`));
    if (!evidence.length) {
      evidenceSection.append(detailText('p', 'author-detail-empty', 'No evidence links have been recorded.'));
    } else {
      const evidenceList = document.createElement('ul');
      evidenceList.className = 'author-detail-evidence';
      evidence.forEach((item) => {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = item.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = item.url;
        li.append(link, detailText('small', '', item.date || ''));
        evidenceList.append(li);
      });
      evidenceSection.append(evidenceList);
    }
    detailsContent.append(evidenceSection);
    detailsDialog.showModal();
  }

  document.querySelectorAll('[data-author-id]').forEach((button) => {
    button.addEventListener('click', () => openAuthorDetails(button.dataset.authorId));
  });

  byId('edit-from-details').addEventListener('click', () => {
    const editButton = document.querySelector(`[data-edit-author][data-id="${CSS.escape(String(currentAuthorId))}"]`);
    if (editButton) openEdit(editButton);
  });

  document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', closeDialogs));
  [editDialog, detailsDialog].forEach((dialog) => dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  }));

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    photoUrl.value = '';
    setPreview(URL.createObjectURL(file));
  });

  editForm.addEventListener('submit', (event) => {
    const imageChosen = Boolean(photoUrl.value) || Boolean(fileInput.files && fileInput.files.length);
    const confirmed = editForm.querySelector('[name="image_confirmed"]').checked;
    if (imageChosen && !confirmed) {
      event.preventDefault();
      message.textContent = 'Confirm that the selected portrait is appropriate and permitted before saving.';
      editForm.querySelector('[name="image_confirmed"]').focus();
    }
  });

  if (state.edit && state.edit.id) {
    const cardButton = document.querySelector(`[data-edit-author][data-id="${CSS.escape(String(state.edit.id))}"]`);
    if (cardButton) {
      byId('edit-author-id').value = String(state.edit.id);
      editName.value = cardButton.dataset.name || '';
      editBio.value = state.edit.bio || '';
      photoUrl.value = state.edit.photo_url || '';
      editDialog.querySelector('[name="image_confirmed"]').checked = Boolean(state.edit.confirmed);
      setPreview(cardButton.dataset.photo || '');
      editDialog.showModal();
    }
  }

  function createLookupCard(title, description, buttonText, onSelect, source) {
    const card = document.createElement('article');
    card.className = 'author-lookup-card';
    const copy = document.createElement('div');
    const heading = document.createElement('strong');
    heading.textContent = title;
    const detail = document.createElement('p');
    detail.textContent = description;
    copy.append(heading, detail);
    if (source) {
      const link = document.createElement('a');
      link.href = source;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'View source';
      copy.append(link);
    }
    card.append(copy);
    if (buttonText && onSelect) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-secondary';
      button.textContent = buttonText;
      button.addEventListener('click', onSelect);
      card.append(button);
    }
    return card;
  }

  async function postLookup(payload) {
    const response = await fetch('/api/author_metadata.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ ...payload, csrf_token: state.csrf || '' }),
      credentials: 'same-origin',
    });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Author lookup could not complete.');
    return data;
  }

  byId('search-author-metadata').addEventListener('click', async () => {
    results.replaceChildren();
    message.textContent = 'Searching Open Library author profiles and Google Books references…';
    try {
      const data = await postLookup({ action: 'search', name: editName.value });
      message.textContent = data.message || 'Review the matching profiles before using their details.';
      const profileHeading = document.createElement('h4');
      profileHeading.textContent = 'Open Library author profiles';
      results.append(profileHeading);
      if (!data.open_library_authors.length) {
        const none = document.createElement('p');
        none.className = 'lookup-empty';
        none.textContent = 'No Open Library author profile was found. You can still update the biography or upload a portrait manually.';
        results.append(none);
      }
      for (const candidate of data.open_library_authors) {
        const card = createLookupCard(
          candidate.name || 'Unnamed author',
          `${candidate.work_count} listed works${candidate.top_work ? ` · Notable work: ${candidate.top_work}` : ''}`,
          'Load Profile',
          async () => {
            message.textContent = `Loading ${candidate.name}…`;
            try {
              const detail = await postLookup({ action: 'details', key: candidate.key });
              if (detail.bio) editBio.value = detail.bio;
              photoUrl.value = detail.photo_url || '';
              fileInput.value = '';
              setPreview(detail.photo_url || '');
              message.textContent = detail.bio || detail.photo_url
                ? 'Profile details loaded. Review and save the biography and portrait.'
                : 'This profile has no biography or portrait. You can add either manually.';
            } catch (error) {
              message.textContent = error.message;
            }
          },
          candidate.source_url,
        );
        results.append(card);
      }
      const worksHeading = document.createElement('h4');
      worksHeading.textContent = 'Google Books work references';
      results.append(worksHeading);
      if (!data.google_books_works.length) {
        const none = document.createElement('p');
        none.className = 'lookup-empty';
        none.textContent = data.google_books_available
          ? 'No matching work references found.'
          : 'Google Books is not configured; Open Library author search remains available.';
        results.append(none);
      }
      for (const work of data.google_books_works) {
        results.append(createLookupCard(work.title || 'Untitled work', (work.authors || []).join(', ') || 'Author not listed', '', null, work.source_url));
      }
    } catch (error) {
      message.textContent = error.message || 'Author lookup could not complete. Try again later.';
    }
  });
})();
