<?php

namespace PGNChess\PGN\File;

use PGNChess\Db\Pdo;
use PGNChess\Exception\PgnFileSyntaxException;
use PGNChess\PGN\Tag;
use PGNChess\PGN\Movetext;
use PGNChess\PGN\Validate as PgnValidate;
use PGNChess\PGN\File\Validate as PgnFileValidate;

class Seed extends AbstractFile
{
    private $result = [];

    public function __construct(string $filepath)
    {
        parent::__construct($filepath);

        $this->result = (object) [
            'valid' => 0,
            'errors' => []
        ];
    }

    public function db(): \stdClass
    {
        $tags = [];
        $movetext = '';
        if ($file = fopen($this->filepath, 'r')) {
            while (!feof($file)) {
                $line = preg_replace('~[[:cntrl:]]~', '', fgets($file));
                try {
                    $tag = PgnValidate::tag($line);
                    $tags[$tag->name] = $tag->value;
                } catch (\Exception $e) {
                    if (!Tag::isStr($tags) && $this->line->startsMovetext($line)) {
                        $this->result->errors[] = ['tags' => array_filter($tags)];
                        Tag::reset($tags);
                        $movetext = '';
                    } elseif (Tag::isStr($tags) && (($this->line->isMovetext($line) || $this->line->endsMovetext($line)))) {
                        $movetext = Movetext::init("$movetext $line")->filter();
                        if (!PgnValidate::movetext($movetext)) {
                            $this->result->errors[] = [
                                'tags' => array_filter($tags),
                                'movetext' => $movetext
                            ];
                        } else {
                            try {
                                $preparedTags = [];
                                Tag::reset($preparedTags);
                                Pdo::getInstance()->query(
                                    $this->sql(),
                                    $this->values(array_replace($preparedTags, $tags), $movetext)
                                );
                                $this->result->valid += 1;
                            } catch (\Exception $e) {
                                $this->result->errors[] = [
                                    'tags' => array_filter($tags),
                                    'movetext' => $movetext
                                ];
                            }
                        }
                        Tag::reset($tags);
                        $movetext = '';
                    } elseif (Tag::isStr($tags)) {
                        $movetext .= ' ' . $line;
                    }
                }
            }
            fclose($file);
        }

        if (empty($this->result->errors)) {
            unset($this->result->errors);
        }

        return $this->result;
    }

    protected function sql(): string
    {
        $sql = 'INSERT INTO games (';

        foreach (Tag::getConstants() as $key => $value) {
            $sql .= "$value, ";
        }

        $sql .= 'movetext) VALUES (';

        foreach (Tag::getConstants() as $key => $value) {
            $sql .= ":$value, ";
        }

        $sql .= ':movetext)';

        return $sql;
    }

    protected function values(array $tags, string $movetext): array
    {
        $values = [];

        foreach ($tags as $key => $value) {
            $values[] = [
                'parameter' => ":$key",
                'value' => $value,
                'type' => \PDO::PARAM_STR
            ];
        }

        $values[] = [
            'parameter' => ':movetext',
            'value' => trim($movetext),
            'type' => \PDO::PARAM_STR
        ];

        return $values;
    }
}
