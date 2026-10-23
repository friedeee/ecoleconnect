<?php

namespace Tests\Unit;

use App\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function amounts(): array
    {
        return [
            'one' => [1, 'un'],
            'seventeen' => [17, 'dix-sept'],
            'twenty-one' => [21, 'vingt et un'],
            'seventy-one' => [71, 'soixante et onze'],
            'seventy-seven' => [77, 'soixante-dix-sept'],
            'eighty' => [80, 'quatre-vingts'],
            'eighty-one' => [81, 'quatre-vingt-un'],
            'ninety-one' => [91, 'quatre-vingt-onze'],
            'hundred' => [100, 'cent'],
            'three hundred' => [300, 'trois cents'],
            'three hundred and five' => [305, 'trois cent cinq'],
            'thousand' => [1000, 'mille'],
            'registration fee' => [15000, 'quinze mille'],
            'eighty thousand' => [80000, 'quatre-vingt mille'],
            'two hundred thousand' => [200000, 'deux cent mille'],
            'tuition' => [285500, 'deux cent quatre-vingt-cinq mille cinq cents'],
            'one million' => [1000000, 'un million'],
            'millions' => [2350000, 'deux millions trois cent cinquante mille'],
            'eighty million' => [80000000, 'quatre-vingts millions'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_written_out_in_french(int $amount, string $words): void
    {
        $this->assertSame($words, Payment::amountInWords($amount));
    }
}
