DROP TEMPORARY TABLE IF EXISTS tmp_authors, tmp_books, tmp_bg, tmp_ba, tmp_be;

CREATE TEMPORARY TABLE tmp_authors AS
    SELECT full_name, bio, verification_status, verification_confidence,
           verification_method, verified_at
    FROM authors WHERE 1 = 0;

CREATE TEMPORARY TABLE tmp_books AS
    SELECT title, description, isbn FROM books WHERE 1 = 0;

CREATE TEMPORARY TABLE tmp_bg AS
    SELECT b.title AS title, g.genre_name AS genre_name
    FROM books b, genres g WHERE 1 = 0;

CREATE TEMPORARY TABLE tmp_ba AS
    SELECT b.title AS title, a.full_name AS full_name
    FROM books b, authors a WHERE 1 = 0;

CREATE TEMPORARY TABLE tmp_be AS
    SELECT b.title AS title, e.format AS format, e.price AS price,
           e.stock_quantity AS stock_quantity,
           e.low_stock_threshold AS low_stock_threshold
    FROM books b, book_editions e WHERE 1 = 0;

START TRANSACTION;

INSERT IGNORE INTO genres (genre_name, status) VALUES
    ('Classics', 'active'),
    ('Literary Fiction', 'active'),
    ('Historical Fiction', 'active'),
    ('Fantasy & Speculative Fiction', 'active'),
    ('Horror', 'active'),
    ('Young Adult', 'active'),
    ('Romance', 'active'),
    ('Graphic Novels & Comics', 'active'),
    ('Children''s Literature', 'active'),
    ('Poetry', 'active'),
    ('History', 'active'),
    ('Food & Culture', 'active'),
    ('Mystery & Crime', 'active'),
    ('Contemporary Fiction', 'active');

INSERT INTO tmp_authors (full_name, bio, verification_status, verification_confidence, verification_method, verified_at) VALUES
    ('Jose Rizal', 'Filipino national hero (1861-1896), physician and writer; author of the novels Noli Me Tangere and El Filibusterismo.', 'verified', 100.00, 'external', NOW()),
    ('Francisco Balagtas', 'Tagalog poet (1788-1862) born in Bigaa, Bulacan; best known for the awit Florante at Laura (1838).', 'verified', 100.00, 'external', NOW()),
    ('Severino Reyes', 'Filipino writer best known as the creator of the Lola Basyang stories.', 'verified', 100.00, 'external', NOW()),
    ('Lualhati Bautista', 'Filipina novelist and screenwriter (1945-2023) born in Tondo, Manila; Palanca Award winner for ''Gapô, Dekada ''70 and Bata, Bata... Pa''no Ka Ginawa?', 'verified', 100.00, 'external', NOW()),
    ('Amado V. Hernandez', 'Filipino writer and labor leader (1903-1970) born in Tondo, Manila; author of Mga Ibong Mandaragit and Luha ng Buwaya.', 'verified', 100.00, 'external', NOW()),
    ('Nick Joaquin', 'Filipino writer (1917-2004), widely considered the most important Filipino writer in English.', 'verified', 100.00, 'external', NOW()),
    ('F. Sionil Jose', 'Filipino novelist (1924-2022) born in Rosales, Pangasinan; author of the Rosales Saga and founder of Solidaridad Publishing House.', 'verified', 100.00, 'external', NOW()),
    ('Dean Francis Alfar', 'Filipino playwright, novelist and speculative fiction writer born in 1969; Palanca Grand Prize winner for the novel Salamanca.', 'verified', 100.00, 'external', NOW()),
    ('Yvette Tan', 'Filipino horror writer based in Manila; Palanca Award-winning author of Waking the Dead and Other Stories.', 'verified', 95.00, 'external', NOW()),
    ('Edgar Calabia Samar', 'Filipino author of the Janus Silang series; winner of the 2015 National Book Award for Novel in Filipino.', 'verified', 95.00, 'external', NOW()),
    ('Budjette Tan', 'Filipino komiks writer; co-creator of the Trese series with Kajo Baldisimo.', 'verified', 100.00, 'external', NOW()),
    ('Kajo Baldisimo', 'Filipino komiks artist; co-creator and illustrator of the Trese series.', 'verified', 95.00, 'external', NOW()),
    ('Ambeth R. Ocampo', 'Filipino historian and writer, born 1961; author of the Looking Back column and Rizal Without the Overcoat.', 'verified', 100.00, 'external', NOW()),
    ('Doreen G. Fernandez', 'Filipino writer, teacher, cultural historian and food critic (1934-2002).', 'verified', 100.00, 'external', NOW()),
    ('Reynaldo C. Ileto', 'Filipino historian born in 1946, educated at the Ateneo de Manila and Cornell; author of Pasyon and Revolution.', 'verified', 95.00, 'external', NOW()),
    ('Bianca Bernardino', 'Filipino author of She''s Dating the Gangster, first posted online and then published by Summit Books'' Pop Fiction imprint.', 'verified', 95.00, 'external', NOW()),
    ('Christine S. Bellen', 'Filipino writer who retold Severino Reyes''s Lola Basyang stories for Anvil Publishing''s picture-book series.', 'verified', 95.00, 'external', NOW()),
    ('F. H. Batacan', 'Filipino writer and author of Smaller and Smaller Circles.', 'verified', 100.00, 'external', NOW()),
    ('Jose Y. Dalisay Jr.', 'Filipino writer, novelist, and academic; author of Soledad''s Sister.', 'verified', 100.00, 'external', NOW()),
    ('Ricky Lee', 'Filipino screenwriter, novelist, and writer; author of Para Kay B.', 'verified', 100.00, 'external', NOW());

