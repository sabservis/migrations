<?php declare(strict_types = 1);

/**
 * This file is part of the Nextras community extensions of Nette Framework
 *
 * @license    New BSD License
 * @link       https://github.com/nextras/migrations
 */

namespace Nextras\Migrations\Drivers;

use Nextras\Migrations\IDbal;
use Nextras\Migrations\IDriver;
use Nextras\Migrations\IOException;


/**
 * @author Jan Skrasek
 * @author Petr Prochazka
 * @author Jan Tvrdik
 */
abstract class BaseDriver implements IDriver
{
	/** @var IDbal */
	protected $dbal;

	/** @var string */
	protected $tableName;

	/** @var null|string */
	protected $tableNameQuoted;


	public function __construct(IDbal $dbal, string $tableName = 'migrations')
	{
		$this->dbal = $dbal;
		$this->tableName = $tableName;
	}


	public function setupConnection(): void
	{
		$this->tableNameQuoted = $this->dbal->escapeIdentifier($this->tableName);
	}


	/**
	 * Loads and executes SQL queries from given file. Taken from Adminer (Apache License), modified.
	 *
	 * @author   Jakub Vrána
	 * @author   Jan Tvrdík
	 * @author   Michael Moravec
	 * @author   Jan Skrasek
	 * @license  Apache License
	 */
	public function loadFile(string $path): int
	{
		$content = @file_get_contents($path);
		if ($content === false) {
			throw new IOException("Cannot open file '$path'.");
		}

		$queryOffset = 0;
		$parseOffset = 0;
		$queries = 0;

		$space = "(?:\\s|/\\*.*\\*/|(?:#|-- )[^\\n]*(?:\\n|\\z)|--(?:\\n|\\z))";
		$spacesRe = "~\\G{$space}*\\z~";
		$delimiter = ';';
		$delimiterRe = "~\\G{$space}*DELIMITER\\s+(\\S+)~i";

		$openRe = $this instanceof PgSqlDriver ? '[\'"]|/\*|-- |\z|\$[^$]*\$' : '[\'"`#]|/\*|-- |\z';
		$parseRe = "(;|$openRe)";
		$endReTable = [
			'\'' => '(\'|\\\\.|\z)s',
			'"' => '("|\\\\.|\z)s',
			'/*' => '(\*/|\z)',
			'[' => '(]|\z)',
		];

		while (true) {
			while (preg_match($delimiterRe, $content, $match, 0, $queryOffset)) {
				$delimiter = $match[1];
				$queryOffset += strlen($match[0]);
				$parseOffset += strlen($match[0]);
				$parseRe = '(' . preg_quote($delimiter) . "|$openRe)";
			}

			while (true) {
				preg_match($parseRe, $content, $match, PREG_OFFSET_CAPTURE, $parseOffset); // should always match
				$found = $match[0][0];
				$parseOffset = $match[0][1] + strlen($found);

				if ($found === $delimiter) { // delimited query
					$queryLength = $match[0][1] - $queryOffset;
					break;

				} elseif ($found) { // find matching quote or comment end
					$endRe = $endReTable[$found] ?? '(' . (preg_match('~^-- |^#~', $found) ? "\n" : preg_quote($found) . "|\\\\.") . '|\z)s';
					while (preg_match($endRe, $content, $match, PREG_OFFSET_CAPTURE, $parseOffset)) { //! respect sql_mode NO_BACKSLASH_ESCAPES
						$s = $match[0][0];
						if (strlen($s) === 0) {
							break 3;
						}

						$parseOffset = $match[0][1] + strlen($s);
						if ($s[0] !== '\\') {
							continue 2;
						}
					}

				} else { // last query or EOF
					if (preg_match($spacesRe, $content, $_, 0, $queryOffset)) {
						break 2;

					} else {
						$queryLength = $match[0][1] - $queryOffset;
						break;
					}
				}
			}

			$q = substr($content, $queryOffset, $queryLength);

			$queries++;
			foreach ($this->divideSqlQueries($q) as $subQuery) {
				$this->dbal->exec($subQuery);
			}

			$queryOffset = $parseOffset;
		}

		return $queries;
	}

    /**
     * Divide alter table query to more queries
     * @return string[]
     */
    private function divideSqlQueries(string $sql): array
    {
        $queries = explode(";", $sql);
        $alterColumnQueries = [];
        $foreignKeyQueries = [];
        $otherQueries = [];

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) continue;

            // Check if it is an ALTER TABLE statement
            if (stripos($query, "ALTER TABLE") !== false) {
                // Split by commas to check for multiple ADD commands in a single statement
                $subCommands = explode(",", $query);
                $tableName = "";

                foreach ($subCommands as $subCmd) {
                    $subCmd = trim($subCmd);

                    // Extract table name from "ALTER TABLE `table_name`"
                    if (stripos($subCmd, "ALTER TABLE") !== false) {
                        preg_match('/ALTER TABLE `?([a-zA-Z0-9_]+)`?/i', $subCmd, $matches);
                        $tableName = $matches[1] ?? "";
                    }

                    if (stripos($subCmd, "ADD FOREIGN KEY") !== false) {
                        $foreignKeyQueries[] = self::prepareAlterTableQuery($subCmd, $tableName);
                    } else {
                        $alterColumnQueries[] = self::prepareAlterTableQuery($subCmd, $tableName);
                    }
                }
            } else {
                // Normal queries (CREATE, INSERT, etc.)
                $otherQueries[] = $query . ";";
            }
        }


        return array_merge($otherQueries, $alterColumnQueries, $foreignKeyQueries);
    }


    private function prepareAlterTableQuery(string $subCmd, $tableName): string
    {
        if (strpos($subCmd, "ALTER TABLE") === 0) {
            return $subCmd;
        }

        return "ALTER TABLE `$tableName` " . $subCmd . ";";
    }
}
