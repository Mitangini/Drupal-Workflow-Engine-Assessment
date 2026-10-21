<?php

namespace Drupal\workflow_engine\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\workflow_engine\Entity\Workflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Visitor-facing pages: workflow listing and result.
 */
class AssessmentController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * Lists published workflows as cards.
   */
  public function listing(): array {
    $storage = $this->entityTypeManager()->getStorage('workflow_engine_workflow');
    $workflows = $storage->loadByProperties(['status' => TRUE]);
    uasort($workflows, fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));

    $items = [];
    foreach ($workflows as $workflow) {
      /** @var \Drupal\workflow_engine\Entity\Workflow $workflow */
      $questions = $workflow->countQuestions();
      $items[] = [
        'name' => $workflow->label(),
        'meta' => $this->t('@steps steps · about @min min', [
          '@steps' => count($workflow->getSteps()),
          '@min' => max(1, (int) ceil($questions / 4)),
        ]),
        'url' => Url::fromRoute('workflow_engine.run', ['slug' => $workflow->getSlug()])->toString(),
      ];
    }
    return [
      '#theme' => 'workflow_engine_cards',
      '#items' => $items,
      '#empty' => $this->t('There are no assessments available right now.'),
      '#attached' => ['library' => ['workflow_engine/assessment']],
      '#cache' => ['tags' => $this->entityTypeManager()->getDefinition('workflow_engine_workflow')->getListCacheTags()],
    ];
  }

  /**
   * Page title for an assessment.
   */
  public function title(string $slug): string {
    $workflow = Workflow::loadBySlug($slug, !$this->currentUser()->hasPermission('administer workflow engine'));
    return $workflow ? (string) $workflow->label() : (string) $this->t('Assessment');
  }

  /**
   * Shows the visitor their own most recent result for a workflow.
   */
  public function result(string $slug, Request $request) {
    $workflow = Workflow::loadBySlug($slug, FALSE);
    $id = $workflow ? (int) $request->getSession()->get('workflow_engine.last.' . $workflow->id(), 0) : 0;
    $row = $id ? $this->database->select('workflow_engine_submission', 's')
      ->fields('s')
      ->condition('id', $id)
      ->condition('workflow_id', $workflow->id())
      ->execute()
      ->fetchObject() : NULL;
    if (!$row) {
      return $this->redirect('workflow_engine.run', ['slug' => $slug]);
    }
    return [
      '#theme' => 'workflow_engine_result',
      '#workflow_name' => $row->workflow_name,
      '#level' => $row->result_level,
      '#result' => $row->result,
      '#message' => $row->result_message,
      '#reference' => $row->id,
      '#restart_url' => Url::fromRoute('workflow_engine.run', ['slug' => $slug])->toString(),
      '#back_url' => Url::fromRoute('workflow_engine.frontend_list')->toString(),
      '#attached' => ['library' => ['workflow_engine/assessment']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
