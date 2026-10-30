(() => {
  const dialog = document.getElementById('customer-author-dialog');
  const body = document.getElementById('customer-author-dialog-body');
  const closeButton = dialog?.querySelector('.customer-author-dialog-close');
  if (!dialog || !body || !closeButton) return;

  const make = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };

  const initials = (name) => name.trim().split(/\s+/).slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase()).join('') || '?';

  const renderProfile = (profile) => {
    body.replaceChildren();

    const overview = make('section', 'customer-author-overview');
    const portrait = make('div', 'customer-author-portrait');
    if (profile.photo) {
      const image = make('img');
      image.src = profile.photo;
      image.alt = `Portrait of ${profile.name}`;
      image.loading = 'lazy';
      image.onerror = () => {
        image.remove();
        portrait.textContent = initials(profile.name);
        portrait.classList.add('is-placeholder');
      };
      portrait.append(image);
    } else {
      portrait.textContent = initials(profile.name);
      portrait.classList.add('is-placeholder');
    }

    const identity = make('div', 'customer-author-identity');
    identity.append(make('h3', '', profile.name));
    identity.append(make('span', 'customer-author-verified', 'Verified Filipino Author'));

    const biography = make('div', 'customer-author-biography');
    biography.append(make('h3', '', 'Biography'));
    biography.append(make('p', '', profile.bio || 'Author biography is not available yet.'));

    overview.append(portrait, identity, biography);
    body.append(overview);

    const books = Array.isArray(profile.books) ? profile.books : [];
    const section = make('section', 'customer-author-books');
    const heading = make('div', 'customer-author-books-heading');
    heading.append(make('h3', '', 'Available in the Shop'));
    heading.append(make('span', '', `${books.length} ${books.length === 1 ? 'book' : 'books'}`));
    section.append(heading);

    if (!books.length) {
      section.append(make('p', 'customer-author-no-books', 'No books by this author are currently listed.'));
    } else {
      const grid = make('div', 'customer-author-book-grid');
      books.forEach((book) => {
        const link = make('a', 'customer-author-book-card');
        link.href = `/book.php?id=${encodeURIComponent(book.book_id)}`;
        link.setAttribute('aria-label', `View ${book.title}`);

        const cover = make('span', 'customer-author-book-cover');
        if (book.cover_image_path) {
          const image = make('img');
          image.src = book.cover_image_path;
          image.alt = `${book.title} cover`;
          image.loading = 'lazy';
          image.onerror = () => {
            image.remove();
            cover.textContent = book.title;
            cover.classList.add('is-placeholder');
          };
          cover.append(image);
        } else {
          cover.textContent = book.title;
          cover.classList.add('is-placeholder');
        }

        const details = make('span', 'customer-author-book-details');
        details.append(make('strong', '', book.title));
        const format = book.formats || 'Edition details available';
        const price = Number(book.min_price || 0).toLocaleString('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
        details.append(make('span', 'customer-author-book-meta', `${format} · ₱${price}`));
        details.append(make('span', 'customer-author-book-stock', `${Number(book.stock_total || 0)} in stock`));
        link.append(cover, details);
        grid.append(link);
      });
      section.append(grid);
    }

    body.append(section);
  };

  document.querySelectorAll('[data-author-profile]').forEach((card) => {
    card.addEventListener('click', () => {
      try {
        renderProfile(JSON.parse(card.dataset.authorProfile || '{}'));
        dialog.showModal();
      } catch (error) {
        console.error('Unable to open author profile.', error);
      }
    });
  });

  closeButton.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
})();
