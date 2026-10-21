# Quick setup (DDEV, works on Windows via WSL2)

```bash
mkdir drupal-test && cd drupal-test
ddev config --project-type=drupal --docroot=web
ddev composer create drupal/recommended-project:^10
ddev composer require drush/drush
ddev start
ddev drush site:install standard --account-name=admin --account-pass=admin -y
mkdir -p web/modules/custom
# copy the workflow_engine folder into web/modules/custom/
ddev drush en workflow_engine -y
ddev drush cr
ddev launch /assessments
ddev launch /admin/structure/workflow-engine
```

Log in with `ddev drush uli`.

## Troubleshooting

* **Module not listed** – folder must be `web/modules/custom/workflow_engine`
  with `workflow_engine.info.yml` directly inside it.
* **Access denied** – grant *Administer workflows* and *View workflow
  submissions* at `/admin/people/permissions`.
* **Builder shows nothing** – run `drush cr`; check the browser console.
* **Upgrading from the first prototype** – run `drush updb -y`.