INSERT INTO authors (full_name, bio, verification_status, verification_confidence, verification_method, verified_at)
SELECT t.full_name, t.bio, t.verification_status, t.verification_confidence, t.verification_method, t.verified_at
FROM tmp_authors t
WHERE NOT EXISTS (SELECT 1 FROM authors a WHERE a.full_name = t.full_name);

INSERT INTO tmp_books (title, description, isbn) VALUES
    ('Noli Me Tangere', 'Jose Rizal''s 1887 novel, originally written in Spanish and widely considered the foundational novel of the Philippines.', NULL),
    ('El Filibusterismo', 'Rizal''s 1891 sequel to Noli Me Tangere, originally written in Spanish, picking up the story thirteen years later as a tale of obsession and revenge.', NULL),
    ('Dekada ''70', 'Amanda Bartolome, wife of Julian and mother of five sons, confronts the violence of the martial law years as her family is pulled into the struggle.', '9789712733987'),
    ('Bata, Bata... Pa''no Ka Ginawa?', 'The story of Lea, a working mother and social activist raising two children.', NULL),
    ('Gapo', 'A novel about Michael, the son of a bar girl in Olongapo, set against the American presence in the Philippines.', NULL),
    ('Luha ng Buwaya', 'Set in the town of Sampilong under the rule of Dona Leona and Don Severo Grande; drawn from Hernandez''s experience as a guerrilla, labor leader and political detainee.', '9789715502528'),
    ('The Woman Who Had Two Navels', 'Connie Escobar is convinced she has two navels, and her search for answers leads her from Manila to Hong Kong and to a family secret.', '9789712738456'),
    ('Po-on', 'The first book of the Rosales Saga, following a family''s journey to Rosales, Pangasinan during the years of the Philippine revolution.', '9789715552677'),
    ('Tree', 'A Rosales Saga novel told by the loving but uneasy son of a land overseer, about class and the search for belonging.', '9789718845141'),  -- check blurb
    ('My Brother, My Executioner', 'A Rosales Saga novel about two brothers on opposite sides of the city and the countryside, and the misfortunes that follow them.', '9789718845165'),  -- check blurb
    ('A Field Guide to the Roads of Manila and Other Stories', 'Fifteen stories of fantasy, science fiction and horror in which the real and the unreal intersect.', '9789712731501'),
    ('Salamanca', 'Fantasy novel by Dean Francis Alfar, winner of the Grand Prize for Novel at the 2005 Palanca Awards.', NULL),
    ('Waking the Dead and Other Stories', 'Ten stories of Filipino horror that blend folk beliefs with modern settings, including the Palanca-winning Kulog and Sidhi.', '9789712737022'),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'After a TALA Online tournament ends with every player dead except Janus, he is drawn into the legend of the Tiyanak of Tabon. National Book Award winner.', '9789715084741'),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'A book in the Janus Silang series, a young adult fantasy built on Philippine folklore.', '9789715085663'),
    ('Si Janus Silang at ang Pitumpu''t Pitong Pusong', 'A book in the Janus Silang series, a National Children''s Book Awards Best Read for Kids.', '9789715086226'),  -- check award claim
    ('She''s Dating the Gangster', 'Athena Dizon pretends to be the girlfriend of school heartthrob Kenji de los Reyes, and the arrangement gets complicated.', NULL),
    ('Trese: Murder on Balete Drive', 'When crime in Manila turns supernatural, the police call Alexandra Trese. The first collection of the Filipino komiks series.', '9789719257479'),
    ('Trese: Unreported Murders', 'The second collection of the Trese komiks series, following Alexandra Trese''s investigations of supernatural crimes in Manila.', '9789719257493'),
    ('Anting-anting (The Amulet)', 'A bilingual Filipino-English picture book retelling of a Lola Basyang tale by Severino Reyes.', '9789712718939'),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'A bilingual Filipino-English picture book retelling of a Lola Basyang tale by Severino Reyes.', '9789712718953'),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'A bilingual Filipino-English picture book retelling of a Lola Basyang tale by Severino Reyes.', '9789712718946'),
    ('Florante at Laura', 'Balagtas''s 1838 awit about Duke Florante of Albania, tied to a tree and awaiting rescue; this edition carries annotations by Virgilio S. Almario.', '9789715084772'),
    ('Rizal Without the Overcoat', 'Essays from Ambeth Ocampo''s Looking Back column that strip away the myths around Jose Rizal. 35th anniversary edition.', '9789712739194'),
    ('Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'A history written from below, using the pasyon as the cultural framework of Philippine popular movements. Winner of the Harry Benda and Masayoshi Ohira prizes.', '9789715502320'),
    ('Tikim: Essays on Philippine Food and Culture', 'Essays on Filipino food, flavors, people, places and history by Doreen Fernandez. Revised and updated edition.', '9789712735639'),
    ('Smaller and Smaller Circles', 'A crime mystery set in the Philippines involving two Jesuit priests investigating a series of murders.', NULL),
    ('Soledad''s Sister', 'A Filipino novel exploring family, migration, identity, and the search for truth.', NULL),
    ('Para Kay B', 'A Filipino novel exploring love, relationships, and the different ways people experience romance.', NULL);

INSERT INTO books (title, description, isbn)
SELECT t.title, t.description, t.isbn
FROM tmp_books t
WHERE NOT EXISTS (SELECT 1 FROM books b WHERE b.title = t.title)
  AND (t.isbn IS NULL OR NOT EXISTS (SELECT 1 FROM books b2 WHERE b2.isbn = t.isbn));

INSERT INTO tmp_bg (title, genre_name) VALUES
    ('Noli Me Tangere', 'Classics'),
    ('Noli Me Tangere', 'Historical Fiction'),
    ('Noli Me Tangere', 'Literary Fiction'),
    ('El Filibusterismo', 'Classics'),
    ('El Filibusterismo', 'Historical Fiction'),
    ('El Filibusterismo', 'Literary Fiction'),
    ('Dekada ''70', 'Literary Fiction'),
    ('Dekada ''70', 'Historical Fiction'),
    ('Bata, Bata... Pa''no Ka Ginawa?', 'Literary Fiction'),
    ('Bata, Bata... Pa''no Ka Ginawa?', 'Contemporary Fiction'),
    ('''Gapô', 'Literary Fiction'),
    ('''Gapô', 'Historical Fiction'),
    ('Luha ng Buwaya', 'Literary Fiction'),
    ('Luha ng Buwaya', 'Historical Fiction'),
    ('The Woman Who Had Two Navels', 'Literary Fiction'),
    ('The Woman Who Had Two Navels', 'Classics'),
    ('Po-on', 'Historical Fiction'),
    ('Po-on', 'Literary Fiction'),
    ('Tree', 'Historical Fiction'),
    ('Tree', 'Literary Fiction'),
    ('My Brother, My Executioner', 'Historical Fiction'),
    ('My Brother, My Executioner', 'Literary Fiction'),
    ('A Field Guide to the Roads of Manila and Other Stories', 'Fantasy & Speculative Fiction'),
    ('A Field Guide to the Roads of Manila and Other Stories', 'Horror'),
    ('Salamanca', 'Fantasy & Speculative Fiction'),
    ('Salamanca', 'Literary Fiction'),
    ('Waking the Dead and Other Stories', 'Horror'),
    ('Waking the Dead and Other Stories', 'Literary Fiction'),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'Young Adult'),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'Fantasy & Speculative Fiction'),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'Horror'),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'Young Adult'),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'Fantasy & Speculative Fiction'),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'Horror'),
    ('Si Janus Silang at ang Pitumpu''t Pitong Pusong', 'Young Adult'),
    ('Si Janus Silang at ang Pitumpu''t Pitong Pusong', 'Fantasy & Speculative Fiction'),
    ('She''s Dating the Gangster', 'Romance'),
    ('She''s Dating the Gangster', 'Young Adult'),
    ('Trese: Murder on Balete Drive', 'Graphic Novels & Comics'),
    ('Trese: Murder on Balete Drive', 'Horror'),
    ('Trese: Murder on Balete Drive', 'Fantasy & Speculative Fiction'),
    ('Trese: Unreported Murders', 'Graphic Novels & Comics'),
    ('Trese: Unreported Murders', 'Horror'),
    ('Trese: Unreported Murders', 'Fantasy & Speculative Fiction'),
    ('Anting-anting (The Amulet)', 'Children''s Literature'),
    ('Anting-anting (The Amulet)', 'Fantasy & Speculative Fiction'),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'Children''s Literature'),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'Fantasy & Speculative Fiction'),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'Children''s Literature'),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'Fantasy & Speculative Fiction'),
    ('Florante at Laura', 'Poetry'),
    ('Florante at Laura', 'Classics'),
    ('Florante at Laura', 'Historical Fiction'),
    ('Rizal Without the Overcoat', 'History'),
    ('Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'History'),
    ('Tikim: Essays on Philippine Food and Culture', 'Food & Culture'),
    ('Tikim: Essays on Philippine Food and Culture', 'History'),
    ('Smaller and Smaller Circles', 'Mystery & Crime'),
    ('Smaller and Smaller Circles', 'Literary Fiction'),
    ('Soledad''s Sister', 'Literary Fiction'),
    ('Soledad''s Sister', 'Contemporary Fiction'),
    ('Para Kay B', 'Romance'),
    ('Para Kay B', 'Literary Fiction');

INSERT IGNORE INTO book_genres (book_id, genre_id)
SELECT b.book_id, g.genre_id
FROM tmp_bg t
JOIN books  b ON b.title = t.title
JOIN genres g ON g.genre_name = t.genre_name;

INSERT INTO tmp_ba (title, full_name) VALUES
    ('Noli Me Tangere', 'José Rizal'),
    ('El Filibusterismo', 'José Rizal'),
    ('Dekada ''70', 'Lualhati Bautista'),
    ('Bata, Bata... Pa''no Ka Ginawa?', 'Lualhati Bautista'),
    ('''Gapô', 'Lualhati Bautista'),
    ('Luha ng Buwaya', 'Amado V. Hernandez'),
    ('The Woman Who Had Two Navels', 'Nick Joaquin'),
    ('Po-on', 'F. Sionil José'),
    ('Tree', 'F. Sionil José'),
    ('My Brother, My Executioner', 'F. Sionil José'),
    ('A Field Guide to the Roads of Manila and Other Stories', 'Dean Francis Alfar'),
    ('Salamanca', 'Dean Francis Alfar'),
    ('Waking the Dead and Other Stories', 'Yvette Tan'),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'Edgar Calabia Samar'),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'Edgar Calabia Samar'),
    ('Si Janus Silang at ang Pitumpu''t Pitong Pusong', 'Edgar Calabia Samar'),
    ('She''s Dating the Gangster', 'Bianca Bernardino'),
    ('Trese: Murder on Balete Drive', 'Budjette Tan'),
    ('Trese: Murder on Balete Drive', 'Kajo Baldisimo'),
    ('Trese: Unreported Murders', 'Budjette Tan'),
    ('Trese: Unreported Murders', 'Kajo Baldisimo'),
    ('Anting-anting (The Amulet)', 'Severino Reyes'),
    ('Anting-anting (The Amulet)', 'Christine S. Bellen'),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'Severino Reyes'),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'Christine S. Bellen'),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'Severino Reyes'),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'Christine S. Bellen'),
    ('Florante at Laura', 'Francisco Balagtas'),
    ('Rizal Without the Overcoat', 'Ambeth R. Ocampo'),
    ('Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'Reynaldo C. Ileto'),
    ('Tikim: Essays on Philippine Food and Culture', 'Doreen G. Fernandez'),
    ('Smaller and Smaller Circles', 'F. H. Batacan'),
    ('Soledad''s Sister', 'Jose Y. Dalisay Jr.'),
    ('Para Kay B', 'Ricky Lee');

INSERT IGNORE INTO book_authors (book_id, author_id)
SELECT b.book_id, a.author_id
FROM tmp_ba t
JOIN books   b ON b.title = t.title
JOIN authors a ON a.full_name = t.full_name;

INSERT INTO tmp_be (title, format, price, stock_quantity, low_stock_threshold) VALUES
    ('Noli Me Tangere', 'paperback', 350.00, 40, 5),
    ('El Filibusterismo', 'paperback', 350.00, 38, 5),
    ('Dekada ''70', 'paperback', 350.00, 25, 5),
    ('Bata, Bata... Pa''no Ka Ginawa?', 'paperback', 350.00, 18, 5),
    ('''Gapô', 'paperback', 350.00, 14, 5),
    ('Luha ng Buwaya', 'paperback', 260.00, 22, 5),
    ('The Woman Who Had Two Navels', 'paperback', 630.00, 12, 5),
    ('Po-on', 'paperback', 450.00, 9, 5),
    ('Tree', 'paperback', 450.00, 4, 5),                       -- low stock (test data)
    ('My Brother, My Executioner', 'paperback', 450.00, 5, 5), -- stock = threshold
    ('A Field Guide to the Roads of Manila and Other Stories', 'paperback', 495.00, 16, 5),
    ('Salamanca', 'paperback', 495.00, 11, 5),
    ('Waking the Dead and Other Stories', 'paperback', 425.00, 20, 5),
    ('Waking the Dead and Other Stories', 'ebook', 199.00, 999, 5),
    ('Si Janus Silang at ang Tiyanak ng Tabon', 'paperback', 249.00, 30, 5),
    ('Si Janus Silang at ang Labanang Manananggal-Mambabarang', 'paperback', 249.00, 24, 5),
    ('Si Janus Silang at ang Pitumpu''t Pitong Pusong', 'paperback', 295.00, 3, 5),   -- low stock (test data)
    ('She''s Dating the Gangster', 'paperback', 195.00, 35, 5),
    ('Trese: Murder on Balete Drive', 'paperback', 395.00, 3, 5),                    -- low stock (test data)
    ('Trese: Unreported Murders', 'paperback', 395.00, 0, 5),                        -- out of stock (test data)
    ('Anting-anting (The Amulet)', 'paperback', 220.00, 15, 5),
    ('Ang Palasyo ng mga Duwende (The Palace of the Dwarves)', 'paperback', 220.00, 15, 5),
    ('Ang Kapatid ng Tatlong Marya (The Brother of Three Marias)', 'paperback', 220.00, 6, 5),
    ('Florante at Laura', 'paperback', 150.00, 50, 5),
    ('Rizal Without the Overcoat', 'paperback', 395.00, 21, 5),
    ('Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'paperback', 460.00, 8, 5),
    ('Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'ebook', 350.00, 999, 5),
    ('Tikim: Essays on Philippine Food and Culture', 'paperback', 600.00, 10, 5),
    ('Smaller and Smaller Circles', 'paperback', 495.00, 12, 5),
    ('Soledad''s Sister', 'paperback', 450.00, 10, 5),
    ('Para Kay B', 'paperback', 295.00, 15, 5);

INSERT IGNORE INTO book_editions (book_id, format, price, stock_quantity, low_stock_threshold, cover_image_path)
SELECT b.book_id, t.format, t.price, t.stock_quantity, t.low_stock_threshold, NULL
FROM tmp_be t
JOIN books b ON b.title = t.title;

UPDATE books b
   SET b.status = 'active', b.review_status = 'approved'
 WHERE EXISTS (SELECT 1 FROM book_genres bg WHERE bg.book_id = b.book_id)
   AND NOT EXISTS (
       SELECT 1 FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id
        WHERE ba.book_id = b.book_id AND a.verification_status <> 'verified'
   );

COMMIT;

SELECT
    (SELECT COUNT(*) FROM tmp_authors t
        LEFT JOIN authors a ON a.full_name = t.full_name
        WHERE a.author_id IS NULL)                                   AS authors_missing,
    (SELECT COUNT(*) FROM tmp_books t
        LEFT JOIN books b ON b.title = t.title
        WHERE b.book_id IS NULL)                                     AS books_missing,
    (SELECT COUNT(*) FROM tmp_bg t
        LEFT JOIN books b  ON b.title = t.title
        LEFT JOIN genres g ON g.genre_name = t.genre_name
        LEFT JOIN book_genres x ON x.book_id = b.book_id AND x.genre_id = g.genre_id
        WHERE x.book_id IS NULL)                                     AS genre_links_missing,
    (SELECT COUNT(*) FROM tmp_ba t
        LEFT JOIN books b   ON b.title = t.title
        LEFT JOIN authors a ON a.full_name = t.full_name
        LEFT JOIN book_authors x ON x.book_id = b.book_id AND x.author_id = a.author_id
        WHERE x.book_id IS NULL)                                     AS author_links_missing,
    (SELECT COUNT(*) FROM tmp_be t
        LEFT JOIN books b ON b.title = t.title
        LEFT JOIN book_editions e ON e.book_id = b.book_id AND e.format = t.format
        WHERE e.edition_id IS NULL)                                  AS editions_missing,
    (SELECT COUNT(*) FROM (SELECT title FROM books
        GROUP BY title HAVING COUNT(*) > 1) d)                       AS duplicate_titles,
    (SELECT COUNT(*) FROM (SELECT full_name FROM authors
        GROUP BY full_name HAVING COUNT(*) > 1) d)                   AS duplicate_authors;

DROP TEMPORARY TABLE IF EXISTS tmp_authors, tmp_books, tmp_bg, tmp_ba, tmp_be;
