<?php

declare(strict_types = 1);

namespace Nextras\Migrations\Extensions;

use Nextras\Migrations\Entities\File;
use Nextras\Migrations\IExtensionHandler;
use Nextras\Migrations\Importer\MySqlImporter;
use mysqli;

class SabSqlHandler implements IExtensionHandler
{
	private MySqlImporter $mySqlImporter;

	public function __construct(mysqli $mysqli)
	{
		$this->mySqlImporter = new MySqlImporter($mysqli);
	}

	public function execute(File $file): int
	{
		$this->mySqlImporter->doImport($file->path);

		return 1;
	}
}
