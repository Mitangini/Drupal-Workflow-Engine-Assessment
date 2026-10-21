<?php

namespace Drupal\workflow_engine;

/**
 * Pure business logic for workflows.
 *
 * Conditional visibility, risk evaluation, answer validation, summaries and
 * normalisation/validation of builder data. It works on plain arrays (see
 * Workflow::toDefinition()) and has no Drupal dependencies, so it can be unit
 * tested directly.
 */
final class WorkflowLogic {

  /**
   * Maximum number of steps per workflow.
   */
  public const MAX_STEPS = 3;

  /**
   * Risk levels, ordered from least to most severe.
   */
  public const LEVELS = [
    'low' => 'Low Risk',
    'medium' => 'Medium Risk',
    'high' => 'High Risk',
  ];

  /**
   * Allowed answer types.
   */
  public const TYPES = ['text', 'textarea', 'email', 'select', 'radio'];

  /**
   * Answer types that offer a list of choices.
   */
  public const CHOICE_TYPES = ['select', 'radio'];

  /**
   * Returns all questions keyed by ID.
   */
  public function questionsById(array $definition): array {
    $by_id = [];
    foreach ($definition['steps'] ?? [] as $step) {
      foreach ($step['questions'] ?? [] as $question) {
        $by_id[$question['id']] = $question;
      }
    }
    return $by_id;
  }

  /**
   * Whether a question is shown, given the answers so far.
   *
   * A question is hidden when its condition is not met, or when the question
   * it depends on is itself hidden.
   */
  public function isVisible(array $question, array $answers, array $by_id, int $depth = 0): bool {
    $condition = $question['condition'] ?? [];
    if (empty($condition['question']) || !isset($condition['value'])) {
      return TRUE;
    }
    $source = $condition['question'];
    // A dangling reference (or a loop) must never hide a question silently.
    if (!isset($by_id[$source]) || $depth > 10) {
      return TRUE;
    }
    if (!$this->isVisible($by_id[$source], $answers, $by_id, $depth + 1)) {
      return FALSE;
    }
    return (string) ($answers[$source] ?? '') === (string) $condition['value'];
  }

  /**
   * Drops answers to questions that are hidden or unknown.
   */
  public function visibleAnswers(array $definition, array $answers): array {
    $by_id = $this->questionsById($definition);
    $clean = [];
    foreach ($by_id as $id => $question) {
      if (array_key_exists($id, $answers) && $this->isVisible($question, $answers, $by_id)) {
        $clean[$id] = trim((string) $answers[$id]);
      }
    }
    return $clean;
  }

