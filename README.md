# 🎲 export.php – Quick Usage Guide

1. **Environment setup**  
   - (Optional) place a `.env` file alongside [export.php](export.php) with keys like `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.  
   - If no .env exists, those variables must already be available in `$_ENV`/`getenv`.

2. **Dependencies**  
   - PHP 7.4+ (works fine on PHP 8).  
   - PDO extensions for MySQL and SQLite enabled (`pdo_mysql`, `pdo_sqlite`).  
   - A MySQL database accessible with the credentials above.

3. **Running the exporter**  
   ```bash
   php export.php [optional/output/path.sqlite]
   ```  
   - Without arguments the tool writes to `database/database-export.sqlite` (overwriting any existing file).  
   - To choose another location, pass it as the first argument; directories are created if missing.

4. **What it does**  
   - Recreates every table from the MySQL database inside the SQLite file, mapping types and default values automatically.  
   - Copies row data for all tables **except** those whose name contains `telescope` and the `audits` table (schemas are still created, only data is skipped).
