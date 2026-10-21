<?php

namespace Drupal\workflow_engine\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\workflow_engine\WorkflowLogic;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin listing and detail view of submissions.
 */
class SubmissionController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'), $container->get('date.formatter'));
  }

  /**
   * Builds a coloured result badge.
   */
  protected function badge(string $level, string $label): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => ['class' => ['wfe-risk', 'wfe-risk--' . ($level !== '' ? $level : 'none')]],
    ];
  }

  /**
   * Lists submissions, newest first, with simple filters.
   */
  public function listing(Request $request): array {
    $workflow_filter = (string) $request->query->get('workflow', '');
    $level_filter = (string) $request->query->get('level', '');

    $query = $this->database->select('workflow_engine_submission', 's')
      ->fields('s', ['id', 'workflow_id', 'workflow_name', 'result', 'result_level', 'created', 'uid'])
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(25);
    if ($workflow_filter !== '') {
      $query->condition('workflow_id', $workflow_filter);
    }
    if ($level_filter !== '') {
      $query->condition('result_level', $level_filter === 'none' ? '' : $level_filter);
    }
    $records = $query->execute()->fetchAll();

    $uids = array_filter(array_unique(array_map(fn($r) => (int) $r->uid, $records)));
    $users = $uids ? $this->entityTypeManager()->getStorage('user')->loadMultiple($uids) : [];

    $rows = [];
    foreach ($records as $record) {
      $rows[] = [
        '#' . $record->id,
        $record->workflow_name,
        ['data' => $this->badge((string) $record->result_level, (string) $record->result)],
        $this->dateFormatter->format((int) $record->created, 'short'),
        isset($users[$record->uid]) ? $users[$record->uid]->getDisplayName() : $this->t('Anonymous'),
        ['data' => [
          '#type' => 'link',
          '#title' => $this->t('View'),
          '#url' => Url::fromRoute('workflow_engine.submission', ['submission_id' => $record->id]),
        ]],
      ];
    }

    return [
      '#attached' => ['library' => ['workflow_engine/admin']],
      'filters' => $this->filters($workflow_filter, $level_filter),
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Ref'), $this->t('Workflow'), $this->t('Result'), $this->t('Submitted'), $this->t('By'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No submissions match.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['contexts' => ['url.query_args'], 'tags' => ['workflow_engine_submissions']],
    ];
  }

  /**
   * Builds the filter chips above the table.
   */
  protected function filters(string $workflow, string $level): array {
    $chip = function ($title, array $query, bool $active): array {
      return [
        '#type' => 'link',
        '#title' => $title,
        '#url' => Url::fromRoute('workflow_engine.submissions', [], ['query' => array_filter($query, fn($v) => $v !== '')]),
        '#attributes' => ['class' => ['wfe-pill', $active ? 'is-active' : '']],
      ];
    };
    $build = ['#type' => 'container', '#attributes' => ['class' => ['wfe-filters']]];
    $build['w_label'] = ['#markup' => '<strong>' . $this->t('Workflow') . '</strong>'];
    $build['w_all'] = $chip($this->t('All'), ['level' => $level], $workflow === '');
    foreach ($this->entityTypeManager()->getStorage('workflow_engine_workflow')->loadMultiple() as $id => $entity) {
      $build['w_' . $id] = $chip($entity->label(), ['workflow' => $id, 'level' => $level], $workflow === $id);
    }
    $build['l_label'] = ['#markup' => '<strong style="margin-left:1rem">' . $this->t('Result') . '</strong>'];
    $build['l_all'] = $chip($this->t('All'), ['workflow' => $workflow], $level === '');
    foreach (WorkflowLogic::LEVELS as $key => $label) {
      $build['l_' . $key] = $chip($label, ['workflow' => $workflow, 'level' => $key], $level === $key);
    }
    return $build;
  }

  /**
   * Shows one submission: answers and calculated result.
   */
  public function detail(int $submission_id): array {
    $record = $this->database->select('workflow_engine_submission', 's')
      ->fields('s')
      ->condition('id', $submission_id)
      ->execute()
      ->fetchObject();
    if (!$record) {
      throw new NotFoundHttpException();
    }

    $groups = json_decode((string) $record->answers, TRUE);
    $groups = is_array($groups) ? $groups : [];
    // Submissions saved by the first prototype were a flat key => value map.
    if ($groups && !isset($groups[0]['items'])) {
      $items = [];
      foreach ($groups as $key => $value) {
        $items[] = ['label' => (string) $key, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
      }
      $groups = [['title' => $this->t('Answers'), 'items' => $items]];
    }

    $user = $record->uid ? $this->entityTypeManager()->getStorage('user')->load($record->uid) : NULL;
    return [
      '#theme' => 'workflow_engine_submission',
      '#workflow_name' => $record->workflow_name,
      '#level' => $record->result_level,
      '#result' => $record->result,
      '#message' => $record->result_message,
      '#submitted' => $this->dateFormatter->format((int) $record->created, 'long'),
      '#user' => $user ? $user->getDisplayName() : $this->t('Anonymous'),
      '#reference' => $record->id,
      '#groups' => $groups,
      '#back_url' => Url::fromRoute('workflow_engine.submissions')->toString(),
      '#attached' => ['library' => ['workflow_engine/admin']],
    ];
  }

}
