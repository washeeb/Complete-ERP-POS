<?php

namespace App\Support;

/** EAN-13 check digits and SVG bars (for markdown stickers and scale labels). */
final class Ean13
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function complete(string $twelve): string
    {
        return $twelve.self::checkDigit($twelve);
    }

    public static function valid(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code) && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    public static function svg(string $code, int $height = 50): string
    {
        $parity = self::PARITY[(int) $code[0]];
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bits .= ($parity[$i - 1] === 'L' ? self::L : self::G)[(int) $code[$i]];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::R[(int) $code[$i]];
        }
        $bits .= '101';
        $rects = '';
        foreach (str_split($bits) as $x => $b) {
            if ($b === '1') {
                $rects .= '<rect x="'.($x + 10).'" y="0" width="1" height="'.$height.'"/>';
            }
        }
        $w = strlen($bits) + 20;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.($height + 12).'" width="'.($w * 2).'" height="'.(($height + 12) * 2).'" shape-rendering="crispEdges">'
            .'<rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$rects.'</g>'
            .'<text x="'.($w / 2).'" y="'.($height + 10).'" font-size="9" text-anchor="middle" font-family="monospace">'.$code.'</text></svg>';
    }
}
