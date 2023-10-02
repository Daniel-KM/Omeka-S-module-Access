<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Form\Site\AccessRequestForm;
use Laminas\Form\FormElementManager;
use Laminas\View\Helper\AbstractHelper;

class AccessRequest extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/access-request';

    /**
     * @var \Access\View\Helper\IsAccessRequestable
     */
    protected $isAccessRequestable;

    /**
     * @var \Laminas\Form\FormElementManager;
     */
    protected $formElementManager;

    /**
     * @var array
     */
    protected $options = [
        'template' => self::PARTIAL_NAME,
        'request_type' => 'items',
    ];

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $resources;

    /**
     * @var array
     */
    protected $requestableResources;

    /**
     * @var array
     */
    protected $requestableMedias;

    public function __construct(
        IsAccessRequestable $isAccessRequestable,
        FormElementManager $formElementManager
    ) {
        $this->isAccessRequestable = $isAccessRequestable;
        $this->formElementManager = $formElementManager;
    }

    /**
     * Prepare the user request resource(s) access form.
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]|\Omeka\Api\Representation\AbstractResourceEntityRepresentation|null $resources
     * @param array $options Options are passed to the template to render.
     * - template (string): The template to use.
     * - request_type (string): The level to check for requestable resource
     *   (items , media, item sets). This param is currently not managed because
     *   full access is not managed: only media content is checked.
     * - fields
     * - consent_label
     */
    public function __invoke($resources = null, ?array $options = null): self
    {
        if ($resources !== null) {
            $this->setResources($resources);
        }
        if ($options !== null) {
            $this->setOptions($options);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    public function setOptions(array $options): self
    {
        $this->options = $options + [
            'template' => self::PARTIAL_NAME,
            'request_type' => 'items',
        ];
        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]|\Omeka\Api\Representation\AbstractResourceEntityRepresentation|null $resources
     */
    public function setResources($resources): self
    {
        $this->resources = $resources && !is_array($resources)
            ? [$resources]
            : ($resources ?: []);
        return $this;
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $resources
     */
    public function getResources(): array
    {
        return $this->resources ?: [];
    }

    public function hasRequestableResources(): bool
    {
        $requestables = $this->getRequestableResources();
        return (bool) $requestables;
    }

    /**
     * Get the requestable resources for the current user.
     *
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function getRequestableResources(): array
    {
        if (is_array($this->requestableResources)) {
            return $this->requestableResources;
        }

        // Skip process for users with full access.
        $user = $this->view->identity();
        if ($user && $this->view->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            $this->requestableResources = [];
            return $this->requestableResources;
        }

        // $requestType = $this->getOptions['request_type'];

        $this->requestableResources = [];

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        foreach ($this->getResources() as $resource) {
            if ($this->isAccessRequestable->__invoke($resource)) {
                $this->requestableResources[$resource->id()] = $resource;
            }
        }

        return $this->requestableResources;
    }

    public function form(): AccessRequestForm
    {
        /** @var \Access\Form\Site\AccessRequestForm $form */
        $formOptions = [
            'full_access' => (bool) $this->view->setting('access_full'),
            'resources' => $this->getRequestableResources(),
            'user' => $this->view->identity(),
            'fields' => $this->options['fields'] ?? [],
            'consent_label' => $this->options['consent_label'] ?? '',
        ];
        /** @var \Access\Form\Site\AccessRequestForm $form */
        $form = $this->formElementManager->get(AccessRequestForm::class, $formOptions);
        $form->setOptions($formOptions);
        return $form;
    }

    public function render(): string
    {
        $vars = $this->getOptions();
        $template = $vars['template'] ?: self::PARTIAL_NAME;

        $vars += [
            'user' => $this->view->identity(),
            'resources' => $this->getResources(),
            'requestType' => $vars['request_type'],
            'requestables' => $this->getRequestableResources(),
            'form' => $this->form(),
        ];

        unset($vars['template']);
        unset($vars['request_type']);

        return $template !== self::PARTIAL_NAME && $this->view->resolver($template)
            ? $this->view->partial($template, $vars)
            : $this->view->partial(self::PARTIAL_NAME, $vars);
    }
}
