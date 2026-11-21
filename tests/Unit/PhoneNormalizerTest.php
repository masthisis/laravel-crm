<?php

use App\Support\PhoneNormalizer;

/** Canonical mobile rule (spec 01 §1.1). */
it('normalises every accepted notation to 9xxxxxxxxx', function (string $raw, string $expected) {
    expect(PhoneNormalizer::canonical($raw))->toBe($expected);
})->with([
    ['9121234567', '9121234567'],          // already canonical
    ['09121234567', '9121234567'],         // leading zero
    ['989121234567', '9121234567'],        // country code
    ['+989121234567', '9121234567'],       // plus country code
    ['00989121234567', '9121234567'],      // double-zero prefix
    ['۰۹۱۲۱۲۳۴۵۶۷', '9121234567'],          // Persian digits
    ['٠٩١٢١٢٣٤٥٦٧', '9121234567'],          // Arabic digits
    ['0912-123 4567', '9121234567'],       // separators
    ['0912.123(4567)', '9121234567'],
    ["0912\u{200C}1234567", '9121234567'], // ZWNJ
]);

it('rejects invalid phones with null', function (?string $raw) {
    expect(PhoneNormalizer::canonical($raw))->toBeNull();
})->with([
    [null],
    [''],
    ['not a phone'],
    ['9121234567 8'],      // wrong length after cleaning
    ['2121234567'],        // landline (starts with 2)
    ['0912123456'],        // 9 digits after stripping 0
    ['abc09121234567'],
]);

it('builds lookup variants for legacy columns', function () {
    expect(PhoneNormalizer::variants('9121234567'))->toBe([
        '9121234567',
        '09121234567',
        '989121234567',
        '+989121234567',
        '00989121234567',
    ]);
});

it('validates with isValid', function () {
    expect(PhoneNormalizer::isValid('09121234567'))->toBeTrue()
        ->and(PhoneNormalizer::isValid('2121234567'))->toBeFalse();
});
