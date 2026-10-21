<?php

namespace Drupal\workflow_engine\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\workflow_engine\Entity\Workflow;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Publishes or unpublishes a workflow in one click.
 */
class WorkflowToggleController extends ControllerBase {

  /**
   * Flips the published state, then returns to the workflow list.
   */
  public function toggle(Workflow $workflow_engine_workflow): RedirectResponse {
    $publish = !$workflow_engine_workflow->isPublished();
    $workflow_engine_workflow->setStatus($publish)->save();
    $this->messenger()->addStatus($publish
      ? $this->t('%name is now published.', ['%name' => $workflow_engine_workflow->label()])
      : $this->t('%name is now unpublished and hidden from visitors.', ['%name' => $workflow_engine_workflow->label()]));
    return $this->redirect('entity.workflow_engine_workflow.collection');
  }

}
