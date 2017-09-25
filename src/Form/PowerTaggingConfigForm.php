<?php

/**
 * @file
 * Contains \Drupal\powertagging\Form\PowerTaggingConfigForm.
 */

namespace Drupal\powertagging\Form;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\semantic_connector\Entity\SemanticConnectorPPServerConnection;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Class PowerTaggingConfigForm.
 *
 * @package Drupal\powertagging\Form
 */
class PowerTaggingConfigForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = $this->entity;
    $config = $powertagging_config->getConfig();

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => t('Title'),
      '#description' => t('Name of the PowerTagging configuration.'),
      '#size' => 35,
      '#maxlength' => 255,
      '#default_value' => $powertagging_config->getTitle(),
      '#required' => TRUE,
    ];

    // Add information about the connection.
    $form['pp_connection_markup'] = [
      '#markup' => $this->getConnectionInfo(),
    ];

    // Define the container for the vertical tabs.
    $form['settings'] = [
      '#type' => 'vertical_tabs',
    ];

    // Tab: Project settings.
    $form['project_settings'] = [
      '#type' => 'details',
      '#title' => t('Project settings'),
      '#group' => 'settings',
      '#tree' => TRUE,
    ];

    $connection = $powertagging_config->getConnection();

    // Get the connected project.
    $projects = $connection->getApi('PPX')->getProjects();
    $project = NULL;
    if (!empty($projects)) {
      foreach ($projects as $project) {
        if ($project['uuid'] == $powertagging_config->getProjectId()) {
          break;
        }
      }
    }

    if (!is_null($project)) {
      $form['project_settings']['title'] = [
        '#type' => 'item',
        '#title' => t('Project name'),
        '#description' => $project['label'],
      ];

      // Language mapping.
      $project_language_options = array();
      foreach ($project['languages'] as $project_language) {
        $project_language_options[$project_language] = $project_language;
      }
      $form['project_settings']['languages'] = [
        '#type' => 'fieldset',
        '#title' => t('Map the Drupal languages with the PoolParty project languages')
      ];
      $states = [];
      // Go through the defined languages.
      foreach (\Drupal::languageManager()->getLanguages() as $language) {
        $form['project_settings']['languages'][$language->getId()] = [
          '#type' => 'select',
          '#title' => t('Drupal language: %language (@id)', ['%language' => $language->getName(), '@id' => $language->getId()]),
          '#description' => t('Select the PoolParty project language'),
          '#options' => $project_language_options,
          '#empty_option' => '',
          '#default_value' => !empty($config['project']['languages'][$language->getId()]) ? $config['project']['languages'][$language->getId()] : '',
        ];
        $states['#edit-project-settings-languages-' . $language->getId()] = ['value' => ''];
      }
      // Go through all locked languages ("Not specified" and "Not abblicable".
      foreach (\Drupal::languageManager()->getDefaultLockedLanguages() as $language) {
        $form['project_settings']['languages'][$language->getId()] = [
          '#type' => 'select',
          '#title' => t('Drupal language: %language', ['%language' => $language->getName()]),
          '#description' => t('Select the PoolParty project language'),
          '#options' => $project_language_options,
          '#empty_option' => '',
          '#default_value' => !empty($config['project']['languages'][$language->getId()]) ? $config['project']['languages'][$language->getId()] : '',
        ];
      }

      // Vocabulary selection.
      // Hidden field for the selecting the vocabulary.
      // It checks the availability of a language.
      $form['project_settings']['no_language_selected'] = [
        '#type' => 'checkbox',
        '#default_value' => FALSE,
        '#attributes' => ['class' => ['hidden']],
        '#states' => ['checked' => $states],
      ];
      $form['project_settings']['taxonomy_id'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_vocabulary',
        '#title' => t('Select or enter a new vocabulary'),
        '#default_value' => (!empty($config['project']['taxonomy_id']) ? Vocabulary::load($config['project']['taxonomy_id']) : ''),
        '#states' => [
          'required' => ['#edit-project-settings-no-language-selected' => array('checked' => FALSE)],
          'disabled' => $states,
        ],
      ];

      // Ask if the vocabulary should be removed also if no language is
      // selected.
      if (!empty($config['project']['taxonomy_id'])) {
        $form['project_settings']['remove_taxonomy'] = array(
          '#type' => 'checkbox',
          '#title' => t('Remove the appropriate vocabulary. All terms and relations to this vocabulary will be removed also.'),
          '#states' => array(
            'visible' => $states,
          ),
        );
      }

      // TODO: Get the list oft the corpora via the API.
      $form['project_settings']['corpus_id'] = array(
        '#type' => 'textfield',
        '#title' => t('Enter a corpus ID if one exists on the PoolParty server'),
        '#default_value' => (!empty($config['project']['corpus_id']) ? $config['project']['corpus_id'] : ''),
        '#field_prefix' => t('corpus:'),
        '#attributes' => array('style' => array('width:auto')),
      );
    }
    else {
      $form['project_settings']['errors'] = array(
        '#type' => 'item',
        '#markup' => '<div class="messages warning">' . t('Either no connection can be established or there are no projects available for the given credentials.') . '</div>',
      );
    }

    // Tab: Global limit settings.
    $form['global_limit_settings'] = [
      '#type' => 'details',
      '#title' => t('Global limit settings'),
      '#group' => 'settings',
    ];
    static::addLimitsForm($form['global_limit_settings'], $config['limits']);

    $fields = $powertagging_config->getFields();
    if (!empty($fields)) {
      $form['global_limit_settings']['overwriting'] = array(
        '#type' => 'fieldset',
        '#title' => t('List of all content types with "PowerTagging Tags" fields'),
        '#description' => t('Select those content types which ones you want to overwrite the limits with the global limits defined above.'),
      );
      if (count($fields) > 1) {
        $form['global_limit_settings']['overwriting']['select_all_content_types'] = array(
          '#type' => 'checkbox',
          '#title' => t('Select all'),
          '#attributes' => array(
            'onclick' => 'jQuery("#edit-overwrite-content-types").find("input").prop("checked", jQuery(this).prop("checked"));',
          ),
        );
      }
      $form['global_limit_settings']['overwriting']['overwrite_content_types'] = array(
        '#type' => 'checkboxes',
        '#options' => $powertagging_config->renderFields('option_list', $fields),
        '#validated' => TRUE,
      );
    }

    // Tab: Batch Jobs.
    $form['batch_jobs'] = array(
      '#type' => 'details',
      '#title' => t('Batch jobs'),
      '#group' => 'settings',
    );

    $operations = [
      [
        'operation' => Link::createFromRoute(t('Tag content'), 'entity.powertagging.tag_content', ['powertagging_config' => $powertagging_config->id()]),
        'description' => t('Select the content types for which the tags should be calculated and linked automatically.'),
      ],[
        'operation' => Link::createFromRoute(t('Update vocabulary'), 'entity.powertagging.update_vocabulary', ['powertagging_config' => $powertagging_config->id()]),
        'description' => t('Update all linked tags from the vocabulary with the PoolParty project.'),
      ]
    ];
    $form['batch_jobs']['operations'] = [
      '#type' => 'table',
      '#header' => [
        'operation' => t('Operation'),
        'description' => t('Description'),
      ],
      '#rows' => $operations,
      '#tableselect' => FALSE,
    ];

    // Attach the libraries for the slider element.
    $form['#attached'] = [
      'library' => [
        'powertagging/widget',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = $this->entity;

    $values = $form_state->getValues();
    $config = [
      'project' => [
        'languages' => $values['project_settings']['languages'],
        'taxonomy_id' => $values['project_settings']['taxonomy_id'],
        'corpus_id' => $values['project_settings']['corpus_id'],
      ],
      'limits' => [
        'concepts_per_extraction' => $values['concepts_per_extraction'],
        'concepts_threshold' => $values['concepts_threshold'],
        'freeterms_per_extraction' => $values['freeterms_per_extraction'],
        'freeterms_threshold' => $values['freeterms_threshold'],
      ]
    ];
    $powertagging_config->set('config', $config);

    // Set the vocabulary.
    if (!empty($values['project_settings']['taxonomy_id'])) {
      $vocabulary = Vocabulary::load($values['project_settings']['taxonomy_id']);
      // Delete vocabulary if it is desired.
      if (isset($values['project_settings']['remove_taxonomy']) && $values['project_settings']['remove_taxonomy']) {
        $vocabulary->delete();
      }
      else {
        $this->addVocabularyFields($vocabulary);
      }
    }

    // Save PowerTagging configuration.
    $status = $powertagging_config->save();

    // Overwrite limits for all selected content types.
    if (isset($values['overwrite_content_types'])) {
      foreach ($values['overwrite_content_types'] as $content_type) {
        if ($content_type) {
          list($entity_type_id, $bundle, $field_type) = explode('|', $content_type);
          $field = [
            'entity_type_id' => $entity_type_id,
            'bundle' => $bundle,
            'field_type' => $field_type,
          ];
          $limits = [
            'concepts' => [
              'concepts_per_extraction' => $config['limits']['concepts_per_extraction'],
              'concepts_threshold' => $config['limits']['concepts_threshold'],
            ],
            'freeterms' => [
              'freeterms_per_extraction' => $config['limits']['freeterms_per_extraction'],
              'freeterms_threshold' => $config['limits']['freeterms_threshold'],
            ],
          ];
          $powertagging_config->updateField($field, 'limits', $limits);
        }
      }
    }

    switch ($status) {
      case SAVED_NEW:
        drupal_set_message($this->t('PowerTagging configuration %title has been created.', [
          '%title' => $powertagging_config->getTitle(),
        ]));
        break;

      default:
        drupal_set_message($this->t('PowerTagging configuration %title has been updated.', [
          '%title' => $powertagging_config->getTitle(),
        ]));
    }
    $form_state->setRedirectUrl(URL::fromRoute('entity.powertagging.collection'));
  }

  /**
   * Adds information about the connection.
   *
   * @return string
   *   Connection information.
   */
  protected function getConnectionInfo() {
    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = $this->entity;
    /** @var SemanticConnectorPPServerConnection $connection */
    $connection = $powertagging_config->getConnection();

    // Get the project title of the currently configured project.
    $project_title = '<invalid project selected>';
    $pp_server_projects = $connection->getApi('PPX')->getProjects();
    foreach ($pp_server_projects as $pp_server_project) {
      if ($pp_server_project['uuid'] == $powertagging_config->getProjectId()) {
        $project_title = $pp_server_project['label'];
      }
    }

    // Add information about the connection.
    $connection_markup = '';
    // Check the PoolParty server version if required.
    if (\Drupal::config('semantic_connector.settings')->get('version_checking')) {
      $api_version_info = $connection->getVersionInfo('PPX');
      if (version_compare($api_version_info['installed_version'], $api_version_info['latest_version'], '<')) {
        $connection_markup .= '<div class="messages warning"><div class="message">';
        $connection_markup .= t('The connected PoolParty server is not up to date. You are currently running version %installed_version, upgrade to version %latest_version to enjoy the new features.', [
          '%installed_version' => $api_version_info['installed_version'],
          '%latest_version' => $api_version_info['latest_version'],
        ]);
        $connection_markup .= '</div></div>';
      }
    }
    $connection_markup .= '<p id="sonr-webmining-connection-info">' . t('Connected PoolParty server') . ': <b>' . $connection->getTitle() . ' (' . $connection->getUrl() . ')</b><br />';
    $connection_markup .= t('Selected project') . ': <b>' . $project_title . '</b><br />';
    $connection_markup .= Link::fromTextAndUrl(t('Change the connected PoolParty server or project'), Url::fromRoute('entity.powertagging.edit_form', ['powertagging' => $powertagging_config->id()]))->toString() . '</p>';

    return $connection_markup;
  }

  /**
   * Adds the form for the global limits.
   *
   * @param array $form
   *   The form where the global limits form will be added.
   * @param array $config
   *   The configuration data of a PowerTagging configuration.
   * @param boolean $tree
   *   The boolean value for the #tree attribute.
   */
  public static function addLimitsForm(array &$form, array $config, $tree = FALSE) {
    $form['concepts'] = array(
      '#type' => 'fieldset',
      '#title' => t('Concept settings'),
      '#description' => t('Concepts are available in the thesaurus.'),
      '#tree' => $tree,
    );

    $form['concepts']['concepts_per_extraction'] = array(
      '#title' => t('Max concepts per extraction'),
      '#type' => 'slider',
      '#default_value' => $config['concepts_per_extraction'],
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#slider_style' => 'concept',
      '#slider_length' => '500px',
      '#description' => t('Maximum number of concepts to be displayed as a tagging result.'),
    );

    $form['concepts']['concepts_threshold'] = array(
      '#title' => t('Threshold level for the concepts'),
      '#type' => 'slider',
      '#default_value' => $config['concepts_threshold'],
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#slider_style' => 'concept',
      '#slider_length' => '500px',
      '#description' => t('Only concepts with a minimum score of the chosen value will be displayed as a tagging result.'),
    );

    $form['freeterms'] = array(
      '#type' => 'fieldset',
      '#title' => t('Free term settings'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#description' => t('Free terms are extracted terms, which are not available in the thesaurus.'),
      '#tree' => $tree,
    );

    $form['freeterms']['freeterms_per_extraction'] = array(
      '#title' => t('Max free terms per extraction'),
      '#type' => 'slider',
      '#default_value' => $config['freeterms_per_extraction'],
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#slider_style' => 'freeterm',
      '#slider_length' => '500px',
      '#description' => t('Maximum number of free terms for tagging.'),
    );

    $form['freeterms']['freeterms_threshold'] = array(
      '#title' => t('Threshold level for the free terms'),
      '#type' => 'slider',
      '#default_value' => $config['freeterms_threshold'],
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#slider_length' => '500px',
      '#slider_style' => 'freeterm',
      '#description' => t('Only free terms with a minimum score of the chosen value will be used for tagging.') . '<br />' . t('WARNING: A threshold below 40 may reduce the quality of free term extractions!'),
    );
  }

  protected function addVocabularyFields(Vocabulary $vocabulary) {
    $fields = [
      'field_uri' => [
        'field_name' => 'field_uri',
        'type' => 'link',
        'label' => t('URI'),
        'description' => t('URI of the concept.'),
        'cardinality' => 1,
        'field_settings' => [],
        'required' => TRUE,
        'instance_settings' => [
          'link_type' => LinkItemInterface::LINK_GENERIC,
          'title' => DRUPAL_DISABLED,
        ],
        'widget' => [
          'type' => 'link_default',
          'weight' => 3,
        ],
      ],
      'field_alt_labels' => [
        'field_name' => 'field_alt_labels',
        'type' => 'text',
        'label' => t('Alternative labels'),
        'description' => t('A comma separated list of synonyms.'),
        'cardinality' => 1,
        'field_settings' => [
          'max_length' => 8192,
        ],
        'required' => FALSE,
        'instance_settings' => [],
        'widget' => [
          'type' => 'text_textfield',
          'weight' => 4,
        ],
      ],
      'field_hidden_labels' => [
        'field_name' => 'field_hidden_labels',
        'type' => 'text',
        'label' => t('Hidden labels'),
        'description' => t('A comma separated list of secondary variants of this term.'),
        'cardinality' => 1,
        'field_settings' => [
          'max_length' => 8192,
        ],
        'required' => FALSE,
        'instance_settings' => [],
        'widget' => [
          'type' => 'text_textfield',
          'weight' => 5,
        ],
      ],
      'field_exact_match' => [
        'field_name' => 'field_exact_match',
        'type' => 'link',
        'label' => t('Exact matches'),
        'description' => t('URIs which show to the same concept at a different data source.'),
        'cardinality' => -1,
        'field_settings' => [],
        'required' => FALSE,
        'instance_settings' => [
          'link_type' => LinkItemInterface::LINK_GENERIC,
          'title' => DRUPAL_DISABLED,
        ],
        'widget' => [
          'type' => 'link_default',
          'weight' => 6,
        ],
      ],
    ];
    foreach ($fields as $field) {
      $this->createVocabularyField($field);
      $this->addFieldtoVocabulary($field, $vocabulary);

      // Set the widget data.
      entity_get_form_display('taxonomy_term', $vocabulary->id(), 'default')
        ->setComponent($field['field_name'], $field['widget'])
        ->save();
    }
  }

  protected function createVocabularyField(array $field) {
    if (is_null(FieldStorageConfig::loadByName('taxonomy_term', $field['field_name']))) {
      $new_field = [
        'field_name' => $field['field_name'],
        'type' => $field['type'],
        'entity_type' => 'taxonomy_term',
        'cardinality' => $field['cardinality'],
        'settings' => $field['field_settings'],
      ];
      FieldStorageConfig::create($new_field)->save();
    }
  }

  protected function addFieldtoVocabulary(array $field, Vocabulary $vocabulary) {
    if (is_null(FieldConfig::loadByName('taxonomy_term', $vocabulary->id(), $field['field_name']))) {
      $instance = [
        'field_name' => $field['field_name'],
        'entity_type' => 'taxonomy_term',
        'bundle' => $vocabulary->id(),
        'label' => $field['label'],
        'description' => $field['description'],
        'required' => $field['required'],
        'settings' => $field['instance_settings'],
      ];
      FieldConfig::create($instance)->save();
    }
  }

}
