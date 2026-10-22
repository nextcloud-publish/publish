<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Covers App\Controller\PublishedSiteController through the kernel,
 * including that the ^/publish/ firewall genuinely has no auth (unlike every
 * other path -- see config/packages/security.yaml).
 */
final class PublishedSiteControllerTest extends WebTestCase
{
    private const SITE_ID = 'integration-test-site';

    private string $jobStorageDir;
    private string|false $previousJobStorageDir;

    protected function setUp(): void
    {
        $this->jobStorageDir = sys_get_temp_dir() . '/publish-job-storage-test-' . bin2hex(random_bytes(6));
        $outputDir = $this->jobStorageDir . '/' . self::SITE_ID . '/output';
        mkdir($outputDir . '/Cats', 0o755, true);

        file_put_contents($outputDir . '/index.html', '<html>home</html>');
        file_put_contents($outputDir . '/Cats/index.html', '<html>cats</html>');
        file_put_contents($outputDir . '/style.css', 'body { color: red; }');

        // JobWorkspace reads this at request time (a plain %env()%, not a
        // cast one), so overriding it before the kernel boots is enough --
        // there is no compiled-container caching of the value to fight.
        $this->previousJobStorageDir = getenv('JOB_STORAGE_DIR');
        putenv('JOB_STORAGE_DIR=' . $this->jobStorageDir);
        $_ENV['JOB_STORAGE_DIR'] = $this->jobStorageDir;
        $_SERVER['JOB_STORAGE_DIR'] = $this->jobStorageDir;
    }

    protected function tearDown(): void
    {
        if ($this->previousJobStorageDir === false) {
            putenv('JOB_STORAGE_DIR');
            unset($_ENV['JOB_STORAGE_DIR'], $_SERVER['JOB_STORAGE_DIR']);
        } else {
            putenv('JOB_STORAGE_DIR=' . $this->previousJobStorageDir);
            $_ENV['JOB_STORAGE_DIR'] = $this->previousJobStorageDir;
            $_SERVER['JOB_STORAGE_DIR'] = $this->previousJobStorageDir;
        }

        exec('rm -rf ' . escapeshellarg($this->jobStorageDir));

        parent::tearDown();
    }

    /**
     * BinaryFileResponse streams via sendContent() and leaves getContent()
     * at its unset default (false), so the served bytes have to be read back
     * from the file it points at instead of the response body.
     */
    private function servedFileContents(BinaryFileResponse $response): string
    {
        return (string) file_get_contents($response->getFile()->getPathname());
    }

    public function testRedirectsTheBareSiteUrlToATrailingSlash(): void
    {
        // ssg-worker's relative asset/back/sub-page links only resolve
        // correctly once the page's own URL ends in "/" -- see the class
        // docblock. Nothing (not even the index page) is served directly
        // from the slash-less URL.
        $client = static::createClient();
        $client->request('GET', '/publish/' . self::SITE_ID);

        self::assertResponseRedirects('/publish/' . self::SITE_ID . '/');
    }

    public function testRedirectsADirectoryShapedPageToATrailingSlash(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publish/' . self::SITE_ID . '/Cats');

        self::assertResponseRedirects('/publish/' . self::SITE_ID . '/Cats/');
    }

    public function testServesTheSiteRootWithoutAnyToken(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->request('GET', '/publish/' . self::SITE_ID);

        // No Authorization header at all: proves the /publish/ firewall
        // really is security: false, not just a lenient access_control rule.
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'text/html; charset=utf-8');

        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringContainsString('home', $this->servedFileContents($response));
    }

    public function testServesANestedPageByItsDirectory(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->request('GET', '/publish/' . self::SITE_ID . '/Cats');

        self::assertResponseStatusCodeSame(200);

        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringContainsString('cats', $this->servedFileContents($response));
    }

    public function testServesAStaticAssetWithItsOwnContentType(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publish/' . self::SITE_ID . '/style.css');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'text/css; charset=utf-8');
    }

    public function test404ForAnUnknownSite(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publish/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function test404ForAnUnsafeStaticSiteId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publish/' . rawurlencode('../etc'));

        self::assertResponseStatusCodeSame(404);
    }

    public function test404ForAPathThatEscapesTheOutputFolder(): void
    {
        $client = static::createClient();
        $client->request('GET', '/publish/' . self::SITE_ID . '/../../input/content.tar.gz');

        self::assertResponseStatusCodeSame(404);
    }
}
