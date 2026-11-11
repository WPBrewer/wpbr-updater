<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Checks payloads against the vendored wpbr-license/v1 schemas.
 *
 * @license GPL-3.0-or-later
 */
final class SchemaAssert
{
    private static ?Validator $validator = null;

    public static function assertValid(string $json, string $schemaId): void
    {
        $data = json_decode($json);
        Assert::assertNotNull($data, 'Response is not JSON: ' . substr($json, 0, 200));

        $result = self::validator()->validate($data, $schemaId);
        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $formatted = $error === null ? 'invalid' : (string) json_encode((new ErrorFormatter())->format($error), JSON_UNESCAPED_SLASHES);
        Assert::fail($schemaId . ' rejected the payload: ' . $formatted . ' payload=' . $json);
    }

    public static function assertInvalid(string $json, string $schemaId): void
    {
        $data = json_decode($json);
        Assert::assertNotNull($data);
        Assert::assertFalse(self::validator()->validate($data, $schemaId)->isValid());
    }

    private static function validator(): Validator
    {
        if (self::$validator instanceof Validator) {
            return self::$validator;
        }

        $validator = new Validator();
        $dir = dirname(__DIR__) . '/contract/schema';
        $validator->resolver()->registerPrefix(
            'https://wpbrewer.com/schema/wpbr-license/v1/',
            $dir
        );
        self::$validator = $validator;
        return $validator;
    }
}
