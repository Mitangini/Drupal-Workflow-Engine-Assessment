<?php

namespace Drupal\Tests\workflow_engine\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workflow_engine\WorkflowLogic;

/**
 * Tests the conditional, validation and risk logic.
 *
 * @coversDefaultClass \Drupal\workflow_engine\WorkflowLogic
 * @group workflow_engine
 */
class WorkflowLogicTest extends UnitTestCase {

  /**
   * Returns the Property Lease sample as a definition.
   */
  protected function sample(): array {
    $choice = fn($id, $label, $type, array $options, $required = FALSE, $condition = NULL) => array_filter([
      'id' => $id, 'label' => $label, 'type' => $type, 'required' => $required,
      'options' => $options, 'condition' => $condition,
    ], fn($v) => $v !== NULL);
    return [
      'steps' => [
        ['id' => 's1', 'title' => 'Applicant Details', 'questions' => [
          $choice('name', 'Full Name', 'text', [], TRUE),
          $choice('email', 'Email Address', 'email', [], TRUE),
        ]],
        ['id' => 's2', 'title' => 'Property Details', 'questions' => [
          $choice('type', 'Property Type', 'select', ['Residential', 'Commercial'], TRUE),
          $choice('use', 'Personal use or rental?', 'select', ['Personal Use', 'Rental'], TRUE, ['question' => 'type', 'value' => 'Residential']),
          $choice('biz', 'Business type', 'text', [], FALSE, ['question' => 'type', 'value' => 'Commercial']),
          $choice('public', 'Public access?', 'radio', ['Yes', 'No'], FALSE, ['question' => 'type', 'value' => 'Commercial']),
        ]],
        ['id' => 's3', 'title' => 'Lease Assessment', 'questions' => [
          $choice('lease', 'Signed lease?', 'radio', ['Yes', 'No'], TRUE),
        ]],
      ],
      'rules' => [
        ['id' => 'r1', 'question' => 'lease', 'value' => 'No', 'level' => 'high', 'message' => 'Get a lease.'],
        ['id' => 'r2', 'question' => 'lease', 'value' => 'Yes', 'level' => 'low', 'message' => 'Fine.'],
      ],
      'default_result' => 'Assessment complete',
    ];
  }

  /**
   * Residential shows the residential question and hides commercial ones.
   */
  public function testConditionalVisibility(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $by_id = $logic->questionsById($def);
    $answers = ['type' => 'Residential'];
    $this->assertTrue($logic->isVisible($by_id['use'], $answers, $by_id));
    $this->assertFalse($logic->isVisible($by_id['biz'], $answers, $by_id));
    $this->assertFalse($logic->isVisible($by_id['public'], $answers, $by_id));

    $answers = ['type' => 'Commercial'];
    $this->assertFalse($logic->isVisible($by_id['use'], $answers, $by_id));
    $this->assertTrue($logic->isVisible($by_id['biz'], $answers, $by_id));
    $this->assertTrue($logic->isVisible($by_id['public'], $answers, $by_id));
  }

  /**
   * A question depending on a hidden question is hidden too.
   */
  public function testNestedConditionIsHiddenWithItsParent(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $def['steps'][1]['questions'][] = ['id' => 'deep', 'label' => 'Deep', 'type' => 'text', 'required' => FALSE, 'options' => [], 'condition' => ['question' => 'public', 'value' => 'Yes']];
    $by_id = $logic->questionsById($def);
    // "public" is hidden for Residential, so "deep" must be hidden even if a
    // stale "Yes" answer is still present.
    $this->assertFalse($logic->isVisible($by_id['deep'], ['type' => 'Residential', 'public' => 'Yes'], $by_id));
    $this->assertTrue($logic->isVisible($by_id['deep'], ['type' => 'Commercial', 'public' => 'Yes'], $by_id));
  }

