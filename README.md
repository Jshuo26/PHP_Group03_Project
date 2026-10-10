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
Copy config.sample.php to config.php and enter local DB credentials and valid Google reCAPTCHA site and secret keys. Registration requires both reCAPTCHA keys.
Enter in terminal php -S localhost:8000
Open http://localhost:8000 

Customers can search and filter books, manage their cart, update account details, place orders, and view order history. Admin and staff accounts can manage books, genres, and order statuses from the Admin link. The existing schema already contains the tables used for these features; no migration is required.
