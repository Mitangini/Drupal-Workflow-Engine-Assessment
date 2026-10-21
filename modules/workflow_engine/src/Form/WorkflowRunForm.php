<?php

namespace Drupal\workflow_engine\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\workflow_engine\Entity\Workflow;
use Drupal\workflow_engine\WorkflowLogic;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a workflow one step at a time, built entirely from its configuration.
 *
 * Nothing about a particular workflow is known here: fields, conditions,
 * required rules and results all come from the Workflow config entity.
 *
 * Note: properties are not "readonly" because forms are serialised into the
 * form cache between steps.
 */
class WorkflowRunForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workflow logic service.
   *
   * @var \Drupal\workflow_engine\WorkflowLogic
   */
  protected $logic;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WorkflowLogic $logic, Connection $database, TimeInterface $time, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logic = $logic;
    $this->database = $database;
    $this->time = $time;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('workflow_engine.logic'),
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'workflow_engine_run_form';
  }

  /**
   * Loads the workflow for a slug; administrators may preview unpublished ones.
   */
  protected function loadWorkflow(string $slug): ?Workflow {
    $published_only = !$this->currentUser->hasPermission('administer workflow engine');
    return Workflow::loadBySlug($slug, $published_only);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $slug = ''): array {
    $workflow = $this->loadWorkflow($slug);
    $list_tags = $this->entityTypeManager->getDefinition('workflow_engine_workflow')->getListCacheTags();
    if (!$workflow || !$workflow->getSteps()) {
      return [
        '#markup' => '<p class="wfe-empty-state">' . $this->t('This assessment is not available.') . '</p>',
        '#cache' => ['tags' => $list_tags, 'contexts' => ['user.permissions']],
      ];
    }
    $form_state->set('slug', $slug);
    $definition = $workflow->toDefinition();
    $steps = $definition['steps'];
    $count = count($steps);
    $step_index = min((int) ($form_state->get('step') ?? 0), $count - 1);
    $answers = $form_state->get('answers') ?? [];
    $by_id = $this->logic->questionsById($definition);
    $step = $steps[$step_index];

    $step_of = [];
    foreach ($steps as $i => $s) {
      foreach ($s['questions'] as $question) {
        $step_of[$question['id']] = $i;
      }
    }

    $form['#attributes']['class'][] = 'workflow-engine-run-form';
    $form['#attached']['library'][] = 'workflow_engine/assessment';
    $form['#cache'] = ['tags' => $workflow->getCacheTags(), 'contexts' => ['user.permissions']];

    $progress = [];
    foreach ($steps as $i => $s) {
      $progress[] = [
        'title' => $s['title'],
        'state' => $i < $step_index ? 'done' : ($i === $step_index ? 'current' : 'todo'),
      ];
    }
    $form['progress'] = ['#theme' => 'workflow_engine_progress', '#steps' => $progress, '#weight' => -20];
    $form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#attributes' => ['class' => ['wfe-step-head']],
      '#weight' => -19,
      'small' => [
        '#type' => 'html_tag',
        '#tag' => 'small',
        '#value' => $this->t('Step @n of @total', ['@n' => $step_index + 1, '@total' => $count]),
      ],
      'title' => ['#plain_text' => $step['title']],
    ];

    $form['answers'] = ['#type' => 'container', '#tree' => TRUE];
    foreach ($step['questions'] as $question) {
      $id = $question['id'];

      // Work out when this question is shown. Conditions on questions from
      // earlier steps are already decided; conditions on questions in this step
      // become client-side #states (and are re-checked on the server).
      $conditions = [];
      $hidden = FALSE;
      $walk = $question;
      for ($guard = 0; $guard < 10 && !empty($walk['condition']['question']); $guard++) {
        $source_id = $walk['condition']['question'];
        $source = $by_id[$source_id] ?? NULL;
        if (!$source) {
          break;
        }
        if (($step_of[$source_id] ?? $step_index) === $step_index) {
          $conditions[':input[name="answers[' . $source_id . ']"]'] = ['value' => (string) $walk['condition']['value']];
        }
        else {
          if (!$this->logic->isVisible($source, $answers, $by_id) || (string) ($answers[$source_id] ?? '') !== (string) $walk['condition']['value']) {
            $hidden = TRUE;
          }
          break;
        }
        $walk = $source;
      }
      if ($hidden) {
        continue;
      }

      $element = [
        '#title' => $question['label'],
        '#default_value' => $answers[$id] ?? '',
      ];
      $options = array_combine($question['options'], $question['options']) ?: [];
      switch ($question['type']) {
        case 'textarea':
          $element += ['#type' => 'textarea', '#rows' => 4];
          break;

        case 'email':
          $element += ['#type' => 'email', '#maxlength' => 255];
          break;

        case 'select':
          $element += ['#type' => 'select', '#options' => $options, '#empty_option' => $this->t('- Select -')];
          break;

        case 'radio':
          $element += ['#type' => 'radios', '#options' => $options];
          break;

        default:
          $element += ['#type' => 'textfield', '#maxlength' => 255];
      }

      if ($conditions) {
        $element['#states']['visible'] = $conditions;
        if (!empty($question['required'])) {
          // Core's #required would fire even while the field is hidden, so a
          // conditionally shown field is only marked required in the browser;
          // the server enforces it via WorkflowLogic::validateAnswers().
          $element['#states']['required'] = $conditions;
        }
      }
      else {
        $element['#required'] = !empty($question['required']);
      }
      $form['answers'][$id] = $element;
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($step_index > 0) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#name' => 'back',
        '#submit' => ['::backSubmit'],
        '#validate' => [],
        '#limit_validation_errors' => [],
      ];
    }
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $step_index === $count - 1 ? $this->t('Submit assessment') : $this->t('Next'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Returns the question IDs of the step currently shown.
   */
  protected function currentQuestionIds(FormStateInterface $form_state, array $definition): array {
    $step = $definition['steps'][(int) ($form_state->get('step') ?? 0)] ?? ['questions' => []];
    return array_column($step['questions'], 'id');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $workflow = $this->loadWorkflow((string) $form_state->get('slug'));
    if (!$workflow) {
      $form_state->setErrorByName('', $this->t('This assessment is no longer available.'));
      return;
    }
    $definition = $workflow->toDefinition();
    $ids = $this->currentQuestionIds($form_state, $definition);
    $answers = array_merge($form_state->get('answers') ?? [], $this->pick($form_state->getValue('answers') ?? [], $ids));
    foreach ($this->logic->validateAnswers($definition, $answers, $ids) as $id => $message) {
      $form_state->setErrorByName('answers][' . $id, $message);
    }
  }

  /**
   * Keeps only scalar values for the given question IDs.
   */
  protected function pick(array $values, array $ids): array {
    $picked = [];
    foreach ($ids as $id) {
      if (isset($values[$id]) && is_scalar($values[$id])) {
        $picked[$id] = trim((string) $values[$id]);
      }
    }
    return $picked;
  }

  /**
   * Goes back one step, keeping what was typed.
   */
  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $workflow = $this->loadWorkflow((string) $form_state->get('slug'));
    if ($workflow) {
      $ids = $this->currentQuestionIds($form_state, $workflow->toDefinition());
      $input = $form_state->getUserInput()['answers'] ?? [];
      $form_state->set('answers', array_merge($form_state->get('answers') ?? [], $this->pick(is_array($input) ? $input : [], $ids)));
    }
    $form_state->set('step', max(0, (int) $form_state->get('step') - 1));
    $form_state->setRebuild(TRUE);
    $form_state->setCached(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $workflow = $this->loadWorkflow((string) $form_state->get('slug'));
    if (!$workflow) {
      return;
    }
    $definition = $workflow->toDefinition();
    $ids = $this->currentQuestionIds($form_state, $definition);
    $answers = array_merge($form_state->get('answers') ?? [], $this->pick($form_state->getValue('answers') ?? [], $ids));
    $form_state->set('answers', $answers);

    $step = (int) ($form_state->get('step') ?? 0);
    if ($step < count($definition['steps']) - 1) {
      $form_state->set('step', $step + 1);
      $form_state->setRebuild(TRUE);
      $form_state->setCached(TRUE);
      return;
    }

    // Final step: validate everything again, then calculate and store.
    $errors = $this->logic->validateAnswers($definition, $answers);
    if ($errors) {
      $first = array_key_first($errors);
      foreach ($definition['steps'] as $i => $s) {
        if (in_array($first, array_column($s['questions'], 'id'), TRUE)) {
          $form_state->set('step', $i);
          break;
        }
      }
      $this->messenger()->addError($this->t('Please complete the highlighted answers before submitting.'));
      $form_state->setRebuild(TRUE);
      $form_state->setCached(TRUE);
      return;
    }

    $result = $this->logic->evaluate($definition, $answers);
    $summary = $this->logic->summarize($definition, $answers);
    $id = $this->database->insert('workflow_engine_submission')
      ->fields([
        'workflow_id' => $workflow->id(),
        'workflow_name' => $workflow->label(),
        'answers' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'result' => $result['label'],
        'result_level' => $result['level'],
        'result_message' => $result['message'],
        'created' => $this->time->getRequestTime(),
        'uid' => (int) $this->currentUser->id(),
      ])
      ->execute();

    // The result page reads the id from the visitor's own session, so one
    // person's result can't be opened by guessing another's reference number.
    $this->getRequest()->getSession()->set('workflow_engine.last.' . $workflow->id(), (int) $id);
    Cache::invalidateTags(['workflow_engine_submissions']);
    $form_state->setRedirect('workflow_engine.result', ['slug' => $workflow->getSlug()]);
  }

}
