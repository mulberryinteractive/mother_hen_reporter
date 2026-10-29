<?php

declare(strict_types=1);

namespace Drupal\mother_hen_reporter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mother_hen_reporter\Reporter;
use Drupal\mother_hen_reporter\ReporterSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures where and how this site reports to Mother Hen.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The reporter settings.
   */
  private ReporterSettings $reporterSettings;

  /**
   * The reporter.
   */
  private Reporter $reporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->reporterSettings = $container->get(ReporterSettings::class);
    $form->reporter = $container->get(Reporter::class);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mother_hen_reporter_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mother_hen_reporter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mother_hen_reporter.settings');

    $messages = [];
    if ($this->reporterSettings->hasLegacySettings()) {
      $messages['warning'][] = $this->t('settings.php still contains the old mother_hen_reporter block, which is ignored since version 2.0. Remove it once this form is configured.');
    }
    if ($warning = $this->reporterSettings->keyStorageWarning()) {
      $messages['warning'][] = $warning;
    }
    $messages['status'][] = $this->reporterSettings->disabledReason() ?? $this->t('This environment reports as "@environment".', [
      '@environment' => $this->reporterSettings->environment(),
    ]);
    $form['messages'] = [
      '#theme' => 'status_messages',
      '#message_list' => $messages,
      '#status_headings' => [
        'status' => $this->t('Status'),
        'warning' => $this->t('Warning'),
      ],
    ];

    $form['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Dashboard endpoint'),
      '#description' => $this->t("The dashboard's report address, ending in /api/mother-hen/inventory."),
      '#default_value' => $config->get('endpoint'),
    ];
    $form['site_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site ID'),
      '#description' => $this->t("From the Site ID column of the dashboard's Sites list."),
      '#default_value' => $config->get('site_id'),
      '#maxlength' => 36,
    ];
    $form['private_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Private key'),
      '#description' => $this->t("Create it with <code>drush mother-hen:keygen</code>, and store it with the Key module's Environment provider so the secret stays out of configuration and the database. Where the key has no value (staging, local copies), nothing is sent."),
      '#empty_option' => $this->t('- Select a key -'),
      '#default_value' => $config->get('private_key'),
    ];
    if ($public_key = $this->reporterSettings->publicKey()) {
      $form['public_key'] = [
        '#type' => 'item',
        '#title' => $this->t('Public key'),
        '#description' => $this->t('Paste this into the site on the dashboard.'),
        '#markup' => '<code>' . $public_key . '</code>',
      ];
    }
    $form['environment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environment name'),
      '#description' => $this->t('Leave empty to use the Upsun/Platform.sh branch name, otherwise "production". The dashboard keeps reports only from environments it monitors.'),
      '#default_value' => $config->get('environment'),
    ];
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development'),
    ];
    $form['development']['allow_insecure_endpoint'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow a plain HTTP endpoint'),
      '#description' => $this->t('Only for testing against a local dashboard.'),
      '#default_value' => $config->get('allow_insecure_endpoint'),
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['report'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send a report now'),
      '#submit' => ['::reportNow'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $site_id = trim((string) $form_state->getValue('site_id'));
    if ($site_id !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $site_id)) {
      $form_state->setErrorByName('site_id', $this->t('The site ID is the UUID shown on the dashboard, e.g. 6f1c2b9e-3d4a-4c5b-8e7f-0a1b2c3d4e5f.'));
    }
    $endpoint = trim((string) $form_state->getValue('endpoint'));
    if ($endpoint !== '' && !str_starts_with($endpoint, 'https://') && !$form_state->getValue('allow_insecure_endpoint')) {
      $form_state->setErrorByName('endpoint', $this->t('The endpoint must use HTTPS.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('mother_hen_reporter.settings')
      ->set('endpoint', trim((string) $form_state->getValue('endpoint')))
      ->set('site_id', trim((string) $form_state->getValue('site_id')))
      ->set('private_key', (string) $form_state->getValue('private_key'))
      ->set('environment', trim((string) $form_state->getValue('environment')))
      ->set('allow_insecure_endpoint', (bool) $form_state->getValue('allow_insecure_endpoint'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Sends a report using the saved settings.
   */
  public function reportNow(array &$form, FormStateInterface $form_state): void {
    $result = $this->reporter->report();
    if ($result->success) {
      $this->messenger()->addStatus($this->t('Report accepted by the dashboard.'));
    }
    else {
      $this->messenger()->addError($this->t('Report not accepted: @message', ['@message' => $result->message]));
    }
  }

}