  /**
   * Validates answers against the definition.
   *
   * @param array $definition
   *   The workflow definition.
   * @param array $answers
   *   Answers keyed by question ID.
   * @param string[]|null $only_ids
   *   Restrict validation to these question IDs (e.g. the current step).
   *
   * @return string[]
   *   Error messages keyed by question ID.
   */
  public function validateAnswers(array $definition, array $answers, ?array $only_ids = NULL): array {
    $by_id = $this->questionsById($definition);
    $errors = [];
    foreach ($by_id as $id => $question) {
      if ($only_ids !== NULL && !in_array($id, $only_ids, TRUE)) {
        continue;
      }
      if (!$this->isVisible($question, $answers, $by_id)) {
        continue;
      }
      $label = $question['label'];
      $value = trim((string) ($answers[$id] ?? ''));
      if ($value === '') {
        if (!empty($question['required'])) {
          $errors[$id] = sprintf('%s is required.', $label);
        }
        continue;
      }
      $type = $question['type'] ?? 'text';
      if (in_array($type, self::CHOICE_TYPES, TRUE)) {
        $options = array_map('strval', $question['options'] ?? []);
        if (!in_array($value, $options, TRUE)) {
          $errors[$id] = sprintf('Choose one of the available answers for "%s".', $label);
        }
      }
      elseif ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $errors[$id] = sprintf('Enter a valid email address for "%s".', $label);
      }
      elseif (mb_strlen($value) > ($type === 'textarea' ? 5000 : 255)) {
        $errors[$id] = sprintf('"%s" is too long.', $label);
      }
    }
    return $errors;
  }

  /**
   * Calculates the assessment result.
   *
   * Every rule whose question was answered with the configured value matches;
   * the most severe matching level wins. With no match the default result is
   * returned with an empty level.
   *
   * @return array
   *   Keys: level, label, message.
   */
  public function evaluate(array $definition, array $answers): array {
    $answers = $this->visibleAnswers($definition, $answers);
    $best = NULL;
    $rank = array_flip(array_keys(self::LEVELS));
    foreach ($definition['rules'] ?? [] as $rule) {
      $level = $rule['level'] ?? '';
      if (!isset($rank[$level]) || !isset($answers[$rule['question'] ?? ''])) {
        continue;
      }
      if ((string) $answers[$rule['question']] !== (string) ($rule['value'] ?? '')) {
        continue;
      }
      if ($best === NULL || $rank[$level] > $rank[$best['level']]) {
        $best = [
          'level' => $level,
          'label' => self::LEVELS[$level],
          'message' => (string) ($rule['message'] ?? ''),
        ];
      }
    }
    return $best ?? [
      'level' => '',
      'label' => (string) ($definition['default_result'] ?? 'Assessment complete'),
      'message' => '',
    ];
  }

  /**
   * Builds a readable snapshot of the answers, grouped by step.
   *
   * @return array
   *   A list of ['title' => string, 'items' => [['label', 'value'], ...]].
   */
  public function summarize(array $definition, array $answers): array {
    $answers = $this->visibleAnswers($definition, $answers);
    $summary = [];
    foreach ($definition['steps'] ?? [] as $step) {
      $items = [];
      foreach ($step['questions'] ?? [] as $question) {
        if (isset($answers[$question['id']]) && $answers[$question['id']] !== '') {
          $items[] = ['label' => $question['label'], 'value' => $answers[$question['id']]];
        }
      }
      $summary[] = ['title' => $step['title'], 'items' => $items];
    }
    return $summary;
  }

  /**
   * Cleans raw builder data into a well-formed definition.
   *
   * Never throws: unusable parts are dropped or defaulted. Use validate() to
   * report problems to the administrator.
   */
  public function normalize(array $raw): array {
    $seen = [];
    $steps = [];
    foreach (array_values((array) ($raw['steps'] ?? [])) as $step) {
      if (!is_array($step)) {
        continue;
      }
      $questions = [];
      foreach (array_values((array) ($step['questions'] ?? [])) as $question) {
        if (!is_array($question)) {
          continue;
        }
        $type = in_array($question['type'] ?? '', self::TYPES, TRUE) ? $question['type'] : 'text';
        $options = [];
        if (in_array($type, self::CHOICE_TYPES, TRUE)) {
          foreach ((array) ($question['options'] ?? []) as $option) {
            $option = $this->clean($option, 255);
            if ($option !== '' && !in_array($option, $options, TRUE)) {
              $options[] = $option;
            }
          }
        }
        $clean = [
          'id' => $this->cleanId($question['id'] ?? '', 'q', $seen),
          'label' => $this->clean($question['label'] ?? '', 255),
          'type' => $type,
          'required' => !empty($question['required']),
          'options' => $options,
        ];
        $condition = $question['condition'] ?? NULL;
        if (is_array($condition)) {
          $source = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($condition['question'] ?? ''));
          $value = $this->clean($condition['value'] ?? '', 255);
          if ($source !== '' || $value !== '') {
            $clean['condition'] = ['question' => $source, 'value' => $value];
          }
        }
        $questions[] = $clean;
      }
      $steps[] = [
        'id' => $this->cleanId($step['id'] ?? '', 's', $seen),
        'title' => $this->clean($step['title'] ?? '', 128),
        'questions' => $questions,
      ];
    }

    $rules = [];
    foreach (array_values((array) ($raw['rules'] ?? [])) as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $clean = [
        'id' => $this->cleanId($rule['id'] ?? '', 'r', $seen),
        'question' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($rule['question'] ?? '')),
        'value' => $this->clean($rule['value'] ?? '', 255),
        'level' => (string) ($rule['level'] ?? ''),
        'message' => $this->clean($rule['message'] ?? '', 1000),
      ];
      // A rule the administrator never started is not an error.
      if ($clean['question'] === '' && $clean['value'] === '' && $clean['message'] === '') {
        continue;
      }
      $rules[] = $clean;
    }

    return [
      'steps' => $steps,
      'rules' => $rules,
      'default_result' => $this->clean($raw['default_result'] ?? '', 128) ?: 'Assessment complete',
    ];
  }

  /**
   * Validates a normalised definition.
   *
   * @return string[]
   *   Human-readable problems; empty when the definition is valid.
   */
  public function validate(array $definition): array {
    $errors = [];
    $steps = $definition['steps'] ?? [];
    if (!$steps) {
      return ['Add at least one step with a question.'];
    }
    if (count($steps) > self::MAX_STEPS) {
      $errors[] = sprintf('A workflow can have at most %d steps.', self::MAX_STEPS);
    }

    // Position of every question in reading order, used for "earlier" checks.
    $position = [];
    $by_id = [];
    foreach ($steps as $step) {
      foreach ($step['questions'] as $question) {
        $position[$question['id']] = count($position);
        $by_id[$question['id']] = $question;
      }
    }

    foreach ($steps as $s => $step) {
      $n = $s + 1;
      if ($step['title'] === '') {
        $errors[] = sprintf('Step %d needs a title.', $n);
      }
      if (!$step['questions']) {
        $errors[] = sprintf('Step %d has no questions.', $n);
      }
      foreach ($step['questions'] as $q => $question) {
        $name = $question['label'] !== '' ? sprintf('"%s"', $question['label']) : sprintf('Question %d in step %d', $q + 1, $n);
        if ($question['label'] === '') {
          $errors[] = sprintf('%s needs question text.', $name);
        }
        if (in_array($question['type'], self::CHOICE_TYPES, TRUE) && count($question['options']) < 2) {
          $errors[] = sprintf('%s needs at least two answer choices.', $name);
        }
        if (isset($question['condition'])) {
          $source = $question['condition']['question'];
          $value = $question['condition']['value'];
          if ($source === '' || $value === '') {
            $errors[] = sprintf('%s: finish the "show only if" setting, or turn it off.', $name);
          }
          elseif (!isset($by_id[$source])) {
            $errors[] = sprintf('%s: the question it depends on no longer exists.', $name);
          }
          elseif ($position[$source] >= $position[$question['id']]) {
            $errors[] = sprintf('%s can only depend on a question that comes before it.', $name);
          }
          elseif (!in_array($by_id[$source]['type'], self::CHOICE_TYPES, TRUE) || !in_array($value, $by_id[$source]['options'], TRUE)) {
            $errors[] = sprintf('%s: "%s" is not one of the answers to the question it depends on.', $name, $value);
          }
        }
      }
    }

    foreach ($definition['rules'] ?? [] as $r => $rule) {
      $name = sprintf('Result rule %d', $r + 1);
      $source = $by_id[$rule['question']] ?? NULL;
      if ($rule['question'] === '' || $rule['value'] === '' || !isset(self::LEVELS[$rule['level']])) {
        $errors[] = sprintf('%s is incomplete: choose a question, an answer and a result level.', $name);
      }
      elseif (!$source) {
        $errors[] = sprintf('%s refers to a question that no longer exists.', $name);
      }
      elseif (!in_array($source['type'], self::CHOICE_TYPES, TRUE) || !in_array($rule['value'], $source['options'], TRUE)) {
        $errors[] = sprintf('%s: "%s" is not one of the answers to "%s".', $name, $rule['value'], $source['label']);
      }
    }
    return $errors;
  }

  /**
   * Trims a value and clips it to a maximum length.
   */
  private function clean(mixed $value, int $max): string {
    $value = is_scalar($value) ? trim((string) $value) : '';
    return mb_substr($value, 0, $max);
  }

  /**
   * Returns a safe, unique ID with the given prefix.
   */
  private function cleanId(mixed $id, string $prefix, array &$seen): string {
    $id = preg_replace('/[^A-Za-z0-9_]/', '', is_scalar($id) ? (string) $id : '');
    while ($id === '' || isset($seen[$id])) {
      $id = $prefix . substr(bin2hex(random_bytes(4)), 0, 6);
    }
    $seen[$id] = TRUE;
    return $id;
  }

}
