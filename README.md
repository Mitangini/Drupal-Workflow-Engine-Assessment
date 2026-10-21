# Workflow Engine – Drupal Developer practical assessment

A configuration-driven workflow engine built as a custom Drupal module (`modules/workflow_engine`).
Admins build multi-step assessments (max 3 steps) from the UI with conditional questions and risk rules.
Visitors complete them and get a result; every submission is stored and can be reviewed by the admin.

## Run it (Docker)

Requirements: Docker Desktop (or Docker Engine with the Compose plugin).

1. Extract the ZIP and open a terminal in the `drupal-assessment` folder.
2. Start the containers:
   ```bash
   docker compose up -d
   ```
3. Open **http://localhost:8080** and run the Drupal installer:
   - Language: English, profile: **Standard**
   - Database type: **MySQL, MariaDB, Percona Server**
   - Database name: `drupal`, username: `drupal`, password: `drupalpass`
   - Open **Advanced options** and set host to `db`
   - Create your admin account (any username/password) and finish.
4. Enable the module: go to **Extend** (`/admin/modules`), search "Workflow Engine", tick it and click **Install**.
   The sample **Property Lease Assessment** workflow is created automatically.
5. (Optional) Make the assessments page the home page: **Configuration → System → Basic site settings**
   (`/admin/config/system/site-information`) → Default front page = `/assessments`.

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
