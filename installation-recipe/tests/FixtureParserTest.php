<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Tests;

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FixtureParserTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fixture-parser-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testSharesParsedContentWithoutReusingTheSourceFilename(): void
    {
        $first = $this->fixture("example:\n  row:\n    options: !json {enabled: true}\n");
        $second = $this->fixture(file_get_contents($first));
        $cache = new InMemoryCache();
        $firstDefinitions = (new FixtureParser($cache))->parse(new FixtureSet([$first]));
        $secondDefinitions = (new FixtureParser($cache))->parse(new FixtureSet([$second]));

        $this->assertSame($firstDefinitions[0]->data['options'], $secondDefinitions[0]->data['options']);
        $this->assertSame($first, $firstDefinitions[0]->source->file);
        $this->assertSame($second, $secondDefinitions[0]->source->file);
    }

    public function testIndependentParsersDoNotShareCachedDefinitions(): void
    {
        $file = $this->fixture("example:\n  row:\n    options: !json {enabled: true}\n");
        $fixtures = new FixtureSet([$file]);
        $first = (new FixtureParser(new InMemoryCache()))->parse($fixtures);
        $second = (new FixtureParser(new InMemoryCache()))->parse($fixtures);

        $this->assertNotSame($first[0]->data['options'], $second[0]->data['options']);
    }

    public function testClearingSharedCacheReparsesUnchangedContent(): void
    {
        $file = $this->fixture("example:\n  row:\n    options: !json {enabled: true}\n");
        $cache = new InMemoryCache();
        $parser = new FixtureParser($cache);
        $fixtures = new FixtureSet([$file]);
        $first = $parser->parse($fixtures);
        $this->assertSame($first[0]->data['options'], $parser->parse($fixtures)[0]->data['options']);
        $cache->clear();

        $this->assertNotSame($first[0]->data['options'], $parser->parse($fixtures)[0]->data['options']);
    }

    public function testChangedContentInvalidatesEvenWhenSizeAndTimestampAreUnchanged(): void
    {
        $file = $this->fixture("example:\n  row: {title: First}\n");
        $fixtures = new FixtureSet([$file]);
        $parser = new FixtureParser(new InMemoryCache());
        $this->assertSame('First', $parser->parse($fixtures)[0]->data['title']);
        $mtime = filemtime($file);
        file_put_contents($file, "example:\n  row: {title: Other}\n");
        touch($file, $mtime);

        $this->assertSame('Other', $parser->parse($fixtures)[0]->data['title']);
    }

    public function testValidatesDuplicateNamesAcrossPreviouslyCachedFiles(): void
    {
        $first = $this->fixture("example:\n  duplicate: {title: First}\n");
        $second = $this->fixture("example:\n  duplicate: {title: Second}\n");
        $parser = new FixtureParser(new InMemoryCache());
        $parser->parse(new FixtureSet([$first]));
        $parser->parse(new FixtureSet([$second]));

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture name "duplicate" is defined more than once.');
        $parser->parse(new FixtureSet([$first, $second]));
    }

    public function testKeepsDuplicateValidationAheadOfRowValidation(): void
    {
        $first = $this->fixture("example:\n  duplicate: {title: First}\n");
        $invalid = $this->fixture("example:\n  duplicate: invalid row\n");
        $parser = new FixtureParser(new InMemoryCache());
        $parser->parse(new FixtureSet([$first]));

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture name "duplicate" is defined more than once.');
        $parser->parse(new FixtureSet([$first, $invalid]));
    }

    public function testPreservesFileAndRowOrderingWhenAllContentIsCached(): void
    {
        $first = $this->fixture("example:\n  first: {title: First}\n  second: {title: Second}\n");
        $second = $this->fixture("example:\n  third: {title: Third}\n");
        $parser = new FixtureParser(new InMemoryCache());
        $parser->parse(new FixtureSet([$first, $second]));

        $definitions = $parser->parse(new FixtureSet([$second, $first]));

        $this->assertSame(['third', 'first', 'second'], array_column($definitions, 'name'));
    }

    public function testCachedInvalidTagsReportTheCurrentFile(): void
    {
        $contents = "example:\n  row: {value: !xml invalid}\n";

        $parser = new FixtureParser(new InMemoryCache());

        foreach ([$this->fixture($contents), $this->fixture($contents)] as $file) {
            try {
                $parser->parse(new FixtureSet([$file]));
                $this->fail('Unknown tags must be rejected.');
            } catch (InvalidRecipeException $exception) {
                $this->assertSame('The fixture value tag "!xml" in "'.$file.'" is invalid.', $exception->getMessage());
            }
        }
    }

    public function testYamlErrorsKeepTheirFileLineAndSnippet(): void
    {
        $file = $this->fixture("example:\n  row: [unterminated\n");

        try {
            Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
            $this->fail('Malformed YAML must fail.');
        } catch (ParseException $expected) {
            try {
                (new FixtureParser(new InMemoryCache()))->parse(new FixtureSet([$file]));
                $this->fail('Malformed fixture YAML must fail.');
            } catch (ParseException $actual) {
                $this->assertSame($expected->getMessage(), $actual->getMessage());
                $this->assertSame($expected->getParsedLine(), $actual->getParsedLine());
            }
        }
    }

    public function testEncodingErrorsPreserveTheirSourceFile(): void
    {
        $file = $this->fixture("example: \xFF");

        try {
            (new FixtureParser(new InMemoryCache()))->parse(new FixtureSet([$file]));
            $this->fail('Invalid YAML encoding must fail.');
        } catch (ParseException $exception) {
            $this->assertSame($file, $exception->getParsedFile());
        }
    }

    public function testRemovedCachedFileStillReportsAnError(): void
    {
        $file = $this->fixture("example:\n  row: {title: First}\n");
        $fixtures = new FixtureSet([$file]);
        $parser = new FixtureParser(new InMemoryCache());
        $parser->parse($fixtures);
        unlink($file);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('File "'.$file.'" does not exist.');
        $parser->parse($fixtures);
    }

    private function fixture(string $contents): string
    {
        $path = $this->directory.'/'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($path, $contents);

        return $path;
    }
}