  /**
   * Stale answers for hidden questions are dropped.
   */
  public function testHiddenAnswersAreDropped(): void {
    $logic = new WorkflowLogic();
    $clean = $logic->visibleAnswers($this->sample(), ['type' => 'Residential', 'use' => 'Rental', 'biz' => 'Cafe', 'bogus' => 'x']);
    $this->assertSame(['type' => 'Residential', 'use' => 'Rental'], $clean);
  }

  /**
   * Required fields are enforced only when visible.
   */
  public function testRequiredOnlyWhenVisible(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $errors = $logic->validateAnswers($def, ['name' => 'A', 'email' => 'a@b.co', 'type' => 'Commercial', 'lease' => 'Yes']);
    $this->assertSame([], $errors, 'Hidden required "use" must not block a commercial submission.');

    $errors = $logic->validateAnswers($def, ['name' => 'A', 'email' => 'a@b.co', 'type' => 'Residential', 'lease' => 'Yes']);
    $this->assertSame(['use'], array_keys($errors));

    $errors = $logic->validateAnswers($def, []);
    $this->assertSame(['name', 'email', 'type', 'lease'], array_keys($errors));
  }

  /**
   * Email format and choice membership are validated.
   */
  public function testEmailAndChoiceValidation(): void {
    $logic = new WorkflowLogic();
    $errors = $logic->validateAnswers($this->sample(), ['name' => 'A', 'email' => 'nope', 'type' => 'Industrial', 'lease' => 'Maybe']);
    $this->assertSame(['email', 'type', 'lease'], array_keys($errors));
  }

  /**
   * Validation can be limited to the current step.
   */
  public function testValidationLimitedToQuestionIds(): void {
    $logic = new WorkflowLogic();
    $errors = $logic->validateAnswers($this->sample(), [], ['name']);
    $this->assertSame(['name'], array_keys($errors));
  }

  /**
   * The two sample risk rules from the brief.
   */
  public function testSampleRiskRules(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $high = $logic->evaluate($def, ['lease' => 'No']);
    $this->assertSame('high', $high['level']);
    $this->assertSame('High Risk', $high['label']);
    $low = $logic->evaluate($def, ['lease' => 'Yes']);
    $this->assertSame('low', $low['level']);
    $this->assertSame('Low Risk', $low['label']);
  }

  /**
   * The most severe matching rule wins, regardless of rule order.
   */
  public function testMostSevereMatchWins(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $def['rules'] = [
      ['id' => 'a', 'question' => 'type', 'value' => 'Commercial', 'level' => 'medium', 'message' => ''],
      ['id' => 'b', 'question' => 'lease', 'value' => 'No', 'level' => 'high', 'message' => ''],
      ['id' => 'c', 'question' => 'public', 'value' => 'Yes', 'level' => 'low', 'message' => ''],
    ];
    $this->assertSame('high', $logic->evaluate($def, ['type' => 'Commercial', 'lease' => 'No', 'public' => 'Yes'])['level']);
    $this->assertSame('medium', $logic->evaluate($def, ['type' => 'Commercial', 'lease' => 'Yes'])['level']);
  }

  /**
   * A rule on a hidden question never fires; no match gives the default.
   */
  public function testDefaultResultAndHiddenRule(): void {
    $logic = new WorkflowLogic();
    $def = $this->sample();
    $def['rules'] = [['id' => 'a', 'question' => 'public', 'value' => 'Yes', 'level' => 'high', 'message' => '']];
    $result = $logic->evaluate($def, ['type' => 'Residential', 'public' => 'Yes']);
    $this->assertSame('', $result['level']);
    $this->assertSame('Assessment complete', $result['label']);
  }

  /**
   * The saved snapshot groups visible answers by step.
   */
  public function testSummaryGroupsByStep(): void {
    $logic = new WorkflowLogic();
    $summary = $logic->summarize($this->sample(), ['name' => 'Asha', 'email' => 'a@b.co', 'type' => 'Residential', 'use' => 'Rental', 'biz' => 'ignored', 'lease' => 'No']);
    $this->assertCount(3, $summary);
    $this->assertSame('Property Details', $summary[1]['title']);
    $this->assertSame([['label' => 'Property Type', 'value' => 'Residential'], ['label' => 'Personal use or rental?', 'value' => 'Rental']], $summary[1]['items']);
  }

