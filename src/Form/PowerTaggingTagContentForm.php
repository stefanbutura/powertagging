<?php
/**
 * @file
 * Contains \Drupal\powertagging\Form\PowerTaggingTagContentForm.
 */

namespace Drupal\powertagging\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem;
use Drupal\powertagging\PowerTagging;

class PowerTaggingTagContentForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'powertagging_tag_content_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param PowerTaggingConfig $powertagging_config
   *   An associative array containing the structure of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, PowerTaggingConfig $powertagging_config = NULL) {
    $fields = $powertagging_config->getFields();
    if (!empty($fields)) {
      $form['powertagging_config'] = [
        '#type' => 'value',
        '#value' => $powertagging_config,
      ];

      $form['content_types'] = [
        '#title' => t('Entity types to be included in the batch process'),
        '#type' => 'checkboxes',
        '#options' => $powertagging_config->renderFields('option_list', $fields),
        '#required' => TRUE,
      ];

      $form['skip_tagged_content'] = [
        '#title' => t('Skip already tagged content'),
        '#type' => 'radios',
        '#options' => [
          '1' => t('Yes'),
          '0' => t('No'),
        ],
        '#default_value' => TRUE,
      ];

      $form['entities_per_request'] = [
        '#type' => 'number',
        '#title' => t('Entities per request'),
        '#description' => t('The number of entities, that get processed during one HTTP request. (Allowed value range: 1 - 100)') . '<br />' . t('The higher this number is, the less HTTP requests have to be sent to the server until the batch finished tagging ALL your entities, what results in a shorter duration of the bulk tagging process.') . '<br />' . t('Numbers too high can result in a timeout, which will break the whole bulk tagging process.') . '<br />' . t('If entities are configured to get tagged with uploaded files, a value of 5 or below is recommended.'),
        '#required' => TRUE,
        '#default_value' => '10',
        '#min' => 1,
        '#max' => 100,
      ];

      $form['submit'] = [
        '#type' => 'submit',
        '#value' => t('Start process'),
        '#attributes' => ['class' => ['button--primary']],
      ];
    }
    else {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . t('No taggable content types found for the PowerTagging configuration "%title".', ['%title' => $powertagging_config->getTitle()]) . '</div>',
      ];
    }

    if (\Drupal::request()->query->has('destination')) {
      $destination = \Drupal::request()->get('destination');
      $url = Url::fromUri(\Drupal::request()->getSchemeAndHttpHost() . $destination);
    }
    else {
      $url = Url::fromRoute('entity.powertagging.edit_config_form', ['powertagging' => $powertagging_config->id()]);
    }
    $form['cancel'] = [
      '#type' => 'link',
      '#title' => t('Cancel'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entities_per_request = $form_state->getValue('entities_per_request');
    if (empty($entities_per_request) || !ctype_digit($entities_per_request) || (int) $entities_per_request == 0 || (int) $entities_per_request > 100) {
      $form_state->setErrorByName('entities_per_request', t('Only values in the range of 1 - 100 are allowed for field "Entities per request"'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = $form_state->getValue('powertagging_config');
    $configuration = $powertagging_config->getConfig();
    $entities_per_request = $form_state->getValue('entities_per_request');
    $content_types = $form_state->getValue('content_types');

    // Get the configured project languages.
    $allowed_langcodes = [];
    foreach ($configuration['project']['languages'] as $drupal_lang => $pp_lang) {
      if (!empty($pp_lang)) {
        $allowed_langcodes[] = $drupal_lang;
      }
    }

    $start_time = time();
    $total = 0;
    $batch = [
      'title' => t('Tagging entities'),
      'operations' => [],
      'init_message' => t('Start with the tagging of the entities.'),
      'progress_message' => t('Process @current out of @total.'),
      'finished' => [$this, 'tagContentBatchFinished'],
    ];

    foreach ($content_types as $content_type) {
      if (empty($content_type)) {
        continue;
      }
      list($entity_type_id, $bundle, $field_type) = explode('|', $content_type);

      // If the entity type is not supported, throw an error and continue.
      if (!in_array($entity_type_id, ['node', 'user', 'taxonomy_term'])) {
        drupal_set_message(t('Entity type "%entitytype" is not supported in bulk tagging.', ['%entitytype' => $entity_type_id]), 'error');
        continue;
      }

      $settings = $powertagging_config->getFieldSettings([
        'entity_type_id' => $entity_type_id,
        'bundle' => $bundle,
        'field_type' => $field_type,
      ]);
      $tag_fields = [];
      foreach ($settings['fields'] as $tag_field_type) {
        if ($tag_field_type && !isset($tag_fields[$tag_field_type])) {
          $info = $this->getInfoForTaggingField([
            'entity_type_id' => $entity_type_id,
            'bundle' => $bundle,
            'field_type' => $tag_field_type,
          ]);
          if (!empty($info)) {
            $tag_fields[$tag_field_type] = $info;
          }
        }
      }

      $tag_settings = [
        'taxonomy_id' => $configuration['project']['taxonomy_id'],
        'concepts_per_extraction' => $settings['limits']['concepts_per_extraction'],
        'concepts_threshold' => $settings['limits']['concepts_threshold'],
        'freeterms_per_extraction' => $settings['limits']['freeterms_per_extraction'],
        'freeterms_threshold' => $settings['limits']['freeterms_threshold'],
        'entity_language' => '',
        'allowed_languages' => $allowed_langcodes,
        'fields' => $tag_fields,
        'skip_tagged_content' => $form_state->getValue('skip_tagged_content'),
        'default_tags_field' => (isset($settings['default_tags_field']) ? $settings['default_tags_field'] : ''),
        'corpus_id' => $configuration['project']['corpus_id'],
      ];

      // Get all entities for the given content type.
      $entity_ids = [];
      switch ($entity_type_id) {
        case 'node':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->condition('type', $bundle)
            ->execute();
          break;

        case 'user':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->execute();
          // Remove the user with the ID = 0.
          array_shift($entity_ids);
          break;

        case 'taxonomy_term':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->execute();
          break;
      }
      $count = count($entity_ids);

      $total += $count;
      for ($i = 0; $i < $count; $i += $entities_per_request) {
        $entities = array_slice($entity_ids, $i, $entities_per_request);
        $batch['operations'][] = [
          [$this, 'tagContentBatchProcess'],
          [
            $entities,
            $entity_type_id,
            $field_type,
            $tag_settings,
            $powertagging_config,
          ],
        ];
      }
    }

    // Add for each operation some batch info data.
    $batch_info = [
      'total' => $total,
      'start_time' => $start_time,
    ];
    foreach ($batch['operations'] as &$operation) {
      $operation[1][] = $batch_info;
    }

    batch_set($batch);
    return TRUE;
  }

  /**
   * Update the powertagging tags of one powertagging field of a single entity.
   *
   * @param array $entity_ids
   *   A single ID or an array of IDs of entities, depending on the entity type
   * @param string $entity_type_id
   *   The entity type ID of the entity (e.g. node, user, ...).
   * @param string $field_type
   *   The field type of the powertagging field.
   * @param array $tag_settings
   *   An array of settings used during the process of extraction.
   * @param PowerTaggingConfig $powertagging_config
   *   A PowerTagging configuration.
   * @param array $batch_info
   *   An associative array of information about the batch process.
   * @param array $context
   *   The Batch context to transmit data between different calls.
   */
  public static function tagContentBatchProcess(array $entity_ids, $entity_type_id, $field_type, array $tag_settings, PowerTaggingConfig $powertagging_config, array $batch_info, &$context) {
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
      $context['results']['tagged'] = 0;
      $context['results']['skipped'] = 0;
    }

    // Load the entities.
    $entities = \Drupal::entityTypeManager()
      ->getStorage($entity_type_id)
      ->loadMultiple($entity_ids);

    $powertagging = new PowerTagging($powertagging_config);
    $powertagging->tagEntities($entities, $field_type, $tag_settings, $context);

    // Show the remaining time as a batch message.
    $time_string = '';
    if ($context['results']['processed'] > 0) {
      $remaining_time = floor((time() - $batch_info['start_time']) / $context['results']['processed'] * ($batch_info['total'] - $context['results']['processed']));
      if ($remaining_time > 0) {
        $time_string = (floor($remaining_time / 86400)) . 'd ' . (floor($remaining_time / 3600) % 24) . 'h ' . (floor($remaining_time / 60) % 60) . 'm ' . ($remaining_time % 60) . 's';
      }
      else {
        $time_string = t('Done.');
      }
    }

    $context['message'] = t('Processed entities: %currententities of %totalentities. (Tagged: %taggedentities, Skipped: %skippedentities)', [
      '%currententities' => $context['results']['processed'],
      '%taggedentities' => $context['results']['tagged'],
      '%skippedentities' => $context['results']['skipped'],
      '%totalentities' => $batch_info['total'],
    ]);
    $context['message'] .= '<br />' . t('Remaining time: %remainingtime.', ['%remainingtime' => $time_string]);
  }

  /**
   * Batch 'finished' callback used by PowerTagging Bulk Tagging.
   */
  public static function tagContentBatchFinished($success, $results, $operations) {
    drupal_set_message(t('Successfully finished bulk tagging of %totalentities entities. (Tagged: %taggedentities, Skipped: %skippedentities)', [
      '%totalentities' => $results['processed'],
      '%taggedentities' => $results['tagged'],
      '%skippedentities' => $results['skipped'],
    ]));
  }

  /**
   * Gets the module and widget for a given field.
   *
   * @param array $field
   *   The field array with entity type ID, bundle and field type.
   *
   * @return array
   *   Module and widget info for a field.
   */
  protected function getInfoForTaggingField(array $field) {
    if ($field['entity_type_id'] == 'node' && $field['field_type'] == 'title') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($field['entity_type_id'] == 'taxonomy_term' && $field['field_type'] == 'name') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($field['entity_type_id'] == 'taxonomy_term' && $field['field_type'] == 'description') {
      return [
        'module' => 'text',
        'widget' => 'text_textarea',
      ];
    }

    /** @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager */
    $entityFieldManager = \Drupal::service('entity_field.manager');
    /** @var FieldConfig $field_definition */
    $field_definition = $entityFieldManager->getFieldDefinitions($field['entity_type_id'], $field['bundle'])[$field['field_type']];

    if (!$field_definition instanceof FieldConfig) {
      return [];
    }

    $field_storage = $field_definition->getFieldStorageDefinition();
    $supported_field_types = PowerTaggingTagsItem::getSupportedFieldTypes();

    return [
      'module' => $field_storage->getTypeProvider(),
      'widget' => $supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()],
    ];
  }
}