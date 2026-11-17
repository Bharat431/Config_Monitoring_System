<?php

use App\Domain\Diff\ChangeSet;
use App\Domain\Diff\DiffEngine;
use App\Domain\Diff\Flattener;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DiffEngineTest extends CIUnitTestCase
{
    private DiffEngine $diff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new DiffEngine(new Flattener());
    }

    public function testIdenticalDocumentsProduceNoChanges(): void
    {
        $changeSet = $this->diff->diff(
            ['app' => ['name' => 'monitor', 'replicas' => 2]],
            ['app' => ['name' => 'monitor', 'replicas' => 2]],
        );

        $this->assertInstanceOf(ChangeSet::class, $changeSet);
        $this->assertTrue($changeSet->isEmpty());
        $this->assertSame(0, $changeSet->count());
        $this->assertSame(
            ['added' => 0, 'updated' => 0, 'removed' => 0, 'type_changes' => 0],
            $changeSet->summary(),
        );
    }

    public function testDetectsAddedAndRemovedNestedKeys(): void
    {
        $changeSet = $this->diff->diff(
            ['server' => ['port' => 8080, 'timeout' => 30]],
            ['server' => ['port' => 8080], 'features' => ['metrics' => true]],
        );

        $this->assertSame(['server.timeout' => 30], $changeSet->removed());
        $this->assertSame(['features.metrics' => true], $changeSet->added());
        $this->assertSame([], $changeSet->updated());
        $this->assertSame(2, $changeSet->count());
    }

    public function testDetectsUpdatedScalarsWithOldAndNewValues(): void
    {
        $changeSet = $this->diff->diff(
            ['database' => ['host' => 'db.local', 'pool' => ['max' => 20]]],
            ['database' => ['host' => 'db.prod', 'pool' => ['max' => 50]]],
        );

        $this->assertSame(
            [
                'database.host'    => ['old' => 'db.local', 'new' => 'db.prod'],
                'database.pool.max' => ['old' => 20, 'new' => 50],
            ],
            $changeSet->updated(),
        );
        $this->assertSame(2, $changeSet->summary()['updated']);
    }

    public function testDetectsTypeChangesSeparatelyFromUpdates(): void
    {
        $changeSet = $this->diff->diff(
            ['server' => ['port' => 8080], 'flag' => true],
            ['server' => ['port' => '8080'], 'flag' => 'true'],
        );

        $typeChanges = $changeSet->typeChanges();

        $this->assertArrayHasKey('server.port', $typeChanges);
        $this->assertSame('integer', $typeChanges['server.port']['oldType']);
        $this->assertSame('string', $typeChanges['server.port']['newType']);
        $this->assertSame(8080, $typeChanges['server.port']['old']);
        $this->assertSame('8080', $typeChanges['server.port']['new']);

        $this->assertArrayHasKey('flag', $typeChanges);
        $this->assertSame('boolean', $typeChanges['flag']['oldType']);
        $this->assertSame('string', $typeChanges['flag']['newType']);

        $this->assertSame([], $changeSet->updated());
        $this->assertSame(2, $changeSet->summary()['type_changes']);
    }

    public function testScalarToObjectIsATypeChange(): void
    {
        $changeSet = $this->diff->diff(
            ['cache' => 'redis'],
            ['cache' => ['driver' => 'redis', 'ttl' => 60]],
        );

        $this->assertArrayHasKey('cache', $changeSet->typeChanges());
        $this->assertSame('string', $changeSet->typeChanges()['cache']['oldType']);
        $this->assertSame('object', $changeSet->typeChanges()['cache']['newType']);
        $this->assertSame([], $changeSet->added());
        $this->assertSame([], $changeSet->removed());
    }

    public function testListsAreTreatedAsAtomicValues(): void
    {
        $changeSet = $this->diff->diff(
            ['hosts' => ['a', 'b']],
            ['hosts' => ['a', 'b', 'c']],
        );

        $this->assertSame(
            ['hosts' => ['old' => ['a', 'b'], 'new' => ['a', 'b', 'c']]],
            $changeSet->updated(),
        );
        $this->assertArrayNotHasKey('hosts.0', $changeSet->updated());
        $this->assertArrayNotHasKey('hosts.2', $changeSet->added());
    }

    public function testEmptyObjectsAndNullValuesAreReported(): void
    {
        $changeSet = $this->diff->diff(
            ['empty' => [], 'nullable' => 'value'],
            ['empty' => ['now' => 'used'], 'nullable' => null],
        );

        // An empty array is indistinguishable from an empty map after JSON
        // decoding, so materialising keys is a type change at that path.
        $this->assertArrayHasKey('empty', $changeSet->typeChanges());
        $this->assertSame('array', $changeSet->typeChanges()['empty']['oldType']);
        $this->assertSame('object', $changeSet->typeChanges()['empty']['newType']);
        $this->assertArrayHasKey('nullable', $changeSet->typeChanges());
        $this->assertSame('null', $changeSet->typeChanges()['nullable']['newType']);
    }

    public function testFlattenerUsesDotNotationAndCountsLeaves(): void
    {
        $flattener = new Flattener();
        $flat      = $flattener->flatten([
            'a' => ['b' => ['c' => 1]],
            'list' => [1, 2],
            'empty' => [],
            'nil' => null,
        ]);

        $this->assertSame(
            ['a.b.c' => 1, 'list' => [1, 2], 'empty' => [], 'nil' => null],
            $flat,
        );
        $this->assertSame(4, $flattener->leafCount([
            'a' => ['b' => ['c' => 1]],
            'list' => [1, 2],
            'empty' => [],
            'nil' => null,
        ]));
    }

    public function testDottedKeysAreEscapedAndCannotCollideWithNesting(): void
    {
        $flattener = new Flattener();

        $this->assertSame('a\\.b', $flattener->pathSegment('a.b'));
        $this->assertSame('a\\\\b', $flattener->pathSegment('a\\b'));

        $flat = $flattener->flatten(['a' => ['b' => 1], 'a.b' => 2]);
        $this->assertSame(['a.b' => 1, 'a\\.b' => 2], $flat);

        $changeSet = $this->diff->diff(
            ['a' => ['b' => 1], 'a.b' => 2],
            ['a' => ['b' => 9], 'a.b' => 8],
        );
        $this->assertSame(
            [
                'a.b'   => ['old' => 1, 'new' => 9],
                'a\\.b' => ['old' => 2, 'new' => 8],
            ],
            $changeSet->updated(),
        );

        // Escaping also applies to newly added/removed subtrees.
        $added = $this->diff->diff([], ['a' => ['b.c' => 3]]);
        $this->assertSame(['a.b\\.c' => 3], $added->added());
    }

    public function testToArrayMatchesAccessors(): void
    {
        $changeSet = $this->diff->diff(['a' => 1], ['a' => 2, 'b' => 3]);

        $this->assertSame(
            [
                'added'        => $changeSet->added(),
                'updated'      => $changeSet->updated(),
                'removed'      => $changeSet->removed(),
                'type_changes' => $changeSet->typeChanges(),
            ],
            $changeSet->toArray(),
        );
    }
}
