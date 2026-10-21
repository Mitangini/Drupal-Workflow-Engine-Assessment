# Workflow Engine (Drupal 10 / 11)

A configuration-driven assessment builder. Administrators create multi-step
workflows from a visual builder in the Drupal admin; visitors complete them on
the front end; submissions and the calculated result are reviewed back in the
admin. **No workflow-specific PHP, Twig or JavaScript** — a new workflow is only
data.

## Requirement coverage

| Brief | Where |
|---|---|
| Create workflow with name + URL slug | Admin → Structure → Workflows → Create workflow |
| Up to three steps, configurable | Step tabs in the builder (hard limit of 3, enforced in JS and on the server) |
| Text / select / radio fields (+ email, long text) | "Answer type" on each question |
| Conditional question from a previous answer | "Only show this question when an earlier answer matches" |
| Risk rule (e.g. No lease → High Risk) | Results section: *When the answer to [question] is [answer] show [Low/Medium/High risk]* |
| Publish / unpublish | "Published" checkbox, or one-click **Publish/Unpublish** in the list |
| Published workflows on a front-end page | `/assessments` |
| Complete the steps, dynamic fields, validation, conditions | `/assessment/{slug}` (one step per page, progress bar, Back/Next) |
| Result displayed | `/assessment/{slug}/result` |
| Save answers + result; admin listing; detail view | Reports → Workflow submissions (`/admin/reports/workflow-engine/submissions`) |

## Design decisions

* **Workflow = config entity** (`workflow_engine_workflow`). Definitions are
  exportable with config sync (`drush cex`) and move between environments.
  Steps, questions, conditions and rules are stored as structured data with a
  full config schema — not a JSON blob.
* **Submissions = custom table.** Append-only and high-volume, so not config.
  Each row stores a *snapshot* (question text + answer, grouped by step), so
  old submissions stay readable if a workflow is edited later.
* **All rules live in `WorkflowLogic`**, a pure class with no Drupal
  dependencies (visibility, validation, risk evaluation, builder validation).
  It is unit-tested and used by both the form and the builder.
* **Conditions** are evaluated twice: instantly in the browser (`#states`) and
  again on the server. A question that depends on a hidden question is hidden
  too, and answers to hidden questions are never saved or scored.
* **Required + conditional**: core `#required` would fire on hidden fields, so
  conditionally-shown questions use the `required` state client-side and
  server-side enforcement via `validateAnswers()`.
* **Risk rules**: every matching rule counts and the *most severe* level wins,
  independent of rule order. No match → configurable default result.
* **Builder UI** is a small vanilla-JS app (`js/builder.js`) that serialises to
  one hidden field. It has a live "Before you save" checklist. The server
  re-validates everything, so bypassing JS cannot store a broken workflow.
* **Privacy**: the result page reads the submission id from the visitor's own
  session, so results cannot be opened by guessing reference numbers.
* **Permissions**: `administer workflow engine` (build) and
  `view workflow submissions` (review). Unpublished workflows return
  "not available" to visitors; administrators can preview them.

## Install

1. Copy this folder to `web/modules/custom/workflow_engine`.
2. `drush en workflow_engine -y && drush cr`
   (or enable at `/admin/modules`).
3. Grant the two permissions at `/admin/people/permissions`
   (user 1 / administrators already have them).
4. The **Property Lease Assessment** sample from the brief is installed as
   configuration. Open `/assessments` to try it, or edit it at
   `/admin/structure/workflow-engine`.

### Upgrading from the first prototype

The first version stored workflows in `workflow_engine.workflows`. Run
`drush updb -y` — it adds the new submission columns and removes the old config
object. (On a throw-away dev site you can also uninstall and re-enable.)

## Using it

1. **Create workflow** → give it a name (the web address fills itself in).
2. **Questions**: pick a step tab, type a step title, **Add question**, then
   choose the answer type. For dropdown/radio questions add the choices.
3. **Conditional questions**: open a question → switch on *Only show this
   question when an earlier answer matches* → pick the earlier question and the
   answer.
4. **Results**: **Add result rule** → choose the question, the answer and the
   result level (and an optional message).
5. **Save workflow**. Publish/unpublish from the list.

## Tests

```bash
vendor/bin/phpunit -c web/core web/modules/custom/workflow_engine/tests
```

`WorkflowLogicTest` covers conditions, nested conditions, required-when-visible,
email/choice validation, the two sample risk rules, severity ordering, default
result, snapshots and builder validation.

## Manual test checklist

1. `/assessments` lists *Property Lease Assessment*.
2. Leave name/email blank → "is required"; bad email → error.
3. Residential → only the personal-use/rental question appears.
4. Commercial → business type + public access appear instead.
5. Lease = No → **High Risk**; Lease = Yes → **Low Risk**.
6. Back button keeps previous answers.
7. Reports → Workflow submissions shows both; open one to see grouped answers.
8. Unpublish the workflow → `/assessments` no longer lists it.
9. Create a second workflow with different questions, without touching code.

## File map

```
config/install/      sample workflow (Property Lease)
config/schema/       schema for the workflow config entity
js/builder.js        visual builder (admin)
src/WorkflowLogic.php        all conditional / risk / validation logic
src/Entity/Workflow.php      config entity
src/Form/WorkflowForm.php    builder form (server-side validation)
src/Form/WorkflowRunForm.php multi-step front-end form
src/Controller/              listing, result, submissions, publish toggle
templates/           cards, progress, result, submission detail
tests/               unit tests
```

## Known limits (by design, per the brief)

No document generation, no complex/compound risk rules, no multiple condition
groups. One condition per question; rules compare one dropdown/radio answer.
