<?php

namespace Workflow\Controller;

use DcGeneral\Data\ModelInterface as EntityInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Workflow\Entity\Registry;
use Workflow\Model\Model;
use Workflow\Model\ModelInterface;

/**
 * Controller is used for loading workflow of an entity.
 *
 * There should not be an call to the controller without calling the initialize method because workflow service
 * can assign another current model. Always use like this:
 *
 * if($controller->initialize($entity))
 * {
 *     $controller->reachNextState('published');
 * }
 *
 * @package Workflow\Controller
 * @author David Molineus <molineus@netzmacht.de>
 */
class Controller
{

	/**
	 * @var \DcaTools\Data\DriverManagerInterface
	 */
	protected $driverManager;

	/**
	 * @var \Symfony\Component\EventDispatcher\EventDispatcher
	 */
	protected $eventDispatcher;

	/**
	 * @var \Workflow\Controller\WorkflowManager
	 */
	protected $workflowManager;

	/**
	 * @var \Workflow\Model\ModelInterface
	 */
	protected $currentModel;

	/**
	 * @var \Workflow\Controller\WorkflowInterface
	 */
	protected $currentWorkflow;

	/**
	 * @var \Workflow\Entity\ModelState|null
	 */
	protected $currentState;

	/**
	 * @var
	 */
	protected $requestAction;

	/**
	 * @var UserInterface
	 */
	protected $user;


	/**
	 * @param WorkflowManager $workflowManager
	 * @param \DcaTools\Data\DriverManagerInterface $driverManager
	 * @param Registry $registry
	 * @param EventDispatcher $eventDispatcher
	 */
	public function __construct(WorkflowManager $workflowManager, $driverManager, Registry $registry, EventDispatcher $eventDispatcher)
	{
		$this->driverManager   = $driverManager;
		$this->eventDispatcher = $eventDispatcher;
		$this->registry        = $registry;

		// DO NOT call setController before objects are assigned to controller
		$this->workflowManager = $workflowManager;
		$this->workflowManager->setController($this);
	}


	/**
	 * @param EntityInterface $entity
	 * @return bool|\Workflow\Entity\ModelState
	 */
	public function initialize(EntityInterface $entity)
	{
		if($this->isInitialized($entity))
		{
			return true;
		}

		$this->currentModel    = new Model($entity, $this);
		$this->currentWorkflow = $this->workflowManager->getAssignedWorkflow($entity);

		if($this->currentWorkflow && $this->currentWorkflow->hasProcess($entity->getProviderName()))
		{
			$this->currentState = $this->getProcessHandler()->getCurrentState($this->currentModel);

			if(!$this->currentState)
			{
				$this->currentState = $this->getProcessHandler()->start($this->currentModel);
			}

			return $this->currentState;
		}

		return false;
	}


	/**
	 * @param EntityInterface $entity
	 * @return bool
	 */
	public function isInitialized(EntityInterface $entity)
	{
		if($this->currentModel)
		{
			$current = $this->currentModel->getEntity();

			if($current->getProviderName() && $entity->getProviderName() && $current->getId() == $entity->getId())
			{
				return true;
			}
		}

		return false;
	}


	/**
	 * @param $stepName
	 * @return bool
	 */
	public function checkCredentials($stepName)
	{
		$processes = $this->currentWorkflow->getProcessConfiguration();
		$tableName = $this->currentModel->getEntity()->getProviderName();
		$process   = $this->getProcessHandler()->getProcess($processes[$tableName]);

		return $this->getProcessHandler()->checkCredentials($this->currentModel, $process->getStep($stepName), $process->getName());
	}


	/**
	 * @return \Workflow\Entity\ModelState|null
	 */
	public function getCurrentState()
	{
		return $this->currentState;
	}


	/**
	 * @return WorkflowInterface
	 */
	public function getCurrentWorkflow()
	{
		return $this->currentWorkflow;
	}


	/**
	 * @param WorkflowInterface $workflow
	 */
	public function setCurrentWorkflow(WorkflowInterface $workflow)
	{
		$this->currentWorkflow = $workflow;
		$this->currentWorkflow->setController($this);
	}


	/**
	 * @return ModelInterface
	 */
	public function getCurrentModel()
	{
		return $this->currentModel;
	}


	/**
	 * @param $action
	 */
	public function setRequestAction($action)
	{
		$this->requestAction = $action;
	}


	/**
	 * @return mixed
	 */
	public function getRequestAction()
	{
		return $this->requestAction;
	}


	/**
	 * @param \Workflow\Controller\UserInterface $user
	 */
	public function setUser($user)
	{
		$this->user = $user;
	}


	/**
	 * @return \Workflow\Controller\UserInterface
	 */
	public function getUser()
	{
		return $this->user;
	}


	/**
	 * @param $stateName
	 * @return \Workflow\Entity\ModelState
	 */
	public function reachNextState($stateName)
	{
		return $this->getProcessHandler()->reachNextState($this->currentModel, $stateName);
	}


	/**
	 * @param string $tableName
	 * @return \Workflow\Handler\ProcessHandlerInterface
	 */
	public function getProcessHandler($tableName=null)
	{
		$tableName = $tableName ?: $this->currentModel->getEntity()->getProviderName();
		return $this->currentWorkflow->getProcessHandler($tableName);
	}


	/**
	 * @param $tableName
	 * @return \DcGeneral\Data\DriverInterface
	 */
	public function getDataProvider($tableName)
	{
		return $this->driverManager->getDataProvider($tableName);
	}


	/**
	 * @return EventDispatcher
	 */
	public function getEventDispatcher()
	{
		return $this->eventDispatcher;
	}


	/**
	 * @return Registry
	 */
	public function getEntityRegistry()
	{
		return $this->registry;
	}

}
