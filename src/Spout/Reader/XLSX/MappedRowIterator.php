<?php

namespace Box\Spout\Reader\XLSX;

use Box\Spout\Reader\IteratorInterface;

class MappedRowIterator implements IteratorInterface
{

    protected static array $camelCache = [];
    protected static array $studlyCache = [];

    public function __construct(protected RowIterator $iterator) {
        $this->iterator->rewind();
        $this->headers = $this->iterator->current()->toArray();
        $this->next();
    }
    public function rewind() : void
    {
        $this->iterator->rewind();
        $this->next();
    }

    protected array|null $headers = null;
    protected array|null $map = null;

    protected function sanitize(string|int|null $value) : string|int|null
    {
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace("–", "-", $value);
        $search = [
            chr(145),
            chr(146),
            chr(147),
            chr(148),
            chr(150),
            chr(151),
            '°'
        ];
        $spaceSearch = [
            chr(160)
        ];
        $replace = array("'","'",'"','"','-', '-', '');
        return mb_convert_encoding(preg_replace(
            '/[^\r\n\t\x20-\x7E\xA0-\xFF]/u',
            '',
            str_replace($search, $replace, str_replace($spaceSearch, ' ', $value))
        ), 'UTF-8', 'UTF-8');
    }
    public static function upper($value)
    {
        return mb_strtoupper($value, 'UTF-8');
    }
    public static function ucfirst($string)
    {
        return static::upper(static::substr($string, 0, 1)).static::substr($string, 1);
    }
    public static function substr($string, $start, $length = null, $encoding = 'UTF-8')
    {
        return mb_substr($string, $start, $length, $encoding);
    }

    public static function studly($value)
    {
        $key = $value;
        if (isset(static::$studlyCache[$key])) {
            return static::$studlyCache[$key];
        }
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));
        $studlyWords = array_map(fn ($word) => static::ucfirst(strtolower($word)), $words);
        return static::$studlyCache[$key] = implode($studlyWords);
    }

    protected static function camel(string $value) : string
    {
        if (isset(static::$camelCache[$value])) {
            return static::$camelCache[$value];
        }

        return static::$camelCache[$value] = lcfirst(static::studly($value));
    }

    protected function assertMappable() : void
    {
        if (!is_null($this->map)) {
            throw new \Exception('CSV Map already set');
        }
        if (is_null($this->headers)) {
            throw new \Exception('No Header row present');
        }
    }

    protected function getComparisonHeaders(bool $caseSensitive = false) : array
    {
        return $caseSensitive ? $this->headers : array_map('strtolower', $this->headers);
    }

    protected function getColIndex(array $possibleCols, array $comparisonHeaders) : int
    {
        foreach ($possibleCols as $col) {
            $index = array_search($col, $comparisonHeaders);
            if ($index !== false) {
                return $index;
            }
        }
        throw new \Exception('None of the expected column options were found: ' . implode(', ', $possibleCols) . ' - headers: ' . implode(', ', $comparisonHeaders));
    }

    public function setMap(array $map, bool $caseSensitive = false) : static
    {
        $this->assertMappable();
        $headersToCompare = $this->getComparisonHeaders($caseSensitive);
        foreach ($map as $label => $possibleCols) {
            if (!is_array($possibleCols)) {
                $possibleCols = [$possibleCols];
            }
            if ($caseSensitive) {
                $possibleCols = array_map('strtolower', $possibleCols);
            }
            $this->map[$this->getColIndex($possibleCols, $headersToCompare)] = $label;
        }
        return $this;
    }
    public function current(): array|\stdClass
    {
        $row = $this->iterator->current();
        $row = $this->mapRow($row->toArray());
        $sanitized = array_map(fn(string|int|null $value) => $this->sanitize($value), $row);
        return (object)$sanitized;
    }

    public function mapRow(array $row) : array
    {
        $output = [];
        if (is_null($this->map)) {
            foreach ($row as $index => $value) {
                $output[self::camel($this->headers[$index])] = $value;
            }
            return $output;
        }
        foreach ($this->map as $index => $property) {
            $output[$property ?? self::camel($this->headers[$index])] = $row[$index];
        }
        return $output;
    }


    public function next(): void
    {
        $this->iterator->next();
    }

    public function key(): int
    {
        return $this->iterator->key();
    }

    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    public function end() : void
    {
        $this->iterator->end();
    }
}
