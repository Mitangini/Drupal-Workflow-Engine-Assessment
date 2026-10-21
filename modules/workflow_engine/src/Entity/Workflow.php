<?php

namespace Drupal\workflow_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the assessment workflow config entity.
 *
 * @ConfigEntityType(
 *   id = "workflow_engine_workflow",
 *   label = @Translation("Workflow"),
 *   label_collection = @Translation("Workflows"),
 *   label_singular = @Translation("workflow"),
 *   label_plural = @Translation("workflows"),
 *   label_count = @PluralTranslation(
 *     singular = "@count workflow",
 *     plural = "@count workflows",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\workflow_engine\WorkflowListBuilder",
 *     "form" = {
 *       "add" = "Drupal\workflow_engine\Form\WorkflowForm",
 *       "edit" = "Drupal\workflow_engine\Form\WorkflowForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "workflow",
 *   admin_permission = "administer workflow engine",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "slug",
 *     "default_result",
 *     "steps",
 *     "rules"
 *   },
 *   links = {
 *     "collection" = "/admin/structure/workflow-engine",
 *     "add-form" = "/admin/structure/workflow-engine/add",
 *     "edit-form" = "/admin/structure/workflow-engine/manage/{workflow_engine_workflow}",
 *     "delete-form" = "/admin/structure/workflow-engine/manage/{workflow_engine_workflow}/delete"
 *   }
 * )
 */
class Workflow extends ConfigEntityBase {

  /**
   * The machine name.
   *
   * @var string
   */
  protected $id;

  /**
   * The workflow name.
   *
   * @var string
   */
  protected $label;

  /**
   * The URL slug used at /assessment/{slug}.
   *
   * @var string
   */
  protected $slug = '';

  /**
   * Result label shown when no result rule matches.
   *
   * @var string
   */
  protected $default_result = 'Assessment complete';

  /**
   * Steps: each with id, title and questions. See the config schema.
   *
   * @var array
   */
  protected $steps = [];

  /**
   * Result rules: each with id, question, value, level and message.
   *
   * @var array
   */
  protected $rules = [];

  /**
   * Loads a workflow by its URL slug.
   *
   * @param string $slug
   *   The URL slug.
   * @param bool $published_only
   *   Only return the workflow when it is published.
   */
  public static function loadBySlug(string $slug, bool $published_only = TRUE): ?self {
    $properties = ['slug' => $slug];
    if ($published_only) {
      $properties['status'] = TRUE;
    }
    $found = \Drupal::entityTypeManager()
      ->getStorage('workflow_engine_workflow')
      ->loadByProperties($properties);
    return $found ? reset($found) : NULL;
  }

  /**
   * Returns the URL slug.
   */
  public function getSlug(): string {
    return (string) $this->slug;
  }

  /**
   * Returns the steps.
   */
  public function getSteps(): array {
    return $this->steps ?? [];
  }

  /**
   * Returns the result rules.
   */
  public function getRules(): array {
    return $this->rules ?? [];
  }

  /**
   * Whether the workflow is published.
   */
  public function isPublished(): bool {
    return (bool) $this->status();
  }

  /**
   * Counts all questions across steps.
   */
  public function countQuestions(): int {
    $count = 0;
    foreach ($this->getSteps() as $step) {
      $count += count($step['questions'] ?? []);
    }
    return $count;
  }

  /**
   * Returns the plain-array definition consumed by WorkflowLogic.
   */
  public function toDefinition(): array {
    return [
      'steps' => $this->getSteps(),
      'rules' => $this->getRules(),
      'default_result' => (string) $this->default_result,
    ];
  }

}
