<?php

namespace Drupal\webform_campaignmonitor_integration\Plugin\WebformHandler;

/**
 * @file
 * WebformCampaignMonitorHandler.php
 */

use Drupal\campaignmonitor\CampaignMonitor;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form submission to Campaign Monitor handler.
 *
 * @WebformHandler(
 *   id = "campaignmonitor",
 *   label = @Translation("Campaign Monitor"),
 *   category = @Translation("Campaign Monitor"),
 *   description = @Translation("Sends a form submission to a Campaign Monitor list."),
 *   cardinality = \Drupal\webform\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class WebformCampaignMonitorHandler extends WebformHandlerBase {

  /**
   * Campaign Monitor Connector.
   *
   * @var CampaignMonitor
   */
  protected $campaignMonitor;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
    $this->campaignMonitor = CampaignMonitor::getConnector();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('webform.campaignmonitor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $lists = $this->campaignMonitor->getLists();
    $selectedList = '';
    if (!empty($this->configuration['list'])) {
      $selectedList = $lists[$this->configuration['list']]['name'];
    }
    return [
      '#theme' => 'markup',
      '#markup' => $this->t('<strong>List: </strong> !list_name', ['!list_name' => $selectedList]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'list' => '',
      'email' => '',
      'fullname' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $lists = $this->campaignMonitor->getLists();

    $options = array();
    $options[''] = $this->t('- Select a list -');
    foreach ($lists as $list_id => $list) {
      $options[$list_id] = $list['name'];
    }

    $form['list'] = [
      '#type' => 'select',
      '#title' => $this->t('List'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['list'],
      '#options' => $options,
    ];

    $fields = $this->getWebform()->getElementsFlattenedAndHasValue();
    $options = array();
    $options[''] = $this->t('- Select an email field -');
    foreach ($fields as $field_name => $field) {
      if ($field['#type'] == 'email') {
        $options[$field_name] = $field['#title'];
      }
    }

    $form['email'] = [
      '#type' => 'select',
      '#title' => $this->t('Email field'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['email'],
      '#options' => $options,
    ];

    $options = array();
    $options[''] = $this->t('- Select a text field -');
    foreach ($fields as $field_name => $field) {
      if ($field['#type'] == 'textfield' || $field['#type'] == 'value') {
        $options[$field_name] = $field['#title'] ?: '[' . $field_name . ']';
      }
    }

    $form['fullname'] = [
      '#type' => 'select',
      '#title' => $this->t('Full name field'),
      '#required' => FALSE,
      '#default_value' => $this->configuration['fullname'],
      '#options' => $options,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValues();
    foreach ($this->configuration as $name => $value) {
      if (isset($values[$name])) {
        $this->configuration[$name] = $values[$name];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    if (!$update) {
      $email_field = $this->configuration['email'];
      $fullname_field = !empty($this->configuration['fullname']) ? $this->configuration['fullname'] : '';
      $fields = $webform_submission->toArray(TRUE);
      $sendFields = array();
      foreach ($fields['data'] as $field_name => $field_value) {
        $sendFields[] = (object) [
          'Key' => $field_name,
          'Value' => $field_value,
        ];
      }
      $email = $fields['data'][$email_field];
      $fullname = $fullname_field ? $fields['data'][$fullname_field] : '';
      if (!$this->campaignMonitor->subscribe($this->configuration['list'], $email, $fullname, $sendFields)) {
        $errors = $this->campaignMonitor->getErrors();
        foreach ($errors as $error) {
          /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $message */
          $message = $error['message'];
          drupal_set_message($message->render(), 'error');
        }
      }
    }
  }

}
