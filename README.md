# Workflow Engine – Drupal Developer practical assessment

A configuration-driven workflow engine built as a custom Drupal module (`modules/workflow_engine`).
Admins build multi-step assessments (max 3 steps) from the UI with conditional questions and risk rules.
Visitors complete them and get a result; every submission is stored and can be reviewed by the admin.

## Prerequisites
- **Docker Desktop** installed and **running** (Windows / macOS), or Docker Engine with the Compose plugin (Linux).
  Check with: `docker --version` and `docker compose version`
- Port **8080** free on your machine (if not, change `"8080:80"` in `docker-compose.yml`).
- Internet access for the first start (Docker downloads the Drupal and MariaDB images).
- No PHP, Composer or database needs to be installed locally; everything runs in containers.

## Run it (Docker)

1. **Extract** the ZIP and open a terminal (PowerShell, Terminal or bash) **inside the `drupal-assessment` folder**, the one that contains `docker-compose.yml`.
2. **Start the containers:**
   ```bash
   docker compose up -d
   ```
   The first run downloads the images and takes a few minutes. Check that both containers are running with `docker compose ps`.
3. Open **http://localhost:8080**. The Drupal installer appears. Follow it:
   - Language: English, then profile: **Standard**
   - Database type: **MySQL, MariaDB, Percona Server**
   - Database name: `drupal`
   - Database username: `drupal`
   - Database password: `drupalpass`
   - Open **Advanced options** and set **Host** to `db` (this is the database container name; do not leave it as localhost)
   - Then create your admin account (any username and password you like) and finish. Installation takes about a minute.
4. **Enable the module:** log in, go to **Extend** (`/admin/modules`), search for "Workflow Engine", tick it and click **Install**.
   The sample **Property Lease Assessment** workflow is created automatically.
5. (Optional) Show the assessments on the home page: **Configuration → System → Basic site settings**
   (`/admin/config/system/site-information`) → Default front page = `/assessments`.

Troubleshooting:
- Page not loading: run `docker compose ps` and make sure both `drupal` and `db` are "running"; give it a minute.
- "Cannot connect to database": the Host under Advanced options must be `db`.
- Start from scratch: `docker compose down -v` (this deletes all data), then repeat from step 2.

## Where to look
| What | URL |
|---|---|
| Visitor side (list of published workflows) | `/assessments` (also linked in the main menu) |
| Create / edit / publish workflows | `/admin/structure/workflow-engine` |
| Submissions list and detail | `/admin/reports/workflow-engine/submissions` |

Useful commands:
```bash
docker compose down        # stop (data is kept)
docker compose down -v     # stop and delete all data for a clean start
```
If port 8080 is busy, change `"8080:80"` in `docker-compose.yml`.

## Contents
- `docker-compose.yml` – Drupal 10 (Apache) + MariaDB 10.11. The `modules` folder is mounted into `modules/custom`.
- `modules/workflow_engine/` – the custom module. Its own `README.md` and `SETUP.md` describe the design and a non-Docker install.

## Key technical decisions
- Custom module instead of Webform: risk rules, the 3-step limit and a simple builder for non-technical users would otherwise need custom handlers and overridden admin UI.
- Workflows are **config entities** (exportable with config sync); submissions are stored in a custom table.
- All rules live in one pure PHP class (`WorkflowLogic`) covered by unit tests.
- Conditions run in the browser (`#states`) and are re-checked on the server; hidden answers are never saved or scored.
- The builder's input is re-validated on the server; admin pages are permission-protected; the publish toggle is CSRF-protected; a result can only be opened by the visitor who submitted it (session-bound).
