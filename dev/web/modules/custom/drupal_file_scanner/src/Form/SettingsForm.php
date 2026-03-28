<?php

declare(strict_types=1);

namespace Drupal\drupal_file_scanner\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Drupal File Scanner.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_file_scanner.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_file_scanner_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupal_file_scanner.settings');

    $form['scan_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Absolute folder path to scan'),
      '#description' => $this->t('Example: /var/data/import. The folder is scanned recursively.'),
      '#required' => TRUE,
      '#default_value' => $config->get('scan_path') ?? '',
    ];

    $form['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed extensions'),
      '#description' => $this->t('Space-separated list.'),
      '#required' => TRUE,
      '#default_value' => $config->get('allowed_extensions') ?? 'jpg jpeg doc docx xlsx xls png pdf csv txt',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $path = (string) $form_state->getValue('scan_path');
    if (!is_dir($path)) {
      $form_state->setErrorByName('scan_path', $this->t('The path %path does not exist or is not a directory.', ['%path' => $path]));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('drupal_file_scanner.settings')
      ->set('scan_path', rtrim((string) $form_state->getValue('scan_path'), '/'))
      ->set('allowed_extensions', (string) $form_state->getValue('allowed_extensions'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
