<?php declare(strict_types=1);

namespace AccessTest;

use Omeka\Test\AbstractHttpControllerTestCase;
use ReflectionMethod;

/**
 * Tests the check of the folders of the module Derivative Media.
 *
 * The rewrite rule of Access only protects the folders of Omeka, so the
 * derivatives built by Derivative Media (zip, pdf, alto, mp3, etc.) stay
 * downloadable directly unless they are added to the rule. The module warns
 * about it on the configuration page.
 *
 * @covers \Access\Module::checkDerivativeMediaFolders
 * @covers \Access\Module::derivativeMediaFolders
 * @group integration
 */
class DerivativeMediaFoldersTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    /**
     * @var \Access\Module
     */
    protected $accessModule;

    /**
     * @var ReflectionMethod
     */
    protected $foldersMethod;

    /**
     * @var array
     */
    protected $settingsBackup = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $this->accessModule = $this->getServiceLocator()->get('ModuleManager')->getModule('Access');
        $this->assertNotNull($this->accessModule, 'Access module not found in Laminas ModuleManager.');
        if (!$this->accessModule->getServiceLocator()) {
            $this->accessModule->setServiceLocator($this->getServiceLocator());
        }

        $this->foldersMethod = new ReflectionMethod($this->accessModule, 'derivativeMediaFolders');
        $this->foldersMethod->setAccessible(true);

        $settings = $this->getSettings();
        foreach ([
            'derivativemedia_converters_audio',
            'derivativemedia_converters_video',
            'access_htaccess_custom_types',
            'access_htaccess_skip',
        ] as $name) {
            $this->settingsBackup[$name] = $settings->get($name);
        }
    }

    public function tearDown(): void
    {
        $settings = $this->getSettings();
        foreach ($this->settingsBackup as $name => $value) {
            $value === null ? $settings->delete($name) : $settings->set($name, $value);
        }
        $this->logout();
        parent::tearDown();
    }

    /**
     * Load the class of the module Derivative Media, that holds the constant
     * listing the folders. The module may be installed but not active: the
     * folders it would create are worth checking anyway.
     */
    protected function requireDerivativeMediaModule(): void
    {
        if (class_exists('DerivativeMedia\Module', false)) {
            return;
        }
        $path = OMEKA_PATH . '/modules/DerivativeMedia/Module.php';
        if (!file_exists($path)) {
            $this->markTestSkipped('Requires the module Derivative Media.');
        }
        require_once $path;
    }

    protected function folders(): array
    {
        return $this->foldersMethod->invoke($this->accessModule, $this->getSettings());
    }

    /**
     * The folders of the item level derivatives come from the constant of the
     * module Derivative Media, so they must be listed even when no converter
     * is configured.
     */
    public function testItemLevelFoldersAreListed(): void
    {
        $this->requireDerivativeMediaModule();

        $folders = $this->folders();
        foreach (['alto', 'pdf', 'pdf2xml', 'txt', 'zip'] as $dir) {
            $this->assertContains($dir, $folders, sprintf('Missing the folder "%s".', $dir));
        }
    }

    /**
     * The folder of a media level derivative is the first segment of the
     * converter, that is stored as "folder/{filename}.extension".
     */
    public function testMediaLevelFoldersComeFromConverters(): void
    {
        $this->requireDerivativeMediaModule();

        $this->getSettings()->set('derivativemedia_converters_audio', [
            'mp3/{filename}.mp3' => '-c copy',
            'flac_hd/{filename}.flac' => '-c copy',
        ]);

        $folders = $this->folders();
        $this->assertContains('mp3', $folders);
        $this->assertContains('flac_hd', $folders);
    }

    /**
     * A comment is stored as a key beginning with a sharp: it is not a folder.
     */
    public function testCommentsAreNotFolders(): void
    {
        $this->requireDerivativeMediaModule();

        $this->getSettings()->set('derivativemedia_converters_video', [
            '# Convert videos for modern browsers.' => '',
            'webm/{filename}.webm' => '-c copy',
        ]);

        $folders = $this->folders();
        $this->assertContains('webm', $folders);
        foreach ($folders as $folder) {
            $this->assertStringNotContainsString('#', $folder);
            $this->assertStringNotContainsString(' ', $folder);
        }
    }

    /**
     * A converter without any folder, so writing at the root of files/, must
     * not add an empty or bogus entry.
     */
    public function testConverterWithoutFolderIsSkipped(): void
    {
        $this->requireDerivativeMediaModule();

        $this->getSettings()->set('derivativemedia_converters_audio', [
            '{filename}.mp3' => '-c copy',
        ]);

        $folders = $this->folders();
        $this->assertNotContains('', $folders);
        $this->assertNotContains('{filename}.mp3', $folders);
    }

    /**
     * The default types of Omeka are not folders of Derivative Media, so the
     * check must never ask to protect them twice.
     */
    public function testOmekaFoldersAreNotListed(): void
    {
        $this->requireDerivativeMediaModule();

        $folders = $this->folders();
        foreach (['original', 'large', 'medium', 'square'] as $dir) {
            $this->assertNotContains($dir, $folders);
        }
    }

    /**
     * The check is silent when the module Derivative Media is not active.
     */
    public function testCheckIsSilentWithoutDerivativeMedia(): void
    {
        if ($this->isDerivativeMediaActive()) {
            $this->markTestSkipped('The module Derivative Media is active.');
        }

        $this->assertSame([], $this->runCheck());
    }

    /**
     * Nothing to report when every folder is already covered by the rule.
     */
    public function testCheckIsSilentWhenAllFoldersAreCovered(): void
    {
        if (!$this->isDerivativeMediaActive()) {
            $this->markTestSkipped('Requires the module Derivative Media to be active.');
        }

        $this->getSettings()->set('access_htaccess_custom_types', implode(' ', $this->folders()));

        $this->assertSame([], $this->runCheck());
    }

    /**
     * A folder that exists and contains files is an error: the derivatives are
     * downloadable right now. A folder not created yet is only a warning.
     */
    public function testCheckReportsUncoveredFolders(): void
    {
        if (!$this->isDerivativeMediaActive()) {
            $this->markTestSkipped('Requires the module Derivative Media to be active.');
        }

        $this->getSettings()->set('access_htaccess_custom_types', '');

        $messages = $this->runCheck();
        $this->assertNotSame([], $messages, 'No message while no folder is covered.');

        $texts = [];
        foreach ($messages as $typeMessages) {
            foreach ($typeMessages as $message) {
                $texts[] = (string) $message;
            }
        }
        $this->assertStringContainsString(
            'Derivative Media',
            implode(' ', $texts)
        );
    }

    /**
     * The management of the .htaccess may be disabled: the check must then ask
     * for a manual configuration, in a single message.
     */
    public function testCheckAsksForManualRuleWhenManagementIsSkipped(): void
    {
        if (!$this->isDerivativeMediaActive()) {
            $this->markTestSkipped('Requires the module Derivative Media to be active.');
        }

        $settings = $this->getSettings();
        $settings->set('access_htaccess_custom_types', '');
        $settings->set('access_htaccess_skip', true);

        $messages = $this->runCheck();
        $count = 0;
        foreach ($messages as $typeMessages) {
            $count += count($typeMessages);
        }
        $this->assertSame(1, $count, 'Expected a single message when the management is skipped.');
    }

    /**
     * A folder holding an .htaccess that denies any direct web access needs no
     * rewrite rule, so it must not be reported.
     */
    public function testDeniedFolderIsNotReported(): void
    {
        if (!$this->isDerivativeMediaActive()) {
            $this->markTestSkipped('Requires the module Derivative Media to be active.');
        }

        $this->getSettings()->set('access_htaccess_custom_types', '');

        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');
        $denied = [];
        foreach ($this->folders() as $dir) {
            if (file_exists($basePath . '/' . $dir . '/.htaccess')) {
                $denied[] = $dir;
            }
        }
        if (!$denied) {
            $this->markTestSkipped('No folder is protected by a deny .htaccess.');
        }

        $texts = '';
        foreach ($this->runCheck() as $typeMessages) {
            foreach ($typeMessages as $message) {
                $texts .= ' ' . (string) $message;
            }
        }

        foreach ($denied as $dir) {
            $this->assertStringNotContainsString(
                $dir,
                $texts,
                sprintf('The folder "%s" is denied by its .htaccess but is reported.', $dir)
            );
        }
    }

    /**
     * A deny set on a parent applies to its children, like "files/iiif" for
     * "files/iiif/3".
     */
    public function testDenyOnParentProtectsChildren(): void
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        $isDenied = new ReflectionMethod($this->accessModule, 'isDirectoryDenied');
        $isDenied->setAccessible(true);

        $parent = $basePath . '/zz-test-deny';
        $child = $parent . '/sub';
        @mkdir($child, 0775, true);
        try {
            $this->assertFalse($isDenied->invoke($this->accessModule, $basePath, 'zz-test-deny/sub'));

            file_put_contents($parent . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
            $this->assertTrue($isDenied->invoke($this->accessModule, $basePath, 'zz-test-deny/sub'));
            $this->assertTrue($isDenied->invoke($this->accessModule, $basePath, 'zz-test-deny'));
        } finally {
            @unlink($parent . '/.htaccess');
            @rmdir($child);
            @rmdir($parent);
        }
    }

    /**
     * An .htaccess without any deny directive does not protect the folder.
     */
    public function testHtaccessWithoutDenyIsNotAProtection(): void
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        $isDenied = new ReflectionMethod($this->accessModule, 'isDirectoryDenied');
        $isDenied->setAccessible(true);

        $dir = $basePath . '/zz-test-allow';
        @mkdir($dir, 0775, true);
        try {
            file_put_contents($dir . '/.htaccess', "# A comment only.\nOptions -Indexes\n");
            $this->assertFalse($isDenied->invoke($this->accessModule, $basePath, 'zz-test-allow'));
        } finally {
            @unlink($dir . '/.htaccess');
            @rmdir($dir);
        }
    }

    protected function isDerivativeMediaActive(): bool
    {
        $module = $this->getServiceLocator()->get('Omeka\ModuleManager')->getModule('DerivativeMedia');
        return $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    protected function runCheck(): array
    {
        $messenger = $this->getServiceLocator()->get('ControllerPluginManager')->get('messenger');
        $messenger->clear();

        $check = new ReflectionMethod($this->accessModule, 'checkDerivativeMediaFolders');
        $check->setAccessible(true);
        $check->invoke($this->accessModule);

        $messages = $messenger->get();
        $messenger->clear();
        return $messages;
    }
}
