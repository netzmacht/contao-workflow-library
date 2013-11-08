<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 04.11.13
 * Time: 08:10
 */

namespace Workflow\Handler;

use DcaTools\Definition;
use DcaTools\Model\FilterBuilder;
use DcGeneral\Data\ModelInterface;
use Workflow\Exception\WorkflowException;
use Workflow\Flow\NextStateInterface;
use Workflow\Flow\Process;
use Workflow\Flow\Step;


class ProcessFactory
{

	/**
	 * @param string $name name or id
	 *
	 * @return Process
	 * @throws \Workflow\Exception\WorkflowException
	 */
	public static function create($name)
	{
		if(is_numeric($name))
		{
			return static::createById($name);
		}

		return static::createByName($name);
	}


	/**
	 * @param $id
	 *
	 * @return Process
	 * @throws \Workflow\Exception\WorkflowException
	 */
	public static function createById($id)
	{
		/** @var \Workflow\Data\DriverManager $driverManager */
		$driverManager = $GLOBALS['container']['workflow.driver-manager'];
		$driver = $driverManager->getDataProvider('tl_workflow_process');

		$config = $driver->getEmptyConfig();
		$config->setId($id);

		$model = $driver->fetch($config);

		if($model === null)
		{
			throw new WorkflowException(sprintf('Unknown process with ID "%s"', $id));
		}

		return static::createFromModel($model);
	}


	/**
	 * @param $name
	 *
	 * @return Process
	 * @throws \Workflow\Exception\WorkflowException
	 */
	public static function createByName($name)
	{
		/** @var \Workflow\Data\DriverManager $driverManager */
		$driverManager = $GLOBALS['container']['workflow.driver-manager'];
		$driver = $driverManager->getDataProvider('tl_workflow_process');

		$config = FilterBuilder::create()->addEquals('name', $name)->getConfig($driver);
		$model = $driver->fetch($config);

		if($model === null)
		{
			throw new WorkflowException(sprintf('Unknown process with name "%s"', $name));
		}

		return static::createFromModel($model);
	}


	/**
	 * @param ModelInterface $model
	 *
	 * @return Process
	 *
	 * @throws \Workflow\Exception\WorkflowException
	 */
	public static function createFromModel(ModelInterface $model)
	{
		/** @var \Workflow\Data\DriverManager $driverManager */
		$driverManager = $GLOBALS['container']['workflow.driver-manager'];
		$driver = $driverManager->getDataProvider('tl_workflow_step');

		$config = FilterBuilder::create()->addEquals('pid', $model->getId())->getConfig($driver);
		$stepsCollection = $driver->fetchAll($config);

		$steps = array();
		$stepsConfig = array();
		$start = null;
		$end = array();

		// pre generate config array, so we can find children steps
		foreach($stepsCollection as $step)
		{
			/** @var \DcGeneral\Data\ModelInterface $step */
			$stepsConfig[$step->getId()] = $step->getPropertiesAsArray();
		}

		foreach($stepsConfig as $step)
		{
			// step could has already been created by recursive calling
			if(!isset($steps[$step['name']]))
			{
				$steps[$step['name']] = static::createStep($step, $steps, $stepsConfig);
			}

			if($step['start'])
			{
				$start = $step['name'];
			}

			if($step['end'])
			{
				$end[$step['name']] = $steps[$step['name']];
			}
		}

		return new Process($model->getProperty('name'), $steps, $start, $end);
	}


	/**
	 * @param $stepConfig
	 * @param Step[] $steps
	 * @param array $stepsConfig
	 *
	 * @return Step
	 *
	 * @throws \InvalidArgumentException
	 */
	protected static function createStep($stepConfig, array &$steps, array $stepsConfig)
	{
		$nextStates = deserialize($stepConfig['next_states'], true);
		$roles      = deserialize($stepConfig['roles'], true);
		$onInvalid  = $stepConfig['invalid'] ?: null;

		$step = new Step($stepConfig['name'], $stepConfig['label'], array(), array('setStatus', $stepConfig['name']), $roles, $onInvalid);
		$steps[$stepConfig['name']] = $step;

		foreach($nextStates as $state)
		{
			if (NextStateInterface::TARGET_TYPE_STEP === $state['type'])
			{
				// step does not exist anymore
				if(!isset($stepsConfig[$state['target']]))
				{
					continue;
				}
				elseif(!isset($steps[$stepsConfig[$state['target']]['name']]))
				{
					$steps[$state['target']] = static::createStep($stepsConfig[$state['target']], $steps, $stepsConfig);
					$target = $steps[$state['target']];
				}
				else {
					$target = $steps[$stepsConfig[$state['target']]['name']];
				}
			}
			elseif(NextStateInterface::TARGET_TYPE_PROCESS === $state['type'])
			{
				$target = static::createById($state['target']);
			}
			else
			{
				throw new \InvalidArgumentException(sprintf('Unknown type "%s", please use "step" or "process"', $state['type']));
			}

			$step->addNextState($state['name'], $state['type'], $target);
		}

		return $step;
	}

}