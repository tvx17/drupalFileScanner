<?php

declare(strict_types=1);

namespace Drupal\file_scanner\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file_scanner\Service\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class FileScannerSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileScanner $scanner,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('file_scanner.scanner'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['file_scanner.settings'];
  }

  public function getFormId(): string {
    return 'file_scanner_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('file_scanner.settings');

    $form['scan_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory to scan'),
      '#description' => $this->t('Use a Drupal stream-wrapper URI, e.g. public://documents.'),
      '#default_value' => $config->get('scan_directory') ?? 'public://',
      '#required' => TRUE,
    ];

    $form['extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#description' => $this->t('Comma-separated, e.g. jpg,jpeg,png,txt,csv,xlsx,xls,docx,doc'),
      '#default_value' => implode(',', (array) ($config->get('extensions') ?? [])),
      '#required' => TRUE,
    ];

    $mediaTypes = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $options = [];
    foreach ($mediaTypes as $id => $mediaType) {
      $options[$id] = $mediaType->label();
    }

    $form['media_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Media bundle'),
      '#description' => $this->t('Media type used to create entities for found files.'),
      '#options' => $options,
      '#default_value' => $config->get('media_bundle') ?? '',
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $directory = trim((string) $form_state->getValue('scan_directory'));
    if (!str_contains($directory, '://')) {
      $form_state->setErrorByName('scan_directory', $this->t('Please provide a stream-wrapper URI like public://folder.'));
    }

    if ($this->parseExtensions((string) $form_state->getValue('extensions')) === []) {
      $form_state->setErrorByName('extensions', $this->t('Please define at least one extension.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $extensions = $this->parseExtensions((string) $form_state->getValue('extensions'));

    $this->configFactory->getEditable('file_scanner.settings')
      ->set('scan_directory', trim((string) $form_state->getValue('scan_directory')))
      ->set('extensions', $extensions)
      ->set('media_bundle', (string) $form_state->getValue('media_bundle'))
      ->save();

    $stats = $this->scanner->scan();
    $this->messenger()->addStatus($this->t('Scan complete. Discovered: @d, created: @c, existing skipped: @e, unsupported: @u, errors: @r.', [
      '@d' => (string) $stats['discovered'],
      '@c' => (string) $stats['created'],
      '@e' => (string) $stats['existing'],
      '@u' => (string) $stats['unsupported'],
      '@r' => (string) $stats['errors'],
    ]));

    parent::submitForm($form, $form_state);
  }

  /**
   * @return string[]
   */
  private function parseExtensions(string $raw): array {
    $parts = array_map(
      static fn (string $ext): string => strtolower(trim($ext, " \t\n\r\0\x0B.")),
      explode(',', $raw)
    );

    return array_values(array_unique(array_filter($parts)));
  }

}
