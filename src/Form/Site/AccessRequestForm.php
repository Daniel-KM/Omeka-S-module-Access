<?php declare(strict_types=1);

namespace Access\Form\Site;

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

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var string
     */
    protected $consentLabel = '';

    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options);

        if (isset($options['full_access'])) {
            $this->fullAccess = (bool) $options['full_access'];
        }
        if (isset($options['resources'])) {
            $this->resources = is_array($options['resources']) ? $options['resources'] : [$options['resources']->id() => $options['resources']];
        }
        if (isset($options['user'])) {
            $this->user = $options['user'];
        }
        if (isset($options['fields'])) {
            $this->fields = $options['fields'];
        }
        if (isset($options['consent_label'])) {
            $this->consentLabel = $options['consent_label'];
        }
    }

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-access-request')
            ->setName('form-access-request');

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
            ;
        }

        $this
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

        foreach ($this->fields ?? [] as $name => $data) {
            if ($name === 'id') {
                $name = 'id[]';
            }
            if (!is_array($data)) {
                $data = ['label' => $data, 'type' => Element\Text::class];
            }
            $isMultiple = substr($name, -2) === '[]';
            if ($isMultiple) {
                $fieldType = $data['type'] ?? Element\Select::class;
                $fieldValue = isset($data['value']) ? (is_array($data['value']) ? $data['value'] : [$data['value']]) : [];
                if ($fieldType === 'hidden' || $fieldType === Element\Hidden::class) {
                    $fieldValue = json_encode($fieldValue);
                }
                $this
                    ->add([
                        'name' => 'fields[' . substr($name, 0, -2) . '][]',
                        'type' => $fieldType,
                        'options' => [
                            'label' => $data['label'] ?? '',
                            'value_options' => $data['value_options'] ?? [],
                        ],
                        'attributes' => [
                            'id' => 'fields-' . substr($name, 0, -2),
                            'class' => $data['class'] ?? '',
                            'multiple' => 'multiple',
                            'value' => $fieldValue,
                        ],
                    ]);
            } else {
                $this
                    ->add([
                        'name' => 'fields[' . $name . ']',
                        'type' => $data['type'] ?? Element\Text::class,
                        'options' => [
                            'label' => $data['label'] ?? '',
                        ],
                        'attributes' => [
                            'id' => 'fields-' . $name,
                            'class' => $data['class'] ?? '',
                            'value' => $data['value'] ?? '',
                        ],
                    ]);
            }
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
        ;

        if ($this->user || !$this->consentLabel) {
            $this
                ->add([
                    'name' => 'consent',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'consent',
                        'value' => true,
                    ],
                ]);
        } else {
            $this
                ->add([
                    'name' => 'consent',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => $this->consentLabel,
                        'label_attributes' => [
                            'class' => 'required',
                        ],
                    ],
                    'attributes' => [
                        'id' => 'consent',
                        'required' => true,
                    ],
                ]);
        }

        $this
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
