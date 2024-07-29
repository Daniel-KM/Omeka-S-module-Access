<?php declare(strict_types=1);

namespace Access\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * @see \Access\Site\BlockLayout\AccessRequest
 * @see \ContactUs\Site\BlockLayout\ContactUs
 */
class AccessRequest extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/block-layout/access-request';

    public function getLabel()
    {
        return 'Access request'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData();

        // Check and normalize options.
        $hasError = false;

        // The element ArrayTextarea is not managed by block.
        if (empty($data['fields'])) {
            $data['fields'] = [];
        } elseif (!is_array($data['fields'])) {
            $fields = $this->stringToList($data['fields']);
            $data['fields'] = [];
            foreach ($fields as $nameLabel) {
                [$name, $label] = is_array($nameLabel)
                    ? [key($nameLabel), reset($nameLabel)]
                    : (array_map('trim', explode('=', $nameLabel, 2)) + ['', '']);
                if ($name === '' || $label === '') {
                    $errorStore->addError('fields', 'To append fields, each row must contain a name and a label separated by a "=".'); // @translate
                    $hasError = true;
                }
                $data['fields'][$name] = $label;
            }
        }

        if ($hasError) {
            return;
        }

        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        // Factory is not used to make rendering simpler.
        $services = $site->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $defaultSettings = $services->get('Config')['access']['block_settings']['accessRequest'];
        $blockFieldset = \Access\Form\AccessRequestFieldset::class;

        $data = $block ? ($block->data() ?? []) + $defaultSettings : $defaultSettings;

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }
        $fieldset = $formElementManager->get($blockFieldset);
        $fieldset->populateValues($dataForm);

        $html = '<p class="explanation">'
            . $view->translate('Append a form to allow visitors to access request to a resource. The id of the resource should be passed as url argument.') // @translate
            . '</p>';
        $html .= $view->formCollection($fieldset, false);
        return $html;
    }

    public function prepareRender(PhpRenderer $view): void
    {
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-request.css', 'Access'));
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        $vars = ['block' => $block] + $block->data();
        $vars['resource'] = null;

        $id = $view->params()->fromQuery('id');
        if ($id) {
            try {
                $vars['resource'] = $view->api()->read('items', ['id' => $id])->getContent();
            } catch (\Exception $e) {
                // Nothing.
            }
        }

        return $view->partial($templateViewScript, $vars);
    }

    /**
     * Get each line of a string separately.
     *
     * @param string $string
     * @return array
     */
    protected function stringToList($string)
    {
        if (is_array($string)) {
            return $string;
        }
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))));
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     *
     * @param string $string
     * @return string
     */
    protected function fixEndOfLine($string)
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $string);
    }
}
