Project Title: aklAAAt!: An Online Bookstore with Inventory and Payment Processing
Group Members: 
1. Franzcharl Alcantara - @pranscourledu 
2. Joshua Bautista - @Jshuo26
3. Clark Naduma - @nadumaclark-beep 
4. Almira Gwen Tabios - @miragwen

Project Description
The project is a digital platform designed to make Filipino-authored books and works from independent publishers more accessible, assorted, and automated. It provides users with a convenient way to discover and explore a diverse collection of Filipino literature and locally published works in one platform. The system also uses automated features to improve book organization, and access to relevant information. Aside from that, this features authors and books everyday to refresh and support our local writers. Overall, the project aims to support Filipino authors and independent publishers while making local literature easier for readers to discover.

Technologies Used: PHP 8, MySQL/MariaDB, HTML5, CSS3, JavaScript, PHPMailer, PayMongo (sandbox), reCAPTCHA.

Features: registration with reCAPTCHA, login/logout, MFA (OTP and Google Authenticator), role-based access (Admin/Staff/Customer), account lockout, product and category CRUD, search and filters, cart, checkout, order management, audit logs, Daily Spotlight, low-stock alerts.

Database: the database name (for example aklaaat_db) and the file location database/database.sql.

Installation / Setup:
Install XAMPP and start Apache and MySQL.
Copy the project into htdocs/.
Open phpMyAdmin, create the database, and import database/database.sql.
For an existing database that predates bulk book import, apply database/migrations/007_bulk_book_import.sql once before using the importer.
For existing databases, apply database/migrations/009_isbn_per_book_edition.sql after migration 007 to store ISBNs per format/edition.
Copy config/config.sample.php to config/config.php and enter local DB credentials and valid Google reCAPTCHA site and secret keys. Registration requires both reCAPTCHA keys.
Enter in terminal php -S localhost:8000
Open http://localhost:8000

Bulk book import is available to admin and staff from Product Management. Upload a UTF-8/Excel-compatible CSV of up to 500 rows; each row must include title and author. The importer maps common supplier heading variations, searches Google Books first, and uses Open Library as a fallback. Review metadata, edition ISBNs, required listing fields, and cover-use rights before importing. New/unverified authors remain hidden until approved through Author Reviews. Retrying a completed batch is idempotent. ISBN matches identify the same edition; exact title-and-author matches group formats under one catalog title, restocking the selected existing format or creating a separate format when needed. A conflicting ISBN for an already cataloged format is held for review. Cover files uploaded by staff are saved under images/book-covers.
