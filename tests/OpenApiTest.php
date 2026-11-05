<?php

declare(strict_types=1);

namespace App\Tests;

use App\Controller\BuildController;
use App\Tests\Controller\BuildControllerTest;
use Doctrine\ORM\EntityManagerInterface;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Checks openapi.yaml against the app: every answer the app gives must match the spec,
 * and the spec must reject the payloads the app rejects.
 */
final class OpenApiTest extends WebTestCase
{
    private const SPEC = __DIR__ . '/../openapi.yaml';

    /** Matches PUBLISH_API_TOKEN in .env.test. */
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer test-api-token-0123456789'];

    private const PAYLOAD = [
        'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'slug' => 'some_collective',
        'title' => 'Some Collective',
        'content_download_url' => 'https://cloud.example.org/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'callback_status_url' => 'https://cloud.example.org/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
    ];

    /**
     * Returns the validators for openapi.yaml.
     *
     * @return ValidatorBuilder the builder, with the spec loaded
     */
    private static function validators(): ValidatorBuilder
    {
        return (new ValidatorBuilder())->fromYamlFile(self::SPEC);
    }

    /**
     * Fails unless $response is an answer the spec allows for $method $path.
     *
     * @param Response $response The app's answer.
     * @param string $method The request method, lower case.
     * @param string $path The path as the spec writes it.
     * @return void
     */
    private function assertAnswerMatchesSpec(Response $response, string $method, string $path): void
    {
        $factory = new Psr17Factory();
        $psrResponse = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createResponse($response);

        try {
            self::validators()->getResponseValidator()->validate(new OperationAddress($path, $method), $psrResponse);
        } catch (ValidationFailed $e) {
            self::fail(sprintf('%d answer to %s %s does not match openapi.yaml: %s', $response->getStatusCode(), $method, $path, self::reason($e)));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Returns whether the spec accepts $payload as the body of POST /build.
     *
     * @param array<string, mixed> $payload The request body.
     * @return bool true when the spec accepts it
     */
    private static function specAccepts(array $payload): bool
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'https://publish.example.org/build')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', self::AUTH['HTTP_AUTHORIZATION'])
            ->withBody($factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        try {
            self::validators()->getRequestValidator()->validate($request);
        } catch (ValidationFailed) {
            return false;
        }

        return true;
    }

    /**
     * Returns the message of $e and of every exception it wraps, so a failure names the field.
     *
     * @param \Throwable $e The validation failure.
     * @return string the messages, outermost first
     */
    private static function reason(\Throwable $e): string
    {
        $messages = [];
        for (; $e !== null; $e = $e->getPrevious()) {
            $messages[] = $e->getMessage();
        }

        return implode(' <- ', $messages);
    }

    /**
     * Sends $body to POST /build as JSON and returns the answer.
     *
     * @param KernelBrowser $client The test client.
     * @param string $body The raw request body.
     * @param array<string, string> $server The server parameters, such as the Authorization header.
     * @return Response the app's answer
     */
    private static function postBuild(KernelBrowser $client, string $body, array $server): Response
    {
        $client->request('POST', '/build', server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body);

        return $client->getResponse();
    }

    public function testTheSpecIsValid(): void
    {
        $spec = self::validators()->getRequestValidator()->getSchema();

        self::assertTrue($spec->validate(), implode("\n", $spec->getErrors()));
    }

    public function testTheHealthAnswerMatchesTheSpec(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $this->assertAnswerMatchesSpec($client->getResponse(), 'get', '/health');
    }

    public function testTheSpecAcceptsAValidBuildRequestAndTheAppItsAnswer(): void
    {
        self::assertTrue(self::specAccepts(self::PAYLOAD));

        $client = static::createClient();
        $response = self::postBuild($client, json_encode(self::PAYLOAD, JSON_THROW_ON_ERROR), self::AUTH);

        self::assertSame(202, $response->getStatusCode());
        $this->assertAnswerMatchesSpec($response, 'post', '/build');
    }

    /**
     * Provides requests the app rejects, with the status it answers.
     *
     * @return array<string, array{string, array<string, string>, int}> body, server parameters and status, keyed by case name
     */
    public static function provideRejectedRequests(): array
    {
        $json = ['HTTP_ACCEPT' => 'application/json'];

        return [
            'invalid field' => [json_encode([...self::PAYLOAD, 'slug' => '../escape'], JSON_THROW_ON_ERROR), self::AUTH, 400],
            'body not JSON' => ['{not json', self::AUTH + $json, 400],
            'no token' => [json_encode(self::PAYLOAD, JSON_THROW_ON_ERROR), $json, 401],
            'wrong token' => [json_encode(self::PAYLOAD, JSON_THROW_ON_ERROR), ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'], 401],
        ];
    }

    /**
     * @param string $body The request body.
     * @param array<string, string> $server The server parameters.
     * @param int $status The status the app answers.
     */
    #[DataProvider('provideRejectedRequests')]
    public function testARejectionMatchesTheSpec(string $body, array $server, int $status): void
    {
        $client = static::createClient();
        $response = self::postBuild($client, $body, $server);

        self::assertSame($status, $response->getStatusCode());
        $this->assertAnswerMatchesSpec($response, 'post', '/build');
    }

    /** The broker cannot fail in the test kernel, so the controller gets a bus that does. */
    public function testTheBrokerFailureMatchesTheSpec(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('connection refused'));

        $request = Request::create('/build', 'POST', content: json_encode(self::PAYLOAD, JSON_THROW_ON_ERROR));
        $response = (new BuildController($bus, new NullLogger(), $this->createStub(EntityManagerInterface::class)))($request);

        self::assertSame(503, $response->getStatusCode());
        $this->assertAnswerMatchesSpec($response, 'post', '/build');
    }

    /**
     * Provides every payload BuildControllerTest shows the app rejecting with a 400.
     *
     * @return array<string, array{string, mixed}> field name and invalid value, keyed by case name
     */
    public static function provideInvalidFields(): array
    {
        $titles = [];
        foreach (BuildControllerTest::provideInvalidTitles() as $name => [$title]) {
            $titles['title: ' . $name] = ['title', $title];
        }

        return BuildControllerTest::provideUnsafeDirectoryNames() + BuildControllerTest::provideInvalidUrls() + $titles;
    }

    #[DataProvider('provideInvalidFields')]
    public function testTheSpecRejectsWhatTheAppRejects(string $field, mixed $value): void
    {
        self::assertFalse(self::specAccepts([...self::PAYLOAD, $field => $value]));
    }
}
