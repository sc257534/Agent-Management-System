# Agent Management System (AMS)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

A complete, single-file PHP and MySQL application for managing agents and their client applications. This system is designed for operational control, tracking payments, application status, and agent performance from a modern, responsive dashboard.

This project is built as a single-page application, routing all views and logic through `index.php`.

## Features

Based on the V3.0 redesign, this system includes:

### **Admin & Dashboard**
* **Secure Admin Login:** Login system with `password_hash` protected credentials.
* **KPI Dashboard:** A central dashboard with animated Key Performance Indicators (KPIs) for:
    * Total Active Agents
    * Pending Applications
    * 30-Day Revenue
    * Total Dues Owed
* **Data Visualization:**
    * **Application Velocity:** A 90-day line chart showing new applications over time.
    * **App Type Breakdown:** A doughnut chart showing the distribution of application types.
* **Critical Alerts Panel:** Automatically shows alerts for applications with outstanding dues and applications pending for more than 7 days.

### **Application Management**
* **Full CRUD:** Create, Read, Update, and Delete client applications.
* **Status Tracking:** Manage application status (Pending, Processing, Completed, Rejected) from a simple UI.
* **Financials:** Track `Total Cost`, `Total Paid`, and `Balance Due` for every application.
* **Payment Logging:** Record multiple payments against any application.
* **History & Timeline:** View a detailed, chronological timeline of all logs and payments for any application.

### **Agent Management**
* **Agent Profiles:** Create and manage agent profiles with contact info.
* **Agent Statistics:** The system automatically calculates and displays key stats for each agent:
    * Total Applications
    * Pending/Processing Apps
    * Total Value of Applications
    * Total Dues Owed by an Agent's clients
* **Safe Deletion:** Deleting an agent reassigns their applications to a default "Direct Applicant" agent to prevent data loss.

### **Security & Usability**
* **Inactivity Timeout:** Users are automatically logged out after 15 minutes of inactivity.
* **CSRF Protection:** All forms and delete actions are protected by CSRF tokens.
* **Live Search:** Instantly filter applications by applicant name using a live search bar.
* **Responsive Design:** A modern, dark-themed, and responsive UI that works on desktop and mobile devices.

## Technology Stack

* **Backend:** PHP 8+
* **Database:** MySQL / MariaDB
* **Frontend:**
    * HTML5 & CSS3 (Modern dark theme)
    * Vanilla JavaScript
* **Libraries:**
    * [Chart.js](https://www.chartjs.org/) for data visualization
    * [SweetAlert2](https://sweetalert2.github.io/) for modern alerts and confirmations
    * [Phosphor Icons](https://phosphoricons.com/) for UI icons

## Screenshots

*(Add your screenshots here!)*

| Dashboard View | Application Detail |
| :---: | :---: |
| <img src="" alt="Dashboard" width="400"> | <img src="" alt="Application Detail" width="400"> |

| Agents Page | Mobile View |
| :---: | :---: |
| <img src="" alt="Agents Page" width="400"> | <img src="" alt="Mobile View" width="400"> |


## Installation

1.  **Clone the Repository:**
    ```bash
    git clone [https://github.com/sc257534/agent-management-system.git](https://github.com/sc257534/agent-management-system.git)
    cd agent-management-system
    ```

2.  **Database Setup:**
    * Create a new database in your MySQL/MariaDB server (e.g., `ams_db`).
    * Import the `ams.sql` file into your new database.
    ```bash
    mysql -u YOUR_USERNAME -p YOUR_DATABASE_NAME < ams.sql
    ```

3.  **Configure the Application:**
    * Rename the `config.sample.php` file to `config.php`.
    * Edit `config.php` and fill in your database connection details (`servername`, `dbname`, `username`, `password`).

4.  **Run the Project:**
    * Place the project folder in your web server's root directory (e.g., `htdocs`, `www`).
    * Open the `index.php` file in your browser.

## Usage

After installation, you can log in using the default credentials:

* **Username:** `admin`
* **Password:** `password`

It is **highly recommended** to change your username and password immediately from the "Settings" page after your first login.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details.
