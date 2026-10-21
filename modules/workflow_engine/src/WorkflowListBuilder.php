<?php

namespace Drupal\workflow_engine;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Lists workflows with publish/unpublish and quick links.
 */
class WorkflowListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Workflow');
    $header['slug'] = $this->t('Public link');
    $header['size'] = $this->t('Steps / questions');
    $header['status'] = $this->t('Status');
    $header['operations'] = $this->t('Operations');
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\workflow_engine\Entity\Workflow $entity */
    $published = $entity->isPublished();
    $row['label'] = ['data' => [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => $entity->toUrl('edit-form'),
    ]];
    $row['slug'] = ['data' => ['#markup' => '<code>/assessment/' . htmlspecialchars($entity->getSlug()) . '</code>']];
    $row['size'] = $this->t('@steps steps · @questions questions', [
      '@steps' => count($entity->getSteps()),
      '@questions' => $entity->countQuestions(),
    ]);
    $row['status'] = ['data' => [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $published ? $this->t('Published') : $this->t('Unpublished'),
      '#attributes' => ['class' => ['wfe-badge', $published ? 'wfe-badge--on' : 'wfe-badge--off']],
    ]];
    $row['operations']['data'] = $this->buildOperations($entity);
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\workflow_engine\Entity\Workflow $entity */
    $operations = parent::getDefaultOperations($entity);
    $operations['toggle'] = [
      'title' => $entity->isPublished() ? $this->t('Unpublish') : $this->t('Publish'),
      'weight' => 5,
      'url' => Url::fromRoute('workflow_engine.toggle', ['workflow_engine_workflow' => $entity->id()]),
    ];
    $operations['open'] = [
      'title' => $this->t('Open'),
      'weight' => 15,
      'url' => Url::fromRoute('workflow_engine.run', ['slug' => $entity->getSlug()]),
    ];
    $operations['submissions'] = [
      'title' => $this->t('Submissions'),
      'weight' => 20,
      'url' => Url::fromRoute('workflow_engine.submissions', [], ['query' => ['workflow' => $entity->id()]]),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No workflows yet. Use "Create workflow" to build your first assessment.');
    $build['#attached']['library'][] = 'workflow_engine/admin';
    return $build;
  }

}
