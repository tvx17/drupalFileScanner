<?php

declare(strict_types=1);

namespace Drupal\drupal_file_scanner\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans a directory and creates media entities for files.
 */
final class FileScanner {

  /**
   * Constructs the file scanner.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns scanner configuration.
   *
   * @return array{scan_path: string, allowed_extensions: string}
   *   Config values.
   */
  public function getConfiguration(): array {
    $config = $this->configFactory->get('drupal_file_scanner.settings');

    return [
      'scan_path' => (string) ($config->get('scan_path') ?: ''),
      'allowed_extensions' => (string) ($config->get('allowed_extensions') ?: ''),
    ];
  }

  /**
   * Runs recursive scan and media creation.
   *
   * @return array{added: int, existing: int, errors: string[]}
   *   Scan summary.
   */
  public function scan(): array {
    $configuration = $this->getConfiguration();
    $scanPath = rtrim($configuration['scan_path'], '/');

    if ($scanPath === '' || !is_dir($scanPath)) {
      return [
        'added' => 0,
        'existing' => 0,
        'errors' => ['Configured scan path is missing or invalid.'],
      ];
    }

    $allowedExtensions = array_filter(array_map('strtolower', preg_split('/\s+/', trim($configuration['allowed_extensions'])) ?: []));

    if ($allowedExtensions === []) {
      return [
        'added' => 0,
        'existing' => 0,
        'errors' => ['No allowed file extensions configured.'],
      ];
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $fileStorage = $this->entityTypeManager->getStorage('file');

    $added = 0;
    $existing = 0;

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($scanPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
      if (!$item->isFile()) {
        continue;
      }

      $pathName = $item->getPathname();
      $extension = strtolower(pathinfo($pathName, PATHINFO_EXTENSION));
      if (!in_array($extension, $allowedExtensions, TRUE)) {
        continue;
      }

      $normalizedRealPath = (string) realpath($pathName);
      if ($normalizedRealPath === '') {
        continue;
      }

      $existingMedia = $mediaStorage->loadByProperties([
        'bundle' => 'scanned_file',
        'field_scan_source_path' => $normalizedRealPath,
      ]);
      if ($existingMedia !== []) {
        $existing++;
        continue;
      }

      $relativePath = ltrim(substr($normalizedRealPath, strlen($scanPath)), '/');
      $uri = sprintf('scanfiles://%s', str_replace('\\', '/', $relativePath));

      $files = $fileStorage->loadByProperties(['uri' => $uri]);
      $file = $files ? reset($files) : NULL;
      if ($file === NULL) {
        $file = File::create([
          'uri' => $uri,
          'status' => 1,
        ]);
        $file->setPermanent();
        $file->save();
      }

      $media = $mediaStorage->create([
        'bundle' => 'scanned_file',
        'name' => basename($normalizedRealPath),
        'status' => 1,
        'field_media_file' => [
          'target_id' => $file->id(),
        ],
        'field_scan_source_path' => $normalizedRealPath,
      ]);

      try {
        $media->save();
        $added++;
      }
      catch (\Throwable $exception) {
        $this->logger->error('Failed to import file @file: @message', [
          '@file' => $normalizedRealPath,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    return [
      'added' => $added,
      'existing' => $existing,
      'errors' => [],
    ];
  }

}
