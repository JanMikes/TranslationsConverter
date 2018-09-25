<?php

namespace Apploud\TranslationsConverter\Commands;

use Apploud\TranslationsConverter\Exceptions\TranslationExportException;
use Nette\Neon\Neon;
use Nette\Utils\Finder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tracy\Debugger;

class ExportTranslationsCommand extends Command
{

	/** @var string */
	protected $langDir;

	public function __construct($langDir)
	{
		parent::__construct();
		$this->langDir = $langDir;
	}

	protected function configure()
	{
		$this->setName('translations:export')
			->setDescription('Exports translations from NEON files to XLSX file.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if (!is_dir($this->langDir)) {
			$output->writeLn("<error>\n\n\tThere is no lang directory inside appDir!\n</error>\n");
			return 1;
		}
		$files = [];
		foreach (Finder::findFiles('*.*.neon')->from($this->langDir) as $file) {
			/** @var \SplFileInfo $file */
			if (!preg_match('/^([^.]+).([^.]+).neon$/', $file->getFilename(), $matches) || count($matches) !== 3) {
				$output->writeLn("<error>\n\n\tLang files must be named <name>.<lang>.neon and name nor lang cannot contain a dot!\n</error>\n");
				return 1;
			}
			$files[$file->getPathname()] = [
				'prefix' => $matches[1],
				'lang' => $matches[2]
			];
		}
		if (!$files) {
			$output->writeLn("<error>\n\n\tThere are no lang files!\n</error>\n");
			return 1;
		}
		try {
			$this->generateXlsx($files, $this->langDir . '/translations.xlsx');
			$output->writeLn("<info>XLSX file generated successfully</info>");
		} catch (TranslationExportException $e) {
			$output->writeLn("<error>\n\n\t" . $e->getMessage() . "\n</error>\n");
			Debugger::log($e);
			return 1;
		}
		return 0;
	}

	private function generateXlsx(array $files, $destination)
	{
		$identifiers = [];
		$langs = [];
		$translations = [];
		foreach ($files as $file => $options) {
			$fileContent = Neon::decode(file_get_contents($file));
			if ($fileContent === NULL) {
				continue;
			}
			$contents = $this->flattenArray($fileContent, $options['prefix']);
			$identifiers = array_unique(array_merge($identifiers, array_keys($contents)));
			$langs[$options['lang']] = $options['lang'];
			if (array_key_exists($options['lang'], $translations)) {
				$translations[$options['lang']] = array_merge($translations[$options['lang']], $contents);
			} else {
				$translations[$options['lang']] = $contents;
			}
		}
		if (!$translations) {
			throw new TranslationExportException('There are no translations inside lang files!');
		}
		$langs = array_values($langs);

		$excel = new \PHPExcel();
		$excel->setActiveSheetIndex(0);
		$sheet = $excel->getActiveSheet();

		$column = 'A';
		$sheet->getColumnDimension($column)->setAutoSize(true);
		$sheet->setCellValue("{$column}1", 'identifier');
		foreach ($langs as $lang) {
			$column++;
			$sheet->getColumnDimension($column)->setAutoSize(true);
			$sheet->setCellValue("{$column}1", $lang);
		}

		$row = 2;
		foreach ($identifiers as $identifier) {
			$col = 'A';
			$sheet->setCellValue($col++ . $row, $identifier);
			foreach ($langs as $lang) {
				$translation = array_key_exists($identifier, $translations[$lang]) ? (string)$translations[$lang][$identifier] : '';
				$sheet->setCellValue($col++ . $row, $translation);
			}
			$row++;
		}

		$writer = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
		$writer->save($destination);
	}

	private function flattenArray(array $array, $prefix) {
		$return = [];
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$return = array_merge($return, self::flattenArray($value, $prefix . '.' . $key));
			} else {
				$return[$prefix . '.' . $key] = $value;
			}
		}
		return $return;
	}

}
