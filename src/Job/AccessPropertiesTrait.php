<?php declare(strict_types=1);

namespace AccessResource\Job;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use Omeka\Stdlib\Message;

trait AccessPropertiesTrait
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $hasNumericDataTypes;

    /**
     * @var bool
     */
    protected $accessViaProperty;

    /**
     * @var string
     */
    protected $propertyLevel;

    /**
     * @var int
     */
    protected $propertyLevelId;

    /**
     * @var string
     */
    protected $propertyEmbargoStart;

    /**
     * @var int
     */
    protected $propertyEmbargoStartId;

    /**
     * @var string
     */
    protected $propertyEmbargoEnd;

    /**
     * @var int
     */
    protected $propertyEmbargoEndId;

    /**
     * For mode property / level, list the possible levels.
     *
     * @var array
     */
    protected $statusLevels = [];

    protected function prepareProperties(bool $useLogger = false): bool
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        if (!$useLogger) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
        }

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('NumericDataTypes');
        $this->hasNumericDataTypes = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        $this->accessViaProperty = (bool) $settings->get('accessresource_property');

        $this->propertyLevel = $settings->get('accessresource_property_level');
        $this->propertyEmbargoStart = $settings->get('accessresource_property_embargo_start');
        $this->propertyEmbargoEnd = $settings->get('accessresource_property_embargo_end');
        $this->statusLevels = $settings->get('accessresource_property_levels', []);

        $hasError = false;

        if ($this->propertyLevel) {
            $this->propertyLevelId = $this->api->search('properties', ['term' => $this->propertyLevel], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyLevelId) {
                $this->propertyLevelId = (int) reset($this->propertyLevelId);
            } else {
                $hasError = true;
                $message = new Message(
                    'Property "%1$s" for level does not exist.', // @translate
                    $this->propertyLevel
                );
                $useLogger ? $this->logger->err($message) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new Message(
                'Property to set access level is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message) : $messenger->addError($message);
        }

        if ($this->propertyEmbargoStart) {
            $this->propertyEmbargoStartId = $this->api->search('properties', ['term' => $this->propertyEmbargoStart], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyEmbargoStartId) {
                $this->propertyEmbargoStartId = (int) reset($this->propertyEmbargoStartId);
            } else {
                $hasError = true;
                $message = new Message(
                    'Property "%1$s" for embargo start does not exist.', // @translate
                    $this->propertyEmbargoStart
                );
                $useLogger ? $this->logger->err($message) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new Message(
                'Property to set embargo start is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message) : $messenger->addError($message);
        }

        if ($this->propertyEmbargoEnd) {
            $this->propertyEmbargoEndId = $this->api->search('properties', ['term' => $this->propertyEmbargoEnd], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyEmbargoEndId) {
                $this->propertyEmbargoEndId = (int) reset($this->propertyEmbargoEndId);
            } else {
                $hasError = true;
                $message = new Message(
                    'Property "%1$s" for embargo end does not exist.', // @translate
                    $this->propertyEmbargoEnd
                );
                $useLogger ? $this->logger->err($message) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new Message(
                'Property to set embargo end is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message) : $messenger->addError($message);
        }

        // This is not an error since default levels may be used, but this is an
        // sensitive job because it modifies values, so stop it.
        if (count(array_intersect_key($this->statusLevels, AccessStatusRepresentation::LEVELS)) !== 4) {
            $hasError = true;
            $message = new Message(
                'List of property levels is incomplete, missing "%s".', // @translate
                implode('", "', array_diff_key(AccessStatusRepresentation::LEVELS, $this->statusLevels))
            );
            $useLogger ? $this->logger->err($message) : $messenger->addError($message);
        } else {
            $this->statusLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $this->statusLevels), AccessStatusRepresentation::LEVELS);
        }

        // Don't repeat messages.
        $this->accessViaProperty = $this->accessViaProperty
            && $this->propertyLevelId
            && $this->propertyEmbargoStartId
            && $this->propertyEmbargoEndId;

        return !$hasError;
    }
}