  /**
   * A well-formed definition passes validation.
   */
  public function testSampleDefinitionIsValid(): void {
    $logic = new WorkflowLogic();
    $this->assertSame([], $logic->validate($logic->normalize($this->sample())));
  }

  /**
   * Builder mistakes are reported in plain language.
   */
  public function testValidationMessages(): void {
    $logic = new WorkflowLogic();
    $raw = [
      'steps' => [
        ['id' => 's1', 'title' => '', 'questions' => [
          ['id' => 'a', 'label' => 'Colour', 'type' => 'select', 'options' => ['Red', 'Red', '']],
          ['id' => 'b', 'label' => 'Why red?', 'type' => 'text', 'condition' => ['question' => 'a', 'value' => 'Blue']],
          ['id' => 'c', 'label' => '', 'type' => 'text'],
        ]],
        ['id' => 's2', 'title' => 'Empty', 'questions' => []],
      ],
      'rules' => [['question' => 'a', 'value' => 'Red', 'level' => '', 'message' => '']],
    ];
    $definition = $logic->normalize($raw);
    $this->assertSame(['Red'], $definition['steps'][0]['questions'][0]['options'], 'Choices are trimmed and de-duplicated.');
    $text = implode("\n", $logic->validate($definition));
    $this->assertStringContainsString('Step 1 needs a title', $text);
    $this->assertStringContainsString('at least two answer choices', $text);
    $this->assertStringContainsString('"Blue" is not one of the answers', $text);
    $this->assertStringContainsString('needs question text', $text);
    $this->assertStringContainsString('Step 2 has no questions', $text);
    $this->assertStringContainsString('Result rule 1 is incomplete', $text);
  }

  /**
   * Conditions must point at an earlier question, never forward or at itself.
   */
  public function testConditionMustReferenceEarlierQuestion(): void {
    $logic = new WorkflowLogic();
    $raw = ['steps' => [['id' => 's1', 'title' => 'One', 'questions' => [
      ['id' => 'a', 'label' => 'First', 'type' => 'text', 'condition' => ['question' => 'b', 'value' => 'Yes']],
      ['id' => 'b', 'label' => 'Second', 'type' => 'radio', 'options' => ['Yes', 'No']],
    ]]]];
    $this->assertStringContainsString('comes before it', implode("\n", $logic->validate($logic->normalize($raw))));
  }

  /**
   * More than three steps is rejected.
   */
  public function testMaxThreeSteps(): void {
    $logic = new WorkflowLogic();
    $step = fn($i) => ['id' => 's' . $i, 'title' => 'S' . $i, 'questions' => [['id' => 'q' . $i, 'label' => 'Q', 'type' => 'text']]];
    $raw = ['steps' => [$step(1), $step(2), $step(3), $step(4)]];
    $this->assertStringContainsString('at most 3 steps', implode("\n", $logic->validate($logic->normalize($raw))));
  }

  /**
   * IDs are sanitised and made unique.
   */
  public function testNormalizeMakesIdsSafeAndUnique(): void {
    $logic = new WorkflowLogic();
    $raw = ['steps' => [['id' => 'x', 'title' => 'T', 'questions' => [
      ['id' => 'q 1!', 'label' => 'A', 'type' => 'text'],
      ['id' => 'q1', 'label' => 'B', 'type' => 'bogus'],
      ['label' => 'C'],
    ]]]];
    $questions = $logic->normalize($raw)['steps'][0]['questions'];
    $ids = array_column($questions, 'id');
    $this->assertCount(3, array_unique($ids));
    $this->assertSame('q1', $ids[0]);
    $this->assertSame('text', $questions[1]['type'], 'Unknown types fall back to text.');
  }

}
