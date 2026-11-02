# campus-event-hub

PROJECT DESCRIPTION

Mini Campus Event Hub is a web-based campus event management system developed to help students discover, view, and register for campus events. It also provides administrators with tools to create and manage events, monitor student registrations, and record event attendance.
The system was developed using PHP, MySQL, PDO, HTML, CSS, and JavaScript. It can be accessed locally using XAMPP or online through InfinityFree hosting.


FEATURES

STUDENT FEATURES
Student Registration – Allows students to create an account.
Student Login – Allows registered students to securely access the system.
Browse Events – Displays available campus events.
View Event Details – Shows event descriptions, dates, schedules, venues, and other information.
Event Registration – Allows students to register for available events.
My Registrations – Allows students to view their event registrations and registration statuses.
Cancel Registration – Allows students to cancel their registrations when permitted.
QR Code Pass – Generates a QR code pass for event registration.
QR Code Download – Allows students to download their event QR code pass.

ADMINISTRATOR FEATURES
Admin Dashboard – Provides access to event management features.
Event Management – Allows administrators to create, edit, cancel, and delete events.
Registration Management – Allows administrators to view and manage student registrations.
Registration Confirmation – Allows administrators to confirm or update registration statuses.
Attendee Management – Displays students registered for an event.
QR Code Attendance – Allows administrators to scan student QR codes to record attendance.

SETUP INSTRUCTION

Follow these instructions to run the project locally using XAMPP.
System Requirements:
 - Windows operating system
 - XAMPP
 - PHP
 - MySQL or MariaDB
 - Web browser
   
Step 1: Install XAMPP
1. Download and install XAMPP.
2. Open the XAMPP Control Panel.
3. Start the Apache and MySQL services.

Step 2: Download the Project
1. Open the GitHub repository: https://github.com/ricsdelavega/campus-event-hub
2. Click the Code button.
3. Select Download ZIP.
4. Extract the downloaded ZIP file.

Step 3: Place the Project in the XAMPP Directory
1. Open the XAMPP installation directory.
2. Navigate to C:\xampp\htdocs.
3. Copy the extracted campus-event-hub folder into htdocs.
4. The project directory should be: C:\xampp\htdocs\campus-event-hub

Step 4: Configure the Database Connection
1. Open the project folder.
2. Navigate to config/database.php.
3. Configure the database connection using your local MySQL settings.
   
For a default XAMPP installation, use:

Host: localhost
Database Name: campus_event_hub
Username: root
Password: Leave blank if no MySQL password has been configured.

Use your local configuration file. If the repository contains database.example.php, copy it and rename the copy to database.php, then enter your local database credentials.

Step 5: Run the Project
1. Make sure Apache and MySQL are running in XAMPP.
2. Open your web browser.
3. Enter the following address: http://localhost/campus-event-hub/
4. The Mini Campus Event Hub homepage should appear.

DATABASE IMPORT SETUP

The SQL files are provided in the database folder. Import them using phpMyAdmin to create the database and its required tables.

Step 1: Open phpMyAdmin
1. Start Apache and MySQL in XAMPP.
2. Open your browser.
3. Visit: http://localhost/phpmyadmin

Step 2: Create the Database
1. Click New in the left sidebar.
2. Enter the database name: campus_event_hub
3. Select the utf8mb4_general_ci collation if available.
4. Click Create.

Step 3: Import the Main Database
1. Select the campus_event_hub database.
2. Click the Import tab.
3. Click Choose File.
4. Select the following file from the project folder: database/campus_event_hub.sql
5. Click Import or Go.
6. Wait for the successful import message.

Step 4: Import the QR Attendance Migration
1. Select the campus_event_hub database again.
2. Open the Import tab.
3. Choose: database/qr_attendance_migration.sql
4. Click Import or Go.
5. Wait for the import process to finish.

Step 5: Verify the Database
1. Select the campus_event_hub database in phpMyAdmin.
2. Check the tables displayed in the sidebar.
3. Make sure the database tables have been imported successfully.
4. The database is now ready to be used by the project.

DEPLOYMENT URL

The Campus Event Hub is deployed online using InfinityFree hosting.

Live Website: [https://campusconnect.page.gd]
GitHub Repository: https://github.com/ricsdelavega/campus-event-hub

The live website allows students and administrators to access the system through a web browser without installing XAMPP on their computers.
