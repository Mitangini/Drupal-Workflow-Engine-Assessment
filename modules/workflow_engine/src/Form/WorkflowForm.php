<?php

namespace Drupal\workflow_engine\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflow_engine\WorkflowLogic;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Create/edit form for a workflow.
 *
 * The question builder is a small JavaScript app (js/builder.js) that keeps its
 * state in a hidden field. This form validates that state on the server, so the
 * rules hold even if the JavaScript is bypassed.
 */
class WorkflowForm extends EntityForm {

  /**
   * The workflow logic service.
   *
   * @var \Drupal\workflow_engine\WorkflowLogic
   */
  protected $logic;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->logic = $container->get('workflow_engine.logic');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\workflow_engine\Entity\Workflow $workflow */
    $workflow = $this->entity;

    $form['#attributes']['class'][] = 'wfe-form';
    $form['#attached']['library'][] = 'workflow_engine/builder';

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow name'),
      '#description' => $this->t('Shown to visitors, for example "Property Lease Assessment".'),
      '#default_value' => $workflow->label(),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#weight' => -20,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $workflow->id(),
      '#machine_name' => [
        'exists' => [static::class, 'machineNameExists'],
        'source' => ['label'],
      ],
      '#disabled' => !$workflow->isNew(),
      '#weight' => -19,
    ];
    $form['slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web address'),
      '#field_prefix' => '/assessment/',
      '#description' => $this->t('Lowercase letters, numbers and hyphens. Filled in from the name; change it if you like.'),
      '#default_value' => $workflow->getSlug(),
      '#maxlength' => 128,
      '#required' => TRUE,
      '#weight' => -18,
      '#attributes' => ['data-wfe-slug' => ''],
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#description' => $this->t('Unpublished workflows are hidden from visitors. Administrators can still preview them.'),
      '#default_value' => $workflow->isNew() ? TRUE : $workflow->isPublished(),
      '#weight' => -17,
    ];

    $form['builder_data'] = [
      '#type' => 'hidden',
      '#default_value' => json_encode($this->builderState($workflow), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      '#weight' => -10,
      '#attributes' => ['data-wfe-data' => ''],
    ];
    $form['builder'] = [
      '#type' => 'container',
      '#weight' => -9,
      '#attributes' => ['class' => ['wfe-builder'], 'data-wfe-builder' => ''],
      'fallback' => [
        '#markup' => '<noscript><p class="messages messages--warning">' . $this->t('JavaScript is required to build questions.') . '</p></noscript>',
      ],
    ];
    return $form;
  }

  /**
   * Machine name existence callback.
   */
  public static function machineNameExists(string $id): bool {
    return (bool) \Drupal::entityTypeManager()->getStorage('workflow_engine_workflow')->load($id);
  }

  /**
   * Returns the state the JavaScript builder starts from.
   */
  protected function builderState(EntityInterface $workflow): array {
    /** @var \Drupal\workflow_engine\Entity\Workflow $workflow */
    if ($workflow->isNew() || !$workflow->getSteps()) {
      return [
        'steps' => [['id' => 's1', 'title' => '', 'questions' => []]],
        'rules' => [],
        'default_result' => 'Assessment complete',
      ];
    }
    return $workflow->toDefinition();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $slug = trim((string) $form_state->getValue('slug'));
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
      $form_state->setErrorByName('slug', $this->t('Use only lowercase letters, numbers and single hyphens in the web address.'));
    }
    else {
      $others = $this->entityTypeManager->getStorage('workflow_engine_workflow')->loadByProperties(['slug' => $slug]);
      foreach ($others as $other) {
        if ($other->id() !== $this->entity->id()) {
          $form_state->setErrorByName('slug', $this->t('Another workflow already uses that web address.'));
        }
      }
    }

    $raw = json_decode((string) $form_state->getValue('builder_data'), TRUE);
    if (!is_array($raw)) {
      $form_state->setErrorByName('builder_data', $this->t('The question builder data could not be read. Reload the page and try again.'));
      return;
    }
    $definition = $this->logic->normalize($raw);
    foreach ($this->logic->validate($definition) as $i => $message) {
      $form_state->setErrorByName('wfe_error_' . $i, $message);
    }
    $form_state->set('wfe_definition', $definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\workflow_engine\Entity\Workflow $entity */
    $definition = $form_state->get('wfe_definition');
    $entity->set('label', trim((string) $form_state->getValue('label')));
    if ($entity->isNew()) {
      $entity->set('id', $form_state->getValue('id'));
    }
    $entity->set('slug', trim((string) $form_state->getValue('slug')));
    $entity->set('status', (bool) $form_state->getValue('status'));
    $entity->set('steps', $definition['steps']);
    $entity->set('rules', $definition['rules']);
    $entity->set('default_result', $definition['default_result']);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save workflow');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->save();
    $this->messenger()->addStatus($this->t('Workflow %name saved.', ['%name' => $this->entity->label()]));
    $form_state->setRedirect('entity.workflow_engine_workflow.collection');
  }

}
