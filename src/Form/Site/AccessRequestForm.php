<?php declare(strict_types=1);

namespace AccessResource\Form\Site;

use Laminas\Form\Element;
use Laminas\Form\Form;

class AccessRequestForm extends Form
{
    /**
     * @var bool
     */
    protected $fullAccess = false;

    /**
     * @var \Omeka\Api\Representation\\AbstractResourceEntityRepresentation[]
     */
    protected $resources = [];

    /**
     * @var \Omeka\Entity\User
     */
    protected $user = null;

    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options ?? []);
        if (isset($options['full_access'])) {
            $this->fullAccess = (bool) $options['full_access'];
        }
        if (isset($options['resources'])) {
            $this->resources = is_array($options['resources']) ? $options['resources'] : [$options['resources']->id() => $options['resources']];
        }
        if (isset($options['user'])) {
            $this->user = $options['user'];
        }
    }

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-access-request');

        if (!$this->user) {
            $this
                ->add([
                    'name' => 'o:name',
                    'type' => Element\Text::class,
                    'options' => [
                        'label' => 'Name', // @translate
                    ],
                    'attributes' => [
                        'id' => 'o-name',
                        'required' => false,
                    ],
                ])
                ->add([
                    'name' => 'o:email',
                    'type' => Element\Email::class,
                    'options' => [
                        'label' => 'Email', // @translate
                    ],
                    'attributes' => [
                        'id' => 'o-email',
                        'required' => true,
                    ],
                ])
                ->add([
                    'name' => 'o:message',
                    'type' => Element\Textarea::class,
                    'options' => [
                        'label' => 'Message', // @translate
                    ],
                    'attributes' => [
                        'id' => 'o-message',
                        'required' => true,
                    ],
                ])
            ;
        }

        $valueOptions = [];
        foreach ($this->resources as $id => $resource) {
            $valueOptions[$id] = $resource->linkPretty();
        }

        $this
            ->add([
                'name' => 'o:resource',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'value_options' => $valueOptions,
                    'label_options' => [
                        'disable_html_escape' => true,
                    ],
                    // TODO Find a way to load the list of resources in RequestController.
                    'disable_inarray_validator' => true,
                ],
                'attributes' => [
                    'id' => 'o-resource',
                    'required' => true,
                    'value' => count($valueOptions) === 1
                        ? [key($valueOptions)]
                        : [],
                ],
            ])
            ->add([
                'type' => Element\Submit::class,
                'name' => 'submit',
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Send', // @translate
                ],
            ]);
    }
}
