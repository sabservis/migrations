<?php

declare(strict_types=1);

namespace Nextras\Migrations\Fixer;

use Nette\Utils\Finder;

final class MySqlFixer
{

    const PATTERN = '/ALTER TABLE\s+`?([\w\d_]+)`?\s+((?:ADD\s+`?[\w\d_]+`?\s+[^,]+,\s*)+)\s*(ADD FOREIGN KEY\s*\([^)]+\)\s+REFERENCES\s+`?[\w\d_]+`?\s*\([^)]+\)[^;]*);/i';

    public function check(string $filePath): bool
    {
        $sql = $this->getSql($filePath);

        $result = preg_match(self::PATTERN, $sql);

        return $result === false || $result === 0;
    }

    /**
     * @return array<\SplFileInfo>|bool - return true if check is O else return files with error
     */
    public function checkInFolder(string $folder): array|bool
    {
        $errorFiles = [];

        foreach (Finder::findFiles('*.sql')->from($folder) as $name => $file) {
            if ($this->check($file->getRealPath()) === false) {
                $errorFiles[] = $file;
            }
        }

        return count($errorFiles)  === 0 ? true : $errorFiles;
    }

    /**
     * @return array<\SplFileInfo>
     */
    public function fixInFolder(string $folder): array
    {
        $fixedFiles = [];

        foreach (Finder::findFiles('*.sql')->from($folder) as $name => $file) {
            if ($this->check($file->getRealPath()) === false) {
                $this->fix($file->getRealPath());
            }
        }

        return $fixedFiles;
    }

    public function fix(string $filePath): void
    {
        if ($this->check($filePath) === true) {
            return;
        }

        $sql = $this->getSql($filePath);

        // Replace callback to split the ALTER TABLE statements
        $sql = preg_replace_callback(self::PATTERN, function ($matches) {
            $tableName = $matches[1];  // Table name
            $addColumns = trim($matches[2]);  // Column definitions
            $addForeignKey = trim($matches[3]); // Foreign key constraint

            // Ensure the column statement ends with a semicolon
            if (substr($addColumns, -1) !== ';') {
                if (substr($addColumns, -1) === ',') {
                    $addColumns = substr($addColumns, 0, -1);
                }
                $addColumns .= ';';
            }

            // Construct two separate ALTER TABLE statements
            return "ALTER TABLE `$tableName` $addColumns\nALTER TABLE `$tableName` $addForeignKey;";
        }, $sql);

        // Write the modified SQL back to the file
        file_put_contents($filePath, $sql);

        if ($this->check($filePath) === false) {
            $this->fix($filePath);
        }
    }

    private function getSql(string $filePath): string
    {
        if (file_exists($filePath) === false) {
            throw new \Exception(sprintf('File "%s" does not exist.', $filePath));
        }

        return file_get_contents($filePath);
    }
}
