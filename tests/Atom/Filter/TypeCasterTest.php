<?php

declare(strict_types=1);

namespace Tests\Atom\Filter;

use Atom\Component\Uid\Ulid;
use Atom\Component\Uid\Uuid;
use Atom\DataBase\DbModel;
use Atom\Filter\InputFilter;
use Atom\Filter\TypeCaster;
use Atom\Form\Field;
use Atom\Form\Form;
use Atom\HttpFoundation\Request;
use Atom\Model;
use Atom\Structure\Cast;
use Atom\Types\Point;
use PHPUnit\Framework\TestCase;
use SortDirection;
use Time\Duration;

enum SampleStatusEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class SampleFormAndDbModel extends DbModel
{
    #[Cast('int', 'ignore')]
    public int $id = 0;

    public string $title = '';

    public string $email = '';

    public string $password = '';

    #[Cast('int', min: 1, max: 120)]
    public int $age = 18;

    #[Cast('float', min: 0.0, max: 1000.0)]
    public float $price = 0.0;

    public bool $isActive = false;

    public ?int $optionalCount = null;

    public SampleStatusEnum $status = SampleStatusEnum::Draft;

    public SortDirection $sortOrder = SortDirection::Ascending;

    public ?Duration $timeout = null;

    public ?Point $location = null;

    public ?Uuid $externalUuid = null;

    public ?Ulid $traceUlid = null;

    /** @var array<int|string, mixed> */
    #[Cast('json', 'json')]
    public array $tags = [];

    #[\Override]
    public static function tableName(): string
    {
        return 'sample_items';
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function attributes(): array
    {
        return [
            'id',
            'title',
            'email',
            'age',
            'price',
            'isActive',
            'optionalCount',
            'status',
            'sortOrder',
            'timeout',
            'location',
            'externalUuid',
            'traceUlid',
            'tags',
        ];
    }

    #[\Override]
    public function rules(): array
    {
        return [
            'title' => [Model::RULE_REQUIRED],
            'email' => [Model::RULE_REQUIRED, Model::RULE_EMAIL],
        ];
    }
}

final class TypeCasterTest extends TestCase
{
    public function testInputFilterSanitizesControlBytesWhilePreservingPasswords(): void
    {
        $raw = [
            'title' => "  Hello\0World\r\n  ",
            'password' => "  My Secret Pass!  \0",
            'nested' => [
                'note' => "  line1\r\nline2  ",
            ],
        ];

        $cleaned = InputFilter::cleanArray($raw);

        self::assertSame('HelloWorld', $cleaned['title']);
        self::assertSame('  My Secret Pass!  ', $cleaned['password']);
        self::assertSame("line1\nline2", $cleaned['nested']['note']);
    }

    public function testLoadDataAutomaticallyFiltersAndClampsFormInput(): void
    {
        $model = new SampleFormAndDbModel();
        $model->loadData([
            'title' => '  Atom PHP 8.6  ',
            'email' => '  dev@example.com ',
            'password' => '  keep-leading-spaces  ',
            'age' => '999',
            'price' => '49,95',
            'isActive' => 'on',
            'optionalCount' => '',
            'status' => 'published',
            'sortOrder' => 'DESC',
            'timeout' => 'PT45S',
            'location' => 'POINT(19.9449 50.0647)',
            'externalUuid' => '550e8400-e29b-41d4-a716-446655440000',
            'traceUlid' => '01ARZ3NDEM0000000000000000',
            'tags' => '["php86","swoole"]',
        ]);

        self::assertSame('Atom PHP 8.6', $model->title);
        self::assertSame('dev@example.com', $model->email);
        self::assertSame('  keep-leading-spaces  ', $model->password);
        self::assertSame(120, $model->age, 'Age should be clamped via PHP 8.6 clamp() to #[Cast(max: 120)]');
        self::assertEqualsWithDelta(49.95, $model->price, 0.0001);
        self::assertTrue($model->isActive);
        self::assertNull($model->optionalCount);
        self::assertSame(SampleStatusEnum::Published, $model->status);
        self::assertSame(SortDirection::Descending, $model->sortOrder);
        self::assertInstanceOf(Duration::class, $model->timeout);
        self::assertSame(45, $model->timeout->seconds);
        self::assertInstanceOf(Point::class, $model->location);
        self::assertEqualsWithDelta(19.9449, $model->location->latitude, 0.0001);
        self::assertEqualsWithDelta(50.0647, $model->location->longitude, 0.0001);
        self::assertInstanceOf(Uuid::class, $model->externalUuid);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $model->externalUuid->toString());
        self::assertInstanceOf(Ulid::class, $model->traceUlid);
        self::assertSame('01ARZ3NDEM0000000000000000', $model->traceUlid->toString());
        self::assertSame(['php86', 'swoole'], $model->tags);
    }

