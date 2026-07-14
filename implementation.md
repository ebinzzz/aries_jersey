# PlayerKit & Jersey Registration System — Full Documentation & Source

A procedural PHP + MySQL player kit registration system. Admins can create multiple **reusable, independently named forms** (e.g. "U-16 Kit 2026", "Senior Women 2027"), each with its own shareable link, its own toggleable fields, and its own list of player registrations. Includes bulk Excel and PDF export.

## Stack
- HTML / CSS / vanilla JS (no frontend framework, no build step)
- PHP 7.4+ (plain procedural PHP + MySQLi — no Composer/framework required)
- MySQL / MariaDB

## Setup & Database Migrations (InfinityFree / Shared Hosting Friendly)

Since budget shared hosting (like InfinityFree) does not provide SSH/command-line access to run standard SQL commands, this project comes with a built-in **Web Migration Tracker and Setup Wizard**:

1. **DB Configuration**: Copy `.env.example` to `.env` and fill in your database credentials. If no `.env` file is present, the app will fallback to the hardcoded defaults in `includes/db_config.php`.
2. **Access Installer**: Visit `/admin/migrations.php` in your browser.
3. **Initialize Database**:
   - If the database doesn't exist, the wizard will offer to create it with one click.
   - It will check your connection environment, verify the directory, and run the pending initial migration (`database/migrations/0001_init.sql`).
   - This automatically seeds default teams (including "Kollam Sailors"), the dynamic fields catalog, and a default administrative account:
     - **Username**: `admin`
     - **Password**: `Admin@123`
4. **Admin Protection**: Once admin accounts exist, the migration tracker automatically locks itself, requiring admin credentials to run new updates or execute SQL.
5. **Alternative CLI Setup**: If you prefer CLI setup, you can import `database.sql` directly:
   
       mysql -u root -p < database.sql

## Automated Deployment (GitHub Actions to InfinityFree)

This project includes a fully automated deployment workflow using GitHub Actions. Pushes to the `main` branch trigger a workflow that lint-checks the codebase and deploys the files to InfinityFree via secure FTP.

### How it works:
1. **Quality Check**: The workflow checks out the repository, installs PHP, and lints all PHP files to verify there are no syntax errors.
2. **FTP Sync**: It automatically syncs only the production files to the InfinityFree server (excluding `.git`, local `.env`, raw databases, and large design assets like videos).

### How to set it up:
1. Go to your GitHub repository.
2. Navigate to **Settings** > **Secrets and variables** > **Actions**.
3. Under **Repository secrets**, click **New repository secret** and add:
   - `FTP_USERNAME`: Your InfinityFree FTP/Hosting Username (e.g., `if0_3821039`)
   - `FTP_PASSWORD`: Your InfinityFree FTP/Hosting Password (found in your InfinityFree client area)
4. When you push to the `main` branch, GitHub Actions will automatically deploy your code to the InfinityFree server.

## Branding & Styling Theme (Kollam Sailors)

The application implements the official branding theme of **Aries Kollam Sailors**:
- **Background**: Deep ocean navy-black (`#030712`).
- **Cards & Surfaces**: Dark slate blue (`#0b1528`).
- **Typography**: Poppins (for clean, legible body text) and Barlow Condensed (italicized, bold, uppercase headings representing sports-centric kit aesthetics).
- **Accents**: Dual-stripe red/coral (`#e11d48`) and royal blue (`#0066ff`) gradients on card headers, button borders, and navigation highlights.

## File Structure

    /
    ├── database.sql              (full schema + seed data — CLI fallback)
    ├── form.php                  (public registration form, ?slug=...)
    ├── database/
    │   └── migrations/
    │       └── 0001_init.sql     (initial table schema + default seed data)
    ├── includes/
    │   ├── db_config.php         (DB credentials — edit this)
    │   ├── auth.php              (session auth, CSRF helpers, admin checks)
    │   ├── form_helpers.php      (dynamic field query, uppercase format helper)
    │   ├── migration_runner.php  (migration file compiler and SQL console runner)
    │   ├── SimpleXlsxWriter.php  (dependency-free zip/XML .xlsx generator)
    │   └── SimplePdfWriter.php   (dependency-free binary .pdf table generator)
    ├── admin/
    │   ├── login.php / logout.php (admin portal access controls)
    │   ├── index.php             (dashboard: list forms, copy links, stats)
    │   ├── form_edit.php         (create/edit a form + select active fields)
    │   ├── registrations.php     (per-form submission list, search & deletes)
    │   ├── export_excel.php      (trigger .xlsx spreadsheet download)
    │   ├── export_pdf.php        (trigger .pdf table download)
    │   ├── admins.php            (manage backend admin logins & credentials)
    │   ├── teams.php             (manage the global team roster select options)
    │   ├── migrations.php        (shared hosting wizard & dev SQL panel)
    │   └── _partials/sidebar.php (dashboard layout navigation bar)
    └── assets/
        ├── css/style.css         (custom responsive theme variables & animations)
        └── js/form.js            (stepper transition, real-time validators)

## Technical Features

- **Stepper Navigation**: The public registration form uses a client-side multi-page stepper. If fields for Step 2 (personal details) or Step 3 (kit sizes) are disabled by the administrator, the system skips that step automatically.
- **Dynamic Field Catalog**: Administrators can toggle fields (Helmet Size, Jersey Name, Shorts Size, Trouser Size, Initials, Socks Size, Chest Size) as enabled or required. Required flags are enforced in client validations and verified serverside.
- **Uppercase Transforms**: Uniform print requirements are respected by converting the Jersey Name field to uppercase and filtering out non-alphabet characters in real-time.
- **Dependency-Free Exporters**:
  - `SimpleXlsxWriter.php` constructs the OpenXML workbook container (ZIP format) manually using binary `pack()` configurations without relying on the `ZipArchive` library.
  - `SimplePdfWriter.php` compiles Helvetica-Bold headers, zebra-striped rows, A4 pagination, and vector borders using manual PDF stream operators, keeping memory footprints light.
- **CSRF Token & Double Submission Prevention**: All admin operations verify session tokens. Public forms freeze submit buttons upon submission to prevent duplicate records.
