<?php

use App\Support\CrossBorder\TransferIntent;

test('it reads amounts, currencies and numbers the way people write them', function (string $message, ?string $amount, ?string $currency, array $phones) {
    $intent = TransferIntent::fromMessages([$message]);
    $latest = $intent->latestAmount();

    expect($latest ? (string) $latest['amount'] : null)->toBe($amount)
        ->and($latest['currency'] ?? null)->toBe($currency)
        ->and($intent->phones)->toBe($phones);
})->with([
    ['sent 3 kwacha to 0971943638', '3', 'KWACHA', ['0971943638']],
    ['send K2,500 to mum on 0977 123 456', '2500', 'KWACHA', ['0977123456']],
    ['pay $20 to +260 97 194 3638', '20', 'USD', ['260971943638']],
    ['20usd please', '20', 'USD', []],
    ['100 euros to 0961234567', '100', 'EUR', ['0961234567']],
    ['make it 1500', '1500', null, []],
    ['try again', null, null, []],
]);

test('the most recent amount is the one that counts', function () {
    $intent = TransferIntent::fromMessages(['send K2,500 to 0977123456', 'actually make it 3 kwacha']);

    expect((string) $intent->latestAmount()['amount'])->toBe('3');
});

test('a transfer must match what the person asked for', function () {
    $intent = TransferIntent::fromMessages(['sent 3 kwacha to 0971943638', 'try again']);

    expect($intent->mismatch(3, 'target', 'ZM', 'USD', '260971943638'))->toBeNull()
        ->and($intent->mismatch(20, 'source', 'ZM', 'USD', '260971943638'))->toBe('The user asked for 3 ZMW, not 20. Quote exactly what they asked for.')
        ->and($intent->mismatch(3, 'source', 'ZM', 'USD'))->toBe('The user asked for 3 ZMW, which is what the recipient receives: use mode "target".')
        ->and($intent->mismatch(3, 'target', 'ZM', 'USD', '260977000000'))->toBe('The user did not give that mobile number. Use the number they gave, or ask them for it.');
});

test('kwacha means the recipient country\'s kwacha', function () {
    $intent = TransferIntent::fromMessages(['send 500 kwacha to 0991234567']);

    expect($intent->mismatch(500, 'target', 'MW', 'USD'))->toBeNull()
        ->and($intent->mismatch(500, 'target', 'ZM', 'USD'))->toBeNull();
});

test('without an amount or a number from the person, nothing can be sent', function () {
    expect(TransferIntent::fromMessages(['try again'])->mismatch(20, 'source', 'ZM', 'USD'))
        ->toBe('The user has not said how much to send in this conversation. Ask them for the amount; never reuse an amount from an earlier transfer.')
        ->and(TransferIntent::fromMessages(['send $20'])->mismatch(20, 'source', 'ZM', 'USD', '260971943638'))
        ->toBe('The user has not given the recipient\'s mobile number in this conversation. Ask for it; never reuse a number from an earlier transfer.');
});

test('a payer currency other than the default must be named in the quote', function () {
    $intent = TransferIntent::fromMessages(['send 100 euros']);

    expect($intent->mismatch(100, 'source', 'ZM', 'EUR'))->toBeNull()
        ->and($intent->mismatch(100, 'source', 'ZM', 'USD'))->toBe('The user asked for 100 EUR: set source_currency to EUR.');
});