    public function testDatabaseHydrationAndNonDestructiveExtraction(): void
    {
        $binaryUuid = \hex2bin('550e8400e29b41d4a716446655440000');
        self::assertIsString($binaryUuid);

        $model = TypeCaster::hydrateFromDatabase(SampleFormAndDbModel::class, [
            'id' => '42',
            'title' => 'DB Row',
            'email' => 'db@example.com',
            'age' => '35',
            'price' => '199.50',
            'is_active' => '1',
            'optional_count' => '7',
            'status' => 'published',
            'sort_order' => 'ASC',
            'timeout' => '120',
            'location' => 'POINT(21.0122 52.2297)',
            'external_uuid' => $binaryUuid,
            'trace_ulid' => '01ARZ3NDEM0000000000000000',
            'tags' => '["db","fast"]',
        ]);

        self::assertSame(42, $model->id);
        self::assertTrue($model->isActive);
        self::assertSame(7, $model->optionalCount);
        self::assertSame(SampleStatusEnum::Published, $model->status);
        self::assertSame(SortDirection::Ascending, $model->sortOrder);
        self::assertInstanceOf(Duration::class, $model->timeout);
        self::assertSame(120, $model->timeout->seconds);
        self::assertInstanceOf(Uuid::class, $model->externalUuid);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $model->externalUuid->toString());

        $payload = TypeCaster::extractForDatabase(
            $model,
            SampleFormAndDbModel::attributes(),
            snakeCaseKeys: true,
        );

        self::assertArrayNotHasKey('id', $payload, 'Field with detype: "ignore" should be omitted on DB write');
        self::assertSame(1, $payload['is_active']);
        self::assertSame('published', $payload['status']);
        self::assertSame('ASC', $payload['sort_order']);
        self::assertSame('["db","fast"]', $payload['tags']);

        // Verify extraction was non-destructive: model still holds rich PHP objects
        self::assertInstanceOf(Uuid::class, $model->externalUuid);
        self::assertInstanceOf(Point::class, $model->location);
        self::assertInstanceOf(Duration::class, $model->timeout);
    }

    public function testRequestFillModelAndFormAutoFieldInference(): void
    {
        $request = Request::create(
            query: [],
            post: [
                'title' => 'Created via Request DTO',
                'email' => 'user@atom.dev',
                'age' => '28',
                'isActive' => '1',
            ],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/items',
            ],
        );

        $model = $request->fillModel(new SampleFormAndDbModel());
        self::assertSame('Created via Request DTO', $model->title);
        self::assertSame(28, $model->age);
        self::assertTrue($model->isActive);

        $emailField = new Field($model, 'email');
        $emailField->autoType();
        self::assertSame(Field::TYPE_EMAIL, $emailField->type);

        $passwordField = new Field($model, 'password');
        $passwordField->autoType();
        self::assertSame(Field::TYPE_PASSWORD, $passwordField->type);

        $ageField = new Field($model, 'age');
        $ageField->autoType();
        self::assertSame(Field::TYPE_NUMBER, $ageField->type);

        $boolField = new Field($model, 'isActive');
        $boolField->autoType();
        self::assertSame(Field::TYPE_CHECKBOX, $boolField->type);

        $form = new Form();
        $rendered = (string) $form->autoField($model, 'email');
        self::assertStringContainsString('type="email"', $rendered);
        self::assertStringContainsString('value="user@atom.dev"', $rendered);
    }
}
