<?php

use App\Domain\Parsing\ConfigParseException;
use App\Domain\Parsing\EnvParser;
use App\Domain\Parsing\JsonParser;
use App\Domain\Parsing\ParserRegistry;
use App\Domain\Parsing\PropertiesParser;
use App\Domain\Parsing\YamlParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ParserTest extends CIUnitTestCase
{
    public function testEnvParserHandlesCommentsQuotesAndExports(): void
    {
        $contents = <<<'ENV'
# top comment
APP_NAME="Config Monitor"
export APP_ENV=production
APP_DEBUG = false
APP_URL=http://localhost:8080   # inline comment
MULTILINE="line one
line two"
ESCAPED="tab\tnewline\n"
EMPTY=
SINGLE='value # not a comment'
ENV;

        $parsed = (new EnvParser())->parse($contents, '.env');

        $this->assertSame('Config Monitor', $parsed['APP_NAME']);
        $this->assertSame('production', $parsed['APP_ENV']);
        $this->assertSame('false', $parsed['APP_DEBUG']);
        $this->assertSame('http://localhost:8080', $parsed['APP_URL']);
        $this->assertSame("line one\nline two", $parsed['MULTILINE']);
        $this->assertSame("tab\tnewline\n", $parsed['ESCAPED']);
        $this->assertSame('', $parsed['EMPTY']);
        $this->assertSame('value # not a comment', $parsed['SINGLE']);
    }

    public function testEnvParserSupportsDotEnvVariants(): void
    {
        $parser = new EnvParser();

        $this->assertTrue($parser->supports('.env'));
        $this->assertTrue($parser->supports('/srv/app/.env.production'));
        $this->assertFalse($parser->supports('environment.yaml'));
    }

    public function testEnvParserRejectsMalformedLines(): void
    {
        $this->expectException(ConfigParseException::class);
        (new EnvParser())->parse("VALID=1\nTHIS_IS_NOT_VALID\n", '.env');
    }

    public function testEnvParserRejectsUnterminatedQuotes(): void
    {
        $this->expectException(ConfigParseException::class);
        (new EnvParser())->parse('KEY="never closed', '.env');
    }

    public function testJsonParserParsesNestedDocuments(): void
    {
        $parsed = (new JsonParser())->parse(
            '{"server":{"port":8080,"hosts":["a","b"]},"enabled":true,"ratio":0.5,"empty":null}',
            'app.json',
        );

        $this->assertSame(8080, $parsed['server']['port']);
        $this->assertSame(['a', 'b'], $parsed['server']['hosts']);
        $this->assertTrue($parsed['enabled']);
        $this->assertSame(0.5, $parsed['ratio']);
        $this->assertNull($parsed['empty']);
    }

    public function testJsonParserRejectsInvalidJson(): void
    {
        $this->expectException(ConfigParseException::class);
        $this->expectExceptionMessage('Invalid JSON');
        (new JsonParser())->parse('{"broken": ', 'app.json');
    }

    public function testJsonParserRejectsScalarRoot(): void
    {
        $this->expectException(ConfigParseException::class);
        (new JsonParser())->parse('"just a string"', 'app.json');
    }

    public function testYamlParserParsesNestedDocuments(): void
    {
        $yaml = <<<'YAML'
app:
  name: monitor
  replicas: 3
  features:
    - diff
    - notify
database:
  host: db.local
  pool:
    max: 20
YAML;

        $parser = new YamlParser();
        $parsed = $parser->parse($yaml, 'services.yaml');

        $this->assertSame('monitor', $parsed['app']['name']);
        $this->assertSame(3, $parsed['app']['replicas']);
        $this->assertSame(['diff', 'notify'], $parsed['app']['features']);
        $this->assertSame(20, $parsed['database']['pool']['max']);
        $this->assertTrue($parser->supports('config.yml'));
    }

    public function testYamlParserRejectsInvalidYaml(): void
    {
        $this->expectException(ConfigParseException::class);
        $this->expectExceptionMessage('Invalid YAML');
        (new YamlParser())->parse("app:\n  - one\n - two", 'broken.yaml');
    }

    public function testPropertiesParserHandlesSeparatorsAndContinuations(): void
    {
        $contents = <<<'PROPS'
# comment
! also a comment
server.port=8080
server.host: localhost
logging.level DEBUG
long.value = first \
    second
escaped\:key=value\=with\=equals
unicode=\u00e9
PROPS;

        $parsed = (new PropertiesParser())->parse($contents, 'application.properties');

        $this->assertSame('8080', $parsed['server.port']);
        $this->assertSame('localhost', $parsed['server.host']);
        $this->assertSame('DEBUG', $parsed['logging.level']);
        $this->assertSame('first second', $parsed['long.value']);
        $this->assertSame('value=with=equals', $parsed['escaped:key']);
        $this->assertSame('é', $parsed['unicode']);
    }

    public function testPropertiesParserRejectsEmptyKeys(): void
    {
        $this->expectException(ConfigParseException::class);
        (new PropertiesParser())->parse("=value\n", 'application.properties');
    }

    public function testRegistrySelectsParserFromPath(): void
    {
        $registry = new ParserRegistry();

        $this->assertInstanceOf(EnvParser::class, $registry->forPath('.env'));
        $this->assertInstanceOf(EnvParser::class, $registry->forPath('.env.local'));
        $this->assertInstanceOf(JsonParser::class, $registry->forPath('/tmp/app.json'));
        $this->assertInstanceOf(YamlParser::class, $registry->forPath('services.yml'));
        $this->assertInstanceOf(PropertiesParser::class, $registry->forPath('app.properties'));
        $this->assertTrue($registry->supports('UPPER.JSON'));
    }

    public function testRegistryRejectsUnsupportedExtension(): void
    {
        $this->expectException(ConfigParseException::class);
        (new ParserRegistry())->forPath('notes.txt');
    }

    public function testRegistryParsesViaDispatchedParser(): void
    {
        $parsed = (new ParserRegistry())->parse('app.json', '{"nested":{"key":"value"}}');

        $this->assertSame('value', $parsed['nested']['key']);
    }

    public function testRegistryDerivesExtensionsFromParsers(): void
    {
        $registry = new ParserRegistry();

        $this->assertSame(
            ['env', 'json', 'yaml', 'yml', 'properties'],
            $registry->extensions(),
        );
        $this->assertSame(['env', 'json', 'yaml', 'properties'], $registry->names());
    }

    /**
     * @dataProvider emptyContentProvider
     */
    public function testAllParsersRejectEmptyContent(string $parserClass, string $path): void
    {
        $this->expectException(ConfigParseException::class);
        (new $parserClass())->parse("  \n\t", $path);
    }

    public static function emptyContentProvider(): array
    {
        return [
            'env'        => [EnvParser::class, '.env'],
            'json'       => [JsonParser::class, 'app.json'],
            'yaml'       => [YamlParser::class, 'app.yaml'],
            'yml'        => [YamlParser::class, 'app.yml'],
            'properties' => [PropertiesParser::class, 'app.properties'],
        ];
    }

    public function testJsonAndYamlRejectNullRoots(): void
    {
        foreach ([[JsonParser::class, 'app.json'], [YamlParser::class, 'app.yaml']] as [$class, $path]) {
            try {
                (new $class())->parse('null', $path);
                $this->fail('Expected a null root to be rejected for ' . $path);
            } catch (ConfigParseException $e) {
                $this->assertStringContainsString('object/map', $e->getMessage());
            }
        }
    }

    public function testParseExceptionKeepsLineAndSnippetContext(): void
    {
        try {
            (new EnvParser())->parse("GOOD=1\nBROKEN LINE\n", '.env');
            $this->fail('Expected malformed env content to throw.');
        } catch (ConfigParseException $e) {
            $this->assertSame(2, $e->sourceLine());
            $this->assertSame('BROKEN LINE', $e->snippet());
            $this->assertSame(['line' => 2, 'snippet' => 'BROKEN LINE'], $e->context());
        }
    }

    public function testTruncatedJsonMessageMentionsPartialWrite(): void
    {
        try {
            (new JsonParser())->parse('{"a": 1', 'app.json');
            $this->fail('Expected truncated JSON to throw.');
        } catch (ConfigParseException $e) {
            $this->assertStringContainsString('partially written', $e->getMessage());
        }
    }
}
