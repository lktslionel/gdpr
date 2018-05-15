<?php

namespace Drupal\gdpr_dump\Form;

use Drupal\anonymizer\Anonymizer\AnonymizerPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gdpr_dump\Service\GdprDatabaseManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SettingsForm.
 *
 * @package Drupal\gdpr_dump\Form
 */
class SettingsForm extends ConfigFormBase {

  const GDPR_DUMP_CONF_KEY = 'gdpr_dump.table_map';
  const GDPR_DUMP_NO_PLUGIN_KEY = 'none';

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Database manager.
   *
   * @var \Drupal\gdpr_dump\Service\GdprDatabaseManager
   */
  protected $databaseManager;

  /**
   * The plugin manager for anonymizers.
   *
   * @var \Drupal\anonymizer\Anonymizer\AnonymizerPluginManager
   */
  protected $pluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('database'),
      $container->get('plugin.manager.anonymizer'),
      $container->get('gdpr_dump.database_manager')
    );
  }

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\anonymizer\Anonymizer\AnonymizerPluginManager $pluginManager
   *   The plugin manager for anonymizers.
   * @param \Drupal\gdpr_dump\Service\GdprDatabaseManager $gdprDatabaseManager
   *   Database manager service.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    AnonymizerPluginManager $pluginManager,
    GdprDatabaseManager $gdprDatabaseManager
  ) {
    parent::__construct($configFactory);
    $this->database = $database;
    $this->pluginManager = $pluginManager;
    $this->databaseManager = $gdprDatabaseManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::GDPR_DUMP_CONF_KEY,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gdpr_dump_settings_form';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Database\InvalidQueryException
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#markup' => $this->t('Check the checkboxes for each table columns containing sensitive data!'),
    ];

    $form['tables'] = [
      '#type' => 'container',
    ];

    $plugins = [];
    foreach ($this->pluginManager->getDefinitions() as $definition) {
      $plugins[$definition['id']] = $definition['label'];
    }

    /* @todo:
     * Maybe divide by type (int, varchar, etc) and
     * display only appropriate ones for the actual selects.
     */
    /* @todo
     * UX
     */
    $anonymizationOptions = [
      '#type' => 'select',
      '#title' => $this->t('Apply anonymization'),
      '#options' => $plugins,
      '#empty_value' => self::GDPR_DUMP_NO_PLUGIN_KEY,
      '#empty_option' => $this->t('- No -'),
      '#title_display' => 'invisible',
    ];

    $config = $this->config(self::GDPR_DUMP_CONF_KEY);
    $mapping = $config->get('mapping');
    $emptyTables = $config->get('empty_tables');
    $table_header = [
      $this->t('Field'),
      $this->t('Type'),
      $this->t('Description'),
      $this->t('Apply anonymization'),
    ];

    /** @var array $columns */
    foreach ($this->databaseManager->getTableColumns() as $table => $columns) {
      $rows = [];
      foreach ($columns as $column) {
        $currentOptions = $anonymizationOptions;
        if (isset($mapping[$table][$column['COLUMN_NAME']])) {
          $currentOptions['#default_value'] = $mapping[$table][$column['COLUMN_NAME']];
        }

        $rows[$column['COLUMN_NAME']] = [
          'name' => [
            '#markup' => '<strong>' . $column['COLUMN_NAME'] . '</strong>',
          ],
          'type' => [
            '#markup' => '<strong>' . $column['DATA_TYPE'] . '</strong>',
          ],
          'description' => [
            '#markup' => '<strong>' . (empty($column['COLUMN_COMMENT']) ? '-' : $column['COLUMN_COMMENT']) . '</strong>',
          ],
          'option' => $currentOptions,
        ];
      }

      $form['tables'][$table] = [
        '#type' => 'details',
        '#title' => $this->t('Table: %table', ['%table' => $table]),
        'empty_table' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Empty this table'),
          '#default_value' => isset($emptyTables[$table]) ? $emptyTables[$table] : NULL,
          '#weight' => 1,
        ],
        'columns' => [
          '#type' => 'table',
          '#header' => $table_header,
          '#weight' => 0,
        ] + $rows,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Config\ConfigValueException
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasValue('tables')) {
      $mapping = [];
      /** @var array $tables */
      $tables = $form_state->getValue('tables', []);
      $emptyTables = [];
      foreach ($tables as $table => $row) {
        if ($row['empty_table']) {
          $emptyTables[$table] = 1;
        }
        foreach ($row['columns'] as $name => $data) {
          if (!empty($data['option']) && $data['option'] !== self::GDPR_DUMP_NO_PLUGIN_KEY) {
            $mapping[$table][$name] = $data['option'];
          }
        }
      }

      $config = $this->configFactory->getEditable(self::GDPR_DUMP_CONF_KEY);
      $config
        ->set('mapping', $mapping)
        ->save();

      $config
        ->set('empty_tables', $emptyTables)
        ->save();
    }

    parent::submitForm($form, $form_state);
  }

}