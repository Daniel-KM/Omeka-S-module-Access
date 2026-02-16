<?php declare(strict_types=1);

namespace AccessTest;

use Omeka\Test\AbstractHttpControllerTestCase;
use ReflectionMethod;

/**
 * Tests for the .htaccess management feature of the Access module.
 *
 * Each test writes a fixture to the real .htaccess file, calls the protected
 * manageHtaccess() method via reflection, and asserts the resulting file
 * content and settings.  The original .htaccess is backed up in setUp() and
 * restored in tearDown().
 *
 * @covers \Access\Module::manageHtaccess
 */
class ManageHtaccessTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    /**
     * @var string Original .htaccess content, saved for restoration.
     */
    protected $htaccessBackup;

    /**
     * @var string Path to the .htaccess file.
     */
    protected $htaccessPath;

    /**
     * @var \Access\Module The actual module class instance.
     */
    protected $accessModuleInstance;

    /**
     * @var ReflectionMethod
     */
    protected $manageHtaccessMethod;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * Minimal .htaccess without any access rule.
     */
    protected function fixtureNoRule(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with a managed rule (marker + comment + rule).
     */
    protected function fixtureManagedOriginalLarge(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large)/(.*)$" "access/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with a managed rule for original only.
     */
    protected function fixtureManagedOriginalOnly(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original)/(.*)$" "access/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with a managed rule that includes custom types.
     */
    protected function fixtureManagedWithCustomTypes(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large|mp3|mp4)/(.*)$" "access/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * Legacy rule: grouped format (original|large) without marker.
     */
    protected function fixtureLegacyGrouped(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

RewriteRule "^files/(original|large)/(.*)$" "/access/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * Legacy rule: individual format (separate lines, no group).
     */
    protected function fixtureLegacyIndividual(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

RewriteRule ^files/original/(.*)$ /access/files/original/$1 [NC,L]
RewriteRule ^files/large/(.*)$ /access/files/large/$1 [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * Legacy rule: individual format with full URL (subdomain).
     */
    protected function fixtureLegacyFullUrl(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

RewriteRule ^files/original/(.*)$ http://localdev/OmekaS/access/files/original/$1 [L]
RewriteRule "^files/large/(.*)$" "%{REQUEST_SCHEME}://%{HTTP_HOST}/OmekaS/access/files/large/$1" [L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * Legacy rule: grouped format with full URL including subdomain.
     */
    protected function fixtureLegacyGroupedFullUrl(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

RewriteRule "^files/(original|large)/(.*)$" "http://demo.example.org/OmekaS/access/files/$1/$2" [P]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with both Access and Analytics markers.
     */
    protected function fixtureBothMarkers(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large)/(.*)$" "access/files/$1/$2" [NC,L]

# Module Analytics: count downloads.
# This rule is automatically managed by the module.
RewriteRule "^files/(original)/(.*)$" "download/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with Analytics marker only (no Access marker).
     */
    protected function fixtureAnalyticsOnly(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Analytics: count downloads.
# This rule is automatically managed by the module.
RewriteRule "^files/(original)/(.*)$" "download/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with a mix of commented-out and active legacy rules.
     */
    protected function fixtureMixedCommentedActive(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

#RewriteRule ^files/original/(.*)$ http://localdev/OmekaS/access/files/original/$1 [NC,L]
# RewriteRule ^files/original/(.*)$ http://localdev/OmekaS/download/files/original/$1 [NC,L]
RewriteRule ^files/original/(.*)$ http://localdev/OmekaS/access/files/original/$1 [L]
#RewriteRule ^files/large/(.*)$ http://localdev/OmekaS/access/files/large/$1 [L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    /**
     * .htaccess with managed rule for all four standard types.
     */
    protected function fixtureManagedAllStandard(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Module Access: protect files.
# This rule is automatically managed by the module.
RewriteRule "^files/(original|large|medium|square)/(.*)$" "access/files/$1/$2" [NC,L]

# The following rule tells Apache that if the requested filename
# exists, simply serve it.
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !\.(php[0-9]?|phtml|phps)$ - [NC,C]
RewriteRule !(?:^|/)\.(?!well-known(?:/.*)?$) - [C]
RewriteRule .* - [L]
HTACCESS;
    }

    // ------------------------------------------------------------------
    // Setup / Teardown
    // ------------------------------------------------------------------

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $this->htaccessPath = OMEKA_PATH . '/.htaccess';
        $this->htaccessBackup = file_get_contents($this->htaccessPath);
        $this->assertNotFalse($this->htaccessBackup, 'Could not back up .htaccess.');

        // Get the actual \Access\Module instance from the Laminas ModuleManager.
        // The Laminas ModuleManager holds the bootstrapped module class instances.
        $laminasModuleManager = $this->getServiceLocator()->get('ModuleManager');
        $this->accessModuleInstance = $laminasModuleManager->getModule('Access');
        $this->assertNotNull($this->accessModuleInstance, 'Access module not found in Laminas ModuleManager.');

        // Ensure the service locator is set (it should be after onBootstrap).
        if (!$this->accessModuleInstance->getServiceLocator()) {
            $this->accessModuleInstance->setServiceLocator($this->getServiceLocator());
        }

        // The Module::manageHtaccess() is protected: make it accessible.
        $this->manageHtaccessMethod = new ReflectionMethod($this->accessModuleInstance, 'manageHtaccess');
        $this->manageHtaccessMethod->setAccessible(true);

        // Reset settings to a known state.
        $settings = $this->getSettings();
        $settings->set('access_htaccess_types', []);
        $settings->set('access_htaccess_custom_types', '');
    }

    public function tearDown(): void
    {
        // Restore the original .htaccess.
        if ($this->htaccessBackup !== null) {
            file_put_contents($this->htaccessPath, $this->htaccessBackup);
        }
        // Reset settings.
        $settings = $this->getSettings();
        $settings->set('access_htaccess_types', []);
        $settings->set('access_htaccess_custom_types', '');

        $this->logout();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Write a fixture to .htaccess and call manageHtaccess().
     *
     * @param string $fixture The .htaccess content to write.
     * @param array|null $types Null for read mode, array for write mode.
     */
    protected function writeFixtureAndManage(string $fixture, ?array $types = null): void
    {
        file_put_contents($this->htaccessPath, $fixture);
        $this->manageHtaccessMethod->invoke($this->accessModuleInstance, $types);
    }

    /**
     * Read the current .htaccess content.
     */
    protected function readHtaccess(): string
    {
        return file_get_contents($this->htaccessPath);
    }

    // ==================================================================
    // Read mode tests
    // ==================================================================

    /**
     * Read mode: no rule present → settings empty, error message expected.
     */
    public function testReadModeNoRule(): void
    {
        $this->writeFixtureAndManage($this->fixtureNoRule(), null);

        $settings = $this->getSettings();
        $this->assertSame([], $settings->get('access_htaccess_types'));
        $this->assertSame('', $settings->get('access_htaccess_custom_types'));
    }

    /**
     * Read mode: managed rule with original|large → settings synced.
     */
    public function testReadModeManagedOriginalLarge(): void
    {
        $this->writeFixtureAndManage($this->fixtureManagedOriginalLarge(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
        $this->assertSame('', $settings->get('access_htaccess_custom_types'));
    }

    /**
     * Read mode: managed rule with original only → settings synced.
     */
    public function testReadModeManagedOriginalOnly(): void
    {
        $this->writeFixtureAndManage($this->fixtureManagedOriginalOnly(), null);

        $settings = $this->getSettings();
        $this->assertSame(['original'], $settings->get('access_htaccess_types'));
        $this->assertSame('', $settings->get('access_htaccess_custom_types'));
    }

    /**
     * Read mode: managed rule with custom types → standard and custom split.
     */
    public function testReadModeManagedWithCustomTypes(): void
    {
        $this->writeFixtureAndManage($this->fixtureManagedWithCustomTypes(), null);

        $settings = $this->getSettings();
        $standardTypes = $settings->get('access_htaccess_types');
        sort($standardTypes);
        $this->assertSame(['large', 'original'], $standardTypes);
        $customTypes = $settings->get('access_htaccess_custom_types');
        $this->assertStringContainsString('mp3', $customTypes);
        $this->assertStringContainsString('mp4', $customTypes);
    }

    /**
     * Read mode: managed rule with all four standard types.
     */
    public function testReadModeManagedAllStandard(): void
    {
        $this->writeFixtureAndManage($this->fixtureManagedAllStandard(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'medium', 'original', 'square'], $types);
        $this->assertSame('', $settings->get('access_htaccess_custom_types'));
    }

    /**
     * Read mode: legacy grouped rule → settings synced, legacy detected.
     */
    public function testReadModeLegacyGrouped(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyGrouped(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Read mode: legacy individual rule → settings synced.
     */
    public function testReadModeLegacyIndividual(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyIndividual(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Read mode: legacy rule with full URL → settings synced.
     */
    public function testReadModeLegacyFullUrl(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyFullUrl(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Read mode: legacy grouped rule with full URL → settings synced.
     */
    public function testReadModeLegacyGroupedFullUrl(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyGroupedFullUrl(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Read mode: mixed commented/active rules → only active detected.
     */
    public function testReadModeMixedCommentedActive(): void
    {
        $this->writeFixtureAndManage($this->fixtureMixedCommentedActive(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        // Only `original` is on an active (non-commented) line.
        $this->assertSame(['original'], $types);
    }

    /**
     * Read mode: both Access and Analytics markers → Access types detected.
     */
    public function testReadModeBothMarkers(): void
    {
        $this->writeFixtureAndManage($this->fixtureBothMarkers(), null);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        // Only Access marker types should be read.
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Read mode: Analytics marker only → no Access types detected.
     */
    public function testReadModeAnalyticsOnly(): void
    {
        $this->writeFixtureAndManage($this->fixtureAnalyticsOnly(), null);

        $settings = $this->getSettings();
        $this->assertSame([], $settings->get('access_htaccess_types'));
    }

    // ==================================================================
    // Write mode tests
    // ==================================================================

    /**
     * Write mode: insert new rule into a clean .htaccess.
     */
    public function testWriteModeInsertNewRule(): void
    {
        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original', 'large']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('# Module Access: protect files.', $htaccess);
        $this->assertStringContainsString('# This rule is automatically managed by the module.', $htaccess);
        $this->assertStringContainsString('RewriteRule "^files/(original|large)/(.*)$" "access/files/$1/$2" [NC,L]', $htaccess);

        $settings = $this->getSettings();
        $types = $settings->get('access_htaccess_types');
        sort($types);
        $this->assertSame(['large', 'original'], $types);
    }

    /**
     * Write mode: the rule uses relative path (no leading /).
     */
    public function testWriteModeRelativePath(): void
    {
        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original']);

        $htaccess = $this->readHtaccess();
        // The destination must NOT start with "/".
        $this->assertStringContainsString('"access/files/$1/$2"', $htaccess);
        $this->assertStringNotContainsString('"/access/files/$1/$2"', $htaccess);
    }

    /**
     * Write mode: rule is inserted after "RewriteEngine On".
     */
    public function testWriteModeInsertedAfterRewriteEngineOn(): void
    {
        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original']);

        $htaccess = $this->readHtaccess();
        $posEngine = strpos($htaccess, 'RewriteEngine On');
        $posMarker = strpos($htaccess, '# Module Access: protect files.');
        $this->assertNotFalse($posEngine);
        $this->assertNotFalse($posMarker);
        $this->assertGreaterThan($posEngine, $posMarker);
    }

    /**
     * Write mode: update existing managed rule (add types).
     */
    public function testWriteModeUpdateAddTypes(): void
    {
        // Start with original only.
        $this->writeFixtureAndManage($this->fixtureManagedOriginalOnly(), ['original', 'large', 'medium']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('(original|large|medium)', $htaccess);
        // Only one marker should exist.
        $this->assertSame(1, substr_count($htaccess, '# Module Access: protect files.'));
    }

    /**
     * Write mode: update existing managed rule (remove types).
     */
    public function testWriteModeUpdateRemoveTypes(): void
    {
        // Start with original|large.
        $this->writeFixtureAndManage($this->fixtureManagedOriginalLarge(), ['original']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('(original)', $htaccess);
        $this->assertStringNotContainsString('large', $htaccess);
    }

    /**
     * Write mode: remove all types → rule removed.
     */
    public function testWriteModeRemoveRule(): void
    {
        $this->writeFixtureAndManage($this->fixtureManagedOriginalLarge(), []);

        $htaccess = $this->readHtaccess();
        $this->assertStringNotContainsString('# Module Access: protect files.', $htaccess);
        $this->assertStringNotContainsString('access/files/', $htaccess);
    }

    /**
     * Write mode: convert legacy grouped rule to managed format.
     */
    public function testWriteModeLegacyGroupedConversion(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyGrouped(), ['original', 'large']);

        $htaccess = $this->readHtaccess();
        // Marker should now be present.
        $this->assertStringContainsString('# Module Access: protect files.', $htaccess);
        // Legacy rule (with leading /) should be removed.
        $this->assertStringNotContainsString('"/access/files/', $htaccess);
        // Only the managed rule should remain.
        $this->assertSame(1, substr_count($htaccess, 'access/files/'));
    }

    /**
     * Write mode: convert legacy individual rules to managed format.
     */
    public function testWriteModeLegacyIndividualConversion(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyIndividual(), ['original', 'large']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('# Module Access: protect files.', $htaccess);
        // Individual legacy rules should be removed.
        $this->assertStringNotContainsString('/access/files/original/', $htaccess);
        $this->assertStringNotContainsString('/access/files/large/', $htaccess);
        // Only the managed grouped rule should remain.
        $this->assertStringContainsString('(original|large)', $htaccess);
    }

    /**
     * Write mode: convert legacy full URL rules to managed format.
     */
    public function testWriteModeLegacyFullUrlConversion(): void
    {
        $this->writeFixtureAndManage($this->fixtureLegacyFullUrl(), ['original', 'large']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('# Module Access: protect files.', $htaccess);
        // Legacy full URL rules should be removed.
        $this->assertStringNotContainsString('localdev', $htaccess);
        $this->assertStringNotContainsString('HTTP_HOST', $htaccess);
    }

    /**
     * Write mode: custom types merged from setting.
     */
    public function testWriteModeCustomTypes(): void
    {
        $settings = $this->getSettings();
        $settings->set('access_htaccess_custom_types', 'mp3 mp4 webm');

        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original', 'large']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('mp3', $htaccess);
        $this->assertStringContainsString('mp4', $htaccess);
        $this->assertStringContainsString('webm', $htaccess);
        $this->assertStringContainsString('(original|large|mp3|mp4|webm)', $htaccess);
    }

    /**
     * Write mode: invalid custom types are sanitized (rejected).
     */
    public function testWriteModeCustomTypesSanitized(): void
    {
        $settings = $this->getSettings();
        $settings->set('access_htaccess_custom_types', 'mp3 ../hack bad@type ok-type');

        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original']);

        $htaccess = $this->readHtaccess();
        $this->assertStringContainsString('mp3', $htaccess);
        $this->assertStringContainsString('ok-type', $htaccess);
        $this->assertStringNotContainsString('hack', $htaccess);
        $this->assertStringNotContainsString('bad@type', $htaccess);
    }

    /**
     * Write mode: idempotent — same types with managed format → no change.
     */
    public function testWriteModeIdempotent(): void
    {
        $fixture = $this->fixtureManagedOriginalLarge();
        file_put_contents($this->htaccessPath, $fixture);

        // First, read mode to sync settings.
        $this->manageHtaccessMethod->invoke($this->accessModuleInstance, null);
        // Then write mode with same types.
        $this->manageHtaccessMethod->invoke($this->accessModuleInstance, ['original', 'large']);

        $htaccess = $this->readHtaccess();
        // The file should be unchanged from the fixture.
        $this->assertSame($fixture, $htaccess);
    }

    /**
     * Write mode: Analytics marker is preserved when updating Access rule.
     */
    public function testWriteModePreservesAnalyticsMarker(): void
    {
        $this->writeFixtureAndManage($this->fixtureBothMarkers(), ['original', 'large', 'medium']);

        $htaccess = $this->readHtaccess();
        // Access marker should be updated.
        $this->assertStringContainsString('(original|large|medium)', $htaccess);
        // Analytics marker and rule should be preserved.
        $this->assertStringContainsString('# Module Analytics: count downloads.', $htaccess);
        $this->assertStringContainsString('download/files/$1/$2', $htaccess);
    }

    /**
     * Write mode: only one marker block after multiple writes.
     */
    public function testWriteModeNoDoubleMarker(): void
    {
        // Write once.
        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original']);
        // Write again with different types.
        $this->manageHtaccessMethod->invoke($this->accessModuleInstance, ['original', 'large']);

        $htaccess = $this->readHtaccess();
        $this->assertSame(1, substr_count($htaccess, '# Module Access: protect files.'));
    }

    /**
     * Write mode: the block ends with a trailing blank line.
     */
    public function testWriteModeTrailingBlankLine(): void
    {
        $this->writeFixtureAndManage($this->fixtureNoRule(), ['original']);

        $htaccess = $this->readHtaccess();
        // After the RewriteRule line, there should be blank lines before the
        // next section (the RewriteCond block).
        $this->assertMatchesRegularExpression('/\[NC,L\]\s*\n\n/', $htaccess);
    }

    // ==================================================================
    // preUninstall tests
    // ==================================================================

    /**
     * preUninstall: managed Access rule is removed.
     */
    public function testPreUninstallRemovesRule(): void
    {
        file_put_contents($this->htaccessPath, $this->fixtureManagedOriginalLarge());

        $preUninstall = new ReflectionMethod($this->accessModuleInstance, 'preUninstall');
        $preUninstall->setAccessible(true);
        $preUninstall->invoke($this->accessModuleInstance);

        $htaccess = $this->readHtaccess();
        $this->assertStringNotContainsString('# Module Access: protect files.', $htaccess);
        $this->assertStringNotContainsString('access/files/', $htaccess);
    }

    /**
     * preUninstall: no rule → no error (idempotent).
     */
    public function testPreUninstallNoRuleNoError(): void
    {
        file_put_contents($this->htaccessPath, $this->fixtureNoRule());

        $preUninstall = new ReflectionMethod($this->accessModuleInstance, 'preUninstall');
        $preUninstall->setAccessible(true);
        // Should not throw any exception.
        $preUninstall->invoke($this->accessModuleInstance);

        $htaccess = $this->readHtaccess();
        // File should be unchanged.
        $this->assertSame($this->fixtureNoRule(), $htaccess);
    }

    /**
     * preUninstall: when Analytics module is active and has no rule,
     * the Access rule is converted to an Analytics download rule.
     *
     * Note: this test only runs when the Analytics module is installed
     * and active. Otherwise it is skipped.
     */
    public function testPreUninstallConvertsToAnalyticsRule(): void
    {
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        $analyticsModule = $moduleManager->getModule('Analytics');
        if (!$analyticsModule || $analyticsModule->getState() !== \Omeka\Module\Manager::STATE_ACTIVE) {
            $this->markTestSkipped('Requires Analytics module to be active.');
        }

        file_put_contents($this->htaccessPath, $this->fixtureManagedOriginalLarge());

        $preUninstall = new ReflectionMethod($this->accessModuleInstance, 'preUninstall');
        $preUninstall->setAccessible(true);
        $preUninstall->invoke($this->accessModuleInstance);

        $htaccess = $this->readHtaccess();
        // Access marker should be gone.
        $this->assertStringNotContainsString('# Module Access: protect files.', $htaccess);
        // Analytics marker and download rule should be present.
        $this->assertStringContainsString('# Module Analytics: count downloads.', $htaccess);
        $this->assertStringContainsString('download/files/$1/$2', $htaccess);
        $this->assertStringContainsString('(original|large)', $htaccess);

        // Analytics settings should be updated.
        $settings = $this->getSettings();
        $analyticsTypes = $settings->get('analytics_htaccess_types');
        sort($analyticsTypes);
        $this->assertSame(['large', 'original'], $analyticsTypes);
    }

    /**
     * preUninstall: when both markers are present, only Access is removed
     * and the existing Analytics rule is preserved (no duplicate).
     */
    public function testPreUninstallPreservesExistingAnalyticsRule(): void
    {
        file_put_contents($this->htaccessPath, $this->fixtureBothMarkers());

        $preUninstall = new ReflectionMethod($this->accessModuleInstance, 'preUninstall');
        $preUninstall->setAccessible(true);
        $preUninstall->invoke($this->accessModuleInstance);

        $htaccess = $this->readHtaccess();
        // Access marker should be gone.
        $this->assertStringNotContainsString('# Module Access: protect files.', $htaccess);
        // Analytics marker and rule should still be there (exactly once).
        $this->assertSame(1, substr_count($htaccess, '# Module Analytics: count downloads.'));
        $this->assertStringContainsString('download/files/', $htaccess);
    }
}
