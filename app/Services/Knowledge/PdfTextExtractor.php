<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Pure-PHP PDF text extraction (no external binaries): handles FlateDecode streams, object streams,
 * standard and CID fonts with ToUnicode CMaps. Scanned PDFs (images only) yield no text.
 */
final class PdfTextExtractor
{
    private string $data;
    /** @var array<int, string> object number => raw object body */
    private array $objects = [];
    /** @var array<int, array<int, int>> font map cache: object id => [code => unicode] */
    private array $cmapCache = [];

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    public static function extract(string $path): string
    {
        $data = (string) file_get_contents($path);
        if ($data === '' || !str_starts_with(ltrim($data), '%PDF')) {
            throw new \RuntimeException('Not a PDF file.');
        }
        if (preg_match('/\/Encrypt\s/', $data)) {
            return '';
        }
        $extractor = new self($data);
        $extractor->loadObjects();
        return $extractor->pagesText();
    }

    private function loadObjects(): void
    {
        if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', $this->data, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $this->objects[(int) $m[1]] = $m[3];
            }
        }
        // Expand object streams (PDF 1.5+)
        foreach ($this->objects as $id => $body) {
            if (preg_match('/\/Type\s*\/ObjStm/', $body)) {
                $this->expandObjectStream($body);
            }
        }
    }

    private function expandObjectStream(string $body): void
    {
        $content = $this->streamContent($body);
        if ($content === null) {
            return;
        }
        if (!preg_match('/\/N\s+(\d+)/', $body, $n) || !preg_match('/\/First\s+(\d+)/', $body, $first)) {
            return;
        }
        $count = (int) $n[1];
        $firstOffset = (int) $first[1];
        $header = substr($content, 0, $firstOffset);
        $numbers = preg_split('/\s+/', trim($header)) ?: [];
        for ($i = 0; $i + 1 < count($numbers) && $i / 2 < $count; $i += 2) {
            $objId = (int) $numbers[$i];
            $offset = (int) $numbers[$i + 1];
            $nextOffset = isset($numbers[$i + 3]) ? (int) $numbers[$i + 3] : strlen($content) - $firstOffset;
            $objBody = substr($content, $firstOffset + $offset, max(0, $nextOffset - $offset));
            if (!isset($this->objects[$objId])) {
                $this->objects[$objId] = $objBody;
            }
        }
    }

    /** Decoded stream content of an object body, or null when there is no stream / unsupported filter. */
    private function streamContent(string $body): ?string
    {
        $pos = strpos($body, 'stream');
        if ($pos === false) {
            return null;
        }
        $start = $pos + 6;
        if (substr($body, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (substr($body, $start, 1) === "\n") {
            $start += 1;
        }
        $end = strrpos($body, 'endstream');
        if ($end === false || $end <= $start) {
            return null;
        }
        $raw = substr($body, $start, $end - $start);
        $dict = substr($body, 0, $pos);
        if (preg_match('/\/Length\s+(\d+)(?!\s+0\s+R)/', $dict, $len) && (int) $len[1] > 0 && (int) $len[1] <= strlen($raw)) {
            $raw = substr($raw, 0, (int) $len[1]);
        } else {
            $raw = rtrim($raw, "\r\n");
        }
        $filters = [];
        if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $dict, $f)) {
            preg_match_all('/\/([A-Za-z0-9]+)/', $f[1], $names);
            $filters = $names[1];
        } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dict, $f)) {
            $filters = [$f[1]];
        }
        $content = $raw;
        foreach ($filters as $filter) {
            switch ($filter) {
                case 'FlateDecode':
                case 'Fl':
                    $decoded = @gzuncompress($content);
                    if ($decoded === false) {
                        $decoded = @gzinflate(substr($content, 2));
                    }
                    if ($decoded === false) {
                        $decoded = @gzinflate($content);
                    }
                    if ($decoded === false) {
                        return null;
                    }
                    $content = $decoded;
                    break;
                case 'ASCIIHexDecode':
                case 'AHx':
                    $content = (string) hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', rtrim($content, '>')) ?? '');
                    break;
                case 'ASCII85Decode':
                case 'A85':
                    $content = self::ascii85($content);
                    break;
                case 'LZWDecode':
                case 'DCTDecode':
                case 'JPXDecode':
                case 'CCITTFaxDecode':
                case 'JBIG2Decode':
                    return null; // images / unsupported
            }
        }
        if (preg_match('/\/Predictor\s+(\d+)/', $dict, $p) && (int) $p[1] >= 10) {
            $columns = preg_match('/\/Columns\s+(\d+)/', $dict, $c) ? (int) $c[1] : 1;
            $content = self::pngPredictor($content, $columns);
        }
        return $content;
    }

    private function pagesText(): string
    {
        $pages = [];
        foreach ($this->objects as $id => $body) {
            if (preg_match('/\/Type\s*\/Page\b(?!s)/', $body)) {
                $pages[$id] = $body;
            }
        }
        // Order pages by the /Kids tree when possible
        $ordered = $this->orderPages(array_keys($pages));
        $text = '';
        foreach ($ordered as $id) {
            $pageText = $this->pageText($pages[$id]);
            if (trim($pageText) !== '') {
                $text .= $pageText . "\n\n";
            }
        }
        return trim($text);
    }

    private function orderPages(array $pageIds): array
    {
        $rootId = null;
        foreach ($this->objects as $id => $body) {
            if (preg_match('/\/Type\s*\/Pages\b/', $body) && !preg_match('/\/Parent\s+\d+\s+\d+\s+R/', $body)) {
                $rootId = $id;
                break;
            }
        }
        if ($rootId === null) {
            return $pageIds;
        }
        $ordered = [];
        $visit = function (int $id, int $depth) use (&$visit, &$ordered, $pageIds): void {
            if ($depth > 50 || !isset($this->objects[$id])) {
                return;
            }
            $body = $this->objects[$id];
            if (preg_match('/\/Kids\s*\[([^\]]*)\]/s', $body, $kids)) {
                preg_match_all('/(\d+)\s+\d+\s+R/', $kids[1], $refs);
                foreach ($refs[1] as $ref) {
                    $visit((int) $ref, $depth + 1);
                }
            } elseif (in_array($id, $pageIds, true)) {
                $ordered[] = $id;
            }
        };
        $visit($rootId, 0);
        foreach ($pageIds as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        return $ordered;
    }

    private function pageText(string $pageBody): string
    {
        // Fonts available on this page: resource name => font object id
        $fonts = $this->pageFonts($pageBody);
        $contents = [];
        if (preg_match('/\/Contents\s*\[([^\]]*)\]/s', $pageBody, $arr)) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $arr[1], $refs);
            foreach ($refs[1] as $ref) {
                $contents[] = (int) $ref;
            }
        } elseif (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pageBody, $single)) {
            $contents[] = (int) $single[1];
        }
        $stream = '';
        foreach ($contents as $ref) {
            if (isset($this->objects[$ref])) {
                $part = $this->streamContent($this->objects[$ref]);
                if ($part !== null) {
                    $stream .= $part . "\n";
                }
            }
        }
        if ($stream === '') {
            return '';
        }
        return $this->contentToText($stream, $fonts);
    }

    /** @return array<string, int> */
    private function pageFonts(string $pageBody): array
    {
        $resources = '';
        if (preg_match('/\/Resources\s+(\d+)\s+\d+\s+R/', $pageBody, $m) && isset($this->objects[(int) $m[1]])) {
            $resources = $this->objects[(int) $m[1]];
        } elseif (preg_match('/\/Resources\s*<<(.*)/s', $pageBody, $m)) {
            $resources = $m[1];
        } elseif (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $pageBody, $p) && isset($this->objects[(int) $p[1]])) {
            return $this->pageFonts($this->objects[(int) $p[1]]);
        }
        $fonts = [];
        $fontDict = '';
        if (preg_match('/\/Font\s+(\d+)\s+\d+\s+R/', $resources, $m) && isset($this->objects[(int) $m[1]])) {
            $fontDict = $this->objects[(int) $m[1]];
        } elseif (preg_match('/\/Font\s*<<(.*?)>>/s', $resources, $m)) {
            $fontDict = $m[1];
        }
        if ($fontDict !== '' && preg_match_all('/\/([A-Za-z0-9_.+-]+)\s+(\d+)\s+\d+\s+R/', $fontDict, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $fonts[$pair[1]] = (int) $pair[2];
            }
        }
        return $fonts;
    }

    /** @return array<int, int>|null code => unicode codepoint, null when no ToUnicode map exists */
    private function fontMap(int $fontId): ?array
    {
        if (array_key_exists($fontId, $this->cmapCache)) {
            return $this->cmapCache[$fontId];
        }
        $this->cmapCache[$fontId] = null;
        $body = $this->objects[$fontId] ?? '';
        if ($body === '' || !preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $m)) {
            return null;
        }
        $cmap = isset($this->objects[(int) $m[1]]) ? $this->streamContent($this->objects[(int) $m[1]]) : null;
        if ($cmap === null) {
            return null;
        }
        $map = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $map[hexdec($pair[1])] = self::utf16Hex($pair[2]);
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[([^\]]*)\])/', $block, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) {
                        $lo = hexdec($r[1]);
                        $hi = hexdec($r[2]);
                        if ($hi - $lo > 65535) {
                            continue;
                        }
                        if (isset($r[5]) && $r[5] !== '') {
                            preg_match_all('/<([0-9A-Fa-f]+)>/', $r[5], $items);
                            foreach ($items[1] as $i => $hex) {
                                $map[$lo + $i] = self::utf16Hex($hex);
                            }
                        } else {
                            $base = self::utf16Hex($r[4]);
                            for ($c = $lo; $c <= $hi; $c++) {
                                $map[$c] = $base + ($c - $lo);
                            }
                        }
                    }
                }
            }
        }
        $this->cmapCache[$fontId] = $map ?: null;
        return $this->cmapCache[$fontId];
    }

    private static function utf16Hex(string $hex): int
    {
        $hex = strlen($hex) % 4 === 0 ? $hex : str_pad($hex, (int) (ceil(strlen($hex) / 4) * 4), '0', STR_PAD_LEFT);
        $units = str_split($hex, 4);
        $first = hexdec($units[0]);
        if ($first >= 0xD800 && $first <= 0xDBFF && isset($units[1])) {
            $second = hexdec($units[1]);
            return 0x10000 + (($first - 0xD800) << 10) + ($second - 0xDC00);
        }
        return $first;
    }

    private function contentToText(string $stream, array $fonts): string
    {
        $out = '';
        $currentMap = null;
        $twoByte = false;
        $lastY = null;
        $lastX = null;
        $fontSize = 10.0;
        // Tokenise operators: strings, arrays, names, numbers, operators
        $len = strlen($stream);
        $i = 0;
        $operands = [];
        $inText = false;
        while ($i < $len) {
            $ch = $stream[$i];
            if ($ch === '(') {
                [$str, $i] = self::readLiteral($stream, $i);
                $operands[] = ['s', $str];
                continue;
            }
            if ($ch === '<' && ($stream[$i + 1] ?? '') !== '<') {
                $end = strpos($stream, '>', $i);
                if ($end === false) {
                    break;
                }
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($stream, $i + 1, $end - $i - 1)) ?? '';
                $operands[] = ['h', $hex];
                $i = $end + 1;
                continue;
            }
            if ($ch === '[') {
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($stream[$j] === '(') {
                        [, $j] = self::readLiteral($stream, $j);
                        continue;
                    }
                    if ($stream[$j] === '[') {
                        $depth++;
                    } elseif ($stream[$j] === ']') {
                        $depth--;
                    }
                    $j++;
                }
                $operands[] = ['a', substr($stream, $i + 1, max(0, $j - $i - 2))];
                $i = $j;
                continue;
            }
            if ($ch === '<' && ($stream[$i + 1] ?? '') === '<') {
                $end = strpos($stream, '>>', $i);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === '/') {
                $j = $i + 1;
                while ($j < $len && !ctype_space($stream[$j]) && !in_array($stream[$j], ['/', '[', ']', '(', '<', '>'], true)) {
                    $j++;
                }
                $operands[] = ['n', substr($stream, $i + 1, $j - $i - 1)];
                $i = $j;
                continue;
            }
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '%') {
                $end = strpos($stream, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            // number or operator
            $j = $i;
            while ($j < $len && !ctype_space($stream[$j]) && !in_array($stream[$j], ['/', '[', ']', '(', '<', '>', '%'], true)) {
                $j++;
            }
            $token = substr($stream, $i, max(1, $j - $i));
            $i = max($j, $i + 1);
            if (is_numeric($token)) {
                $operands[] = ['num', (float) $token];
                continue;
            }
            // operator
            switch ($token) {
                case 'BT':
                    $inText = true;
                    $lastY = null;
                    break;
                case 'ET':
                    $inText = false;
                    $out .= "\n";
                    break;
                case 'Tf':
                    $nameOp = null;
                    foreach ($operands as $op) {
                        if ($op[0] === 'n') {
                            $nameOp = $op[1];
                        } elseif ($op[0] === 'num') {
                            $fontSize = (float) $op[1] ?: $fontSize;
                        }
                    }
                    $currentMap = null;
                    $twoByte = false;
                    if ($nameOp !== null && isset($fonts[$nameOp])) {
                        $currentMap = $this->fontMap($fonts[$nameOp]);
                        $fontBody = $this->objects[$fonts[$nameOp]] ?? '';
                        $twoByte = (bool) preg_match('/\/Type0\b|Identity-H|Identity-V/', $fontBody);
                    }
                    break;
                case 'Td':
                case 'TD':
                    $nums = array_values(array_filter($operands, static fn($o) => $o[0] === 'num'));
                    if (count($nums) >= 2) {
                        $dy = (float) $nums[count($nums) - 1][1];
                        $dx = (float) $nums[count($nums) - 2][1];
                        if (abs($dy) > $fontSize * 0.5) {
                            $out .= "\n";
                        } elseif ($dx > $fontSize * 0.3 && $out !== '' && !str_ends_with($out, ' ')) {
                            $out .= ' ';
                        }
                    }
                    break;
                case 'Tm':
                    $nums = array_values(array_filter($operands, static fn($o) => $o[0] === 'num'));
                    if (count($nums) >= 6) {
                        $y = (float) $nums[5][1];
                        $x = (float) $nums[4][1];
                        if ($lastY !== null && abs($y - $lastY) > $fontSize * 0.5) {
                            $out .= "\n";
                        } elseif ($lastX !== null && $x - $lastX > $fontSize * 0.3 && $out !== '' && !str_ends_with($out, ' ')) {
                            $out .= ' ';
                        }
                        $lastY = $y;
                        $lastX = $x;
                    }
                    break;
                case 'T*':
                    $out .= "\n";
                    break;
                case 'Tj':
                case '\'':
                case '"':
                    if ($token !== 'Tj') {
                        $out .= "\n";
                    }
                    foreach ($operands as $op) {
                        if ($op[0] === 's' || $op[0] === 'h') {
                            $out .= $this->decodeString($op, $currentMap, $twoByte);
                        }
                    }
                    break;
                case 'TJ':
                    foreach ($operands as $op) {
                        if ($op[0] !== 'a') {
                            continue;
                        }
                        $out .= $this->decodeArray($op[1], $currentMap, $twoByte, $fontSize);
                    }
                    break;
            }
            $operands = [];
        }
        // Tidy: collapse spaces, fix hyphenation artefacts
        $out = preg_replace('/[ \t]+/', ' ', $out) ?? $out;
        $out = preg_replace('/ *\n */', "\n", $out) ?? $out;
        $out = preg_replace('/(\p{L})-\n(\p{Ll})/u', '$1$2', $out) ?? $out;
        return trim($out);
    }

    private function decodeArray(string $inner, ?array $map, bool $twoByte, float $fontSize): string
    {
        $out = '';
        $len = strlen($inner);
        $i = 0;
        while ($i < $len) {
            $ch = $inner[$i];
            if ($ch === '(') {
                [$str, $i] = self::readLiteral($inner, $i);
                $out .= $this->decodeString(['s', $str], $map, $twoByte);
                continue;
            }
            if ($ch === '<') {
                $end = strpos($inner, '>', $i);
                if ($end === false) {
                    break;
                }
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($inner, $i + 1, $end - $i - 1)) ?? '';
                $out .= $this->decodeString(['h', $hex], $map, $twoByte);
                $i = $end + 1;
                continue;
            }
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $len && !ctype_space($inner[$j]) && $inner[$j] !== '(' && $inner[$j] !== '<') {
                $j++;
            }
            $num = substr($inner, $i, $j - $i);
            if (is_numeric($num) && (float) $num < -180 && $out !== '' && !str_ends_with($out, ' ')) {
                $out .= ' ';
            }
            $i = max($j, $i + 1);
        }
        return $out;
    }

    private function decodeString(array $op, ?array $map, bool $twoByte): string
    {
        $bytes = $op[0] === 'h' ? (string) hex2bin(strlen($op[1]) % 2 ? $op[1] . '0' : $op[1]) : $op[1];
        if ($bytes === '') {
            return '';
        }
        $out = '';
        if ($map !== null) {
            $width = $twoByte ? 2 : 1;
            // Detect single-byte maps used with hex strings
            if (!$twoByte && $op[0] === 'h' && strlen($bytes) % 2 === 0 && max(array_keys($map)) > 255) {
                $width = 2;
            }
            for ($i = 0; $i + $width <= strlen($bytes); $i += $width) {
                $code = $width === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]) : ord($bytes[$i]);
                if (isset($map[$code])) {
                    $out .= self::codepoint($map[$code]);
                } elseif ($width === 1 && $code >= 32 && $code < 127) {
                    $out .= chr($code);
                }
            }
            return $out;
        }
        if ($twoByte) {
            // Identity encoding without ToUnicode: try treating as UTF-16BE
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
            return $converted !== false && mb_check_encoding($converted, 'UTF-8') ? $converted : '';
        }
        // Standard / WinAnsi single byte
        $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        return $converted !== false ? $converted : $bytes;
    }

    private static function codepoint(int $cp): string
    {
        if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return '';
        }
        return mb_chr($cp, 'UTF-8') ?: '';
    }

    /** Read a PDF literal string starting at "(" and return [decoded, nextIndex]. */
    private static function readLiteral(string $s, int $i): array
    {
        $len = strlen($s);
        $depth = 0;
        $out = '';
        for ($j = $i; $j < $len; $j++) {
            $c = $s[$j];
            if ($c === '\\') {
                $n = $s[$j + 1] ?? '';
                $j++;
                switch ($n) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\x0c"; break;
                    case '(': case ')': case '\\': $out .= $n; break;
                    case "\n": break;
                    case "\r": if (($s[$j + 1] ?? '') === "\n") { $j++; } break;
                    default:
                        if (ctype_digit($n)) {
                            $oct = $n;
                            while (strlen($oct) < 3 && ctype_digit($s[$j + 1] ?? '')) {
                                $oct .= $s[++$j];
                            }
                            $out .= chr(octdec($oct) & 255);
                        } else {
                            $out .= $n;
                        }
                }
                continue;
            }
            if ($c === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return [$out, $j + 1];
                }
            }
            $out .= $c;
        }
        return [$out, $len];
    }

    private static function ascii85(string $data): string
    {
        $data = preg_replace('/^<~|~>$/', '', trim($data)) ?? $data;
        $data = preg_replace('/\s/', '', $data) ?? $data;
        $out = '';
        $tuple = [];
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $c = $data[$i];
            if ($c === 'z' && !$tuple) {
                $out .= "\0\0\0\0";
                continue;
            }
            $tuple[] = ord($c) - 33;
            if (count($tuple) === 5) {
                $v = 0;
                foreach ($tuple as $t) {
                    $v = $v * 85 + $t;
                }
                $out .= pack('N', $v);
                $tuple = [];
            }
        }
        if ($tuple) {
            $count = count($tuple);
            while (count($tuple) < 5) {
                $tuple[] = 84;
            }
            $v = 0;
            foreach ($tuple as $t) {
                $v = $v * 85 + $t;
            }
            $out .= substr(pack('N', $v), 0, $count - 1);
        }
        return $out;
    }

    private static function pngPredictor(string $data, int $columns): string
    {
        $rowLen = $columns + 1;
        $rows = str_split($data, $rowLen);
        $prev = str_repeat("\0", $columns);
        $out = '';
        foreach ($rows as $row) {
            if (strlen($row) < 2) {
                continue;
            }
            $type = ord($row[0]);
            $line = substr($row, 1);
            $decoded = '';
            for ($i = 0; $i < strlen($line); $i++) {
                $raw = ord($line[$i]);
                $left = $i > 0 ? ord($decoded[$i - 1]) : 0;
                $up = ord($prev[$i] ?? "\0");
                $val = match ($type) {
                    1 => $raw + $left,
                    2 => $raw + $up,
                    3 => $raw + (int) floor(($left + $up) / 2),
                    default => $raw,
                };
                $decoded .= chr($val & 255);
            }
            $out .= $decoded;
            $prev = $decoded;
        }
        return $out;
    }
}
